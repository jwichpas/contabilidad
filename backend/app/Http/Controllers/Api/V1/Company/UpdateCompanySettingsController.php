<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyEconomicActivity;
use App\Support\Company\CompanyEconomicProfile;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Services\Security\AuditLoggerService;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

/**
 * Endpoint para actualizar los datos de la empresa, su configuración de divisas (moneda base y secundarias)
 * y los parámetros contable-tributarios.
 */
final readonly class UpdateCompanySettingsController
{
    public function __construct(
        private TenantContext $tenantContext,
        private AuditLoggerService $auditLogger,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $companyId = $this->tenantContext->requireCompanyId(CompanySetting::class);

        $economicProfileReady = CompanyEconomicProfile::isReady();

        $validated = $request->validate([
            // Datos generales de la empresa
            'business_name' => ['sometimes', 'required', 'string', 'max:200'],
            'legal_address' => ['sometimes', 'required', 'string', 'max:300'],
            'tax_id' => ['sometimes', 'required', 'string', 'min:8', 'max:20'],

            // Configuración de divisas
            'base_currency_id' => ['sometimes', 'required', 'integer', 'exists:currencies,id'],
            'secondary_currency_ids' => ['sometimes', 'required', 'array', 'min:1'],
            'secondary_currency_ids.*' => ['integer', 'exists:currencies,id'],

            // Parámetros tributarios SUNAT
            'tax_regime' => ['sometimes', 'required', 'string', 'in:GENERAL,MYPE_TRIBUTARIO,RER,ESPECIAL,NRUS'],
            'legal_form' => ['sometimes', 'nullable', 'string', Rule::in(CompanyEconomicProfile::LEGAL_FORMS)],
            'economic_activities' => ['sometimes', 'required', 'array', 'min:1', 'max:10'],
            'economic_activities.*' => ['required', 'array:sector_code,ciiu_code,description,is_primary'],
            'economic_activities.*.sector_code' => ['required', 'string', Rule::in(CompanyEconomicProfile::SECTORS)],
            'economic_activities.*.ciiu_code' => ['required', 'string', 'regex:/^[0-9]{4}$/'],
            'economic_activities.*.description' => ['required', 'string', 'max:200'],
            'economic_activities.*.is_primary' => ['required', 'boolean'],
            'default_vat_rate' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'is_retention_agent' => ['sometimes', 'boolean'],
            'is_perception_agent' => ['sometimes', 'boolean'],
            'is_good_taxpayer' => ['sometimes', 'boolean'],
            'is_sire_obliged' => ['sometimes', 'boolean'],
            'is_ple_obliged' => ['sometimes', 'boolean'],

            // Representante Legal y Contador
            'legal_representative_name' => ['nullable', 'string', 'max:150'],
            'legal_rep_identity_doc_code' => ['nullable', 'string', 'exists:sunat_table_02_identity_document_types,code'],
            'legal_representative_id_number' => ['nullable', 'string', 'max:15'],
            'chief_accountant_name' => ['nullable', 'string', 'max:150'],
            'chief_accountant_cpc_number' => ['nullable', 'string', 'max:30'],

            // Cuentas contables automáticas por defecto
            'sales_vat_account_id' => ['nullable', 'integer', 'exists:accounting_accounts,id'],
            'purchase_vat_account_id' => ['nullable', 'integer', 'exists:accounting_accounts,id'],
            'fx_gain_account_id' => ['nullable', 'integer', 'exists:accounting_accounts,id'],
            'fx_loss_account_id' => ['nullable', 'integer', 'exists:accounting_accounts,id'],
            'cost_absorption_account_id' => ['nullable', 'integer', 'exists:accounting_accounts,id'],
            'retained_earnings_account_id' => ['nullable', 'integer', 'exists:accounting_accounts,id'],
        ]);

        // Los campos nuevos no deben disparar errores SQL antes de ejecutar
        // la migración Laravel pendiente sobre la única base de desarrollo.
        $profileRequested = array_key_exists('legal_form', $validated)
            || array_key_exists('economic_activities', $validated);

        if ($profileRequested && ! $economicProfileReady) {
            throw ValidationException::withMessages([
                'economic_activities' => [
                    'El perfil económico aún no está habilitado. Ejecute primero las migraciones Laravel pendientes.',
                ],
            ]);
        }

        if (array_key_exists('economic_activities', $validated)) {
            CompanyEconomicProfile::assertValidActivities($validated['economic_activities']);
        }

        // Validación de no duplicidad entre moneda base y secundarias
        if (isset($validated['base_currency_id'], $validated['secondary_currency_ids'])) {
            $baseId = (int) $validated['base_currency_id'];
            $secIds = array_map('intval', (array) $validated['secondary_currency_ids']);

            if (in_array($baseId, $secIds, true)) {
                throw ValidationException::withMessages([
                    'secondary_currency_ids' => ['La moneda base principal no puede estar incluida dentro de las monedas secundarias.'],
                ]);
            }
        }

        /** @var Company $company */
        $company = Company::query()->findOrFail($companyId);

        /** @var CompanySetting $settings */
        $settings = CompanySetting::query()->firstOrCreate(
            ['company_id' => $companyId],
            [
                'base_currency_id' => $validated['base_currency_id'] ?? Currency::query()->where('code', 'PEN')->value('id') ?? 1,
                'tax_regime' => 'GENERAL',
                'default_vat_rate' => '18.00',
            ]
        );

        $oldCompanyValues = $company->only(['business_name', 'legal_address', 'tax_id']);
        $oldSettingValues = $settings->toArray();
        if ($economicProfileReady && array_key_exists('economic_activities', $validated)) {
            $oldSettingValues['economic_activities'] = CompanyEconomicActivity::query()
                ->where('company_id', $companyId)->get()->toArray();
        }

        // NRUS corresponde solamente a una persona natural elegible.
        $effectiveRegime = $validated['tax_regime'] ?? $settings->tax_regime;
        $effectiveLegalForm = $validated['legal_form'] ?? $settings->legal_form;
        CompanyEconomicProfile::assertTaxRegimeEligibility($effectiveRegime, $effectiveLegalForm);

        DB::transaction(function () use ($company, $settings, $validated, $companyId, $request): void {
            // 1. Actualizar datos de Company
            $companyUpdates = array_intersect_key($validated, array_flip(['business_name', 'legal_address', 'tax_id']));
            if (! empty($companyUpdates)) {
                $company->update($companyUpdates);
            }

            // 2. Actualizar configuración de monedas en company_currencies
            if (isset($validated['base_currency_id'], $validated['secondary_currency_ids'])) {
                $baseCurrencyId = (int) $validated['base_currency_id'];
                $secondaryIds = array_values(array_unique(array_map('intval', (array) $validated['secondary_currency_ids'])));
                $allSelectedIds = array_merge([$baseCurrencyId], $secondaryIds);

                // Eliminar o desactivar monedas que ya no pertenecen a la empresa
                CompanyCurrency::query()
                    ->where('company_id', $companyId)
                    ->whereNotIn('currency_id', $allSelectedIds)
                    ->delete();

                // Registrar o actualizar moneda base
                CompanyCurrency::query()->updateOrCreate(
                    ['company_id' => $companyId, 'currency_id' => $baseCurrencyId],
                    ['is_base' => true, 'is_active' => true]
                );

                // Registrar o actualizar monedas secundarias
                foreach ($secondaryIds as $secId) {
                    CompanyCurrency::query()->updateOrCreate(
                        ['company_id' => $companyId, 'currency_id' => $secId],
                        ['is_base' => false, 'is_active' => true]
                    );
                }

                $validated['base_currency_id'] = $baseCurrencyId;
                $validated['secondary_currency_id'] = $secondaryIds[0] ?? null;
            }

            // 3. Actualizar CompanySetting
            $settingKeys = [
                'base_currency_id',
                'secondary_currency_id',
                'tax_regime',
                'legal_form',
                'default_vat_rate',
                'is_retention_agent',
                'is_perception_agent',
                'is_good_taxpayer',
                'is_sire_obliged',
                'is_ple_obliged',
                'legal_representative_name',
                'legal_rep_identity_doc_code',
                'legal_representative_id_number',
                'chief_accountant_name',
                'chief_accountant_cpc_number',
                'sales_vat_account_id',
                'purchase_vat_account_id',
                'fx_gain_account_id',
                'fx_loss_account_id',
                'cost_absorption_account_id',
                'retained_earnings_account_id',
            ];
            $settingUpdates = array_intersect_key($validated, array_flip($settingKeys));
            if (! empty($settingUpdates)) {
                $settings->update($settingUpdates);
            }

            // Una transacción engloba TODOS los cambios de clasificación;
            // nunca quedan dos actividades principales, incluso si un paso falla.
            if (array_key_exists('economic_activities', $validated)) {
                CompanyEconomicActivity::query()
                    ->where('company_id', $companyId)
                    ->update(['is_primary' => false]);

                $validCodes = [];
                foreach ($validated['economic_activities'] as $activity) {
                    $validCodes[] = $activity['ciiu_code'];
                    $record = CompanyEconomicActivity::query()->firstOrNew([
                        'company_id' => $companyId,
                        'ciiu_code' => $activity['ciiu_code'],
                    ]);
                    if (! $record->exists) {
                        $record->created_by = $request->user()?->id;
                    }
                    $record->fill([
                        'sector_code' => $activity['sector_code'],
                        'description' => trim($activity['description']),
                        'is_primary' => (bool) $activity['is_primary'],
                        'updated_by' => $request->user()?->id,
                    ]);
                    $record->save();
                }

                CompanyEconomicActivity::query()
                    ->where('company_id', $companyId)
                    ->whereNotIn('ciiu_code', $validCodes)
                    ->delete();
            }
        });

        $newSettingValues = $settings->fresh()->toArray();
        if ($economicProfileReady && array_key_exists('economic_activities', $validated)) {
            $newSettingValues['economic_activities'] = CompanyEconomicActivity::query()
                ->where('company_id', $companyId)->get()->toArray();
        }

        // Auditoría ISO/IEC 27001
        $this->auditLogger->log(
            eventType: 'COMPANY_SETTINGS_UPDATED',
            companyId: $companyId,
            userId: $request->user()?->id,
            auditableType: CompanySetting::class,
            auditableId: (string) $settings->id,
            oldValues: array_merge($oldCompanyValues, $oldSettingValues),
            newValues: array_merge($company->only(['business_name', 'legal_address', 'tax_id']), $newSettingValues),
            request: $request,
            severity: 'INFO',
        );

        $companyCurrencies = CompanyCurrency::query()
            ->where('company_id', $companyId)
            ->with('currency')
            ->where('is_active', true)
            ->orderByDesc('is_base')
            ->orderBy('id')
            ->get();

        return response()->json([
            'message' => 'Configuración de empresa y régimen de divisas actualizados exitosamente.',
            'data' => [
                'company' => $company->fresh(),
                'settings' => $settings->fresh([
                    'baseCurrency',
                    'secondaryCurrency',
                    'salesVatAccount',
                    'purchaseVatAccount',
                    'fxGainAccount',
                    'fxLossAccount',
                    'costAbsorptionAccount',
                    'retainedEarningsAccount',
                ]),
                'company_currencies' => $companyCurrencies,
                'economic_profile_ready' => $economicProfileReady,
                'economic_activities' => $economicProfileReady
                    ? CompanyEconomicActivity::query()->where('company_id', $companyId)
                        ->orderByDesc('is_primary')->orderBy('id')->get()
                    : [],
            ],
        ]);
    }
}
