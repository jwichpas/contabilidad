<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyEconomicActivity;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\User;
use App\Services\Accounting\InitializeCompanyChartOfAccountsService;
use App\Services\Company\CompanyOperationalDefaultsService;
use App\Support\Company\CompanyEconomicProfile;
use App\Support\Company\CompanyOnboardingGuard;
use App\Support\Tenant\TenantContext;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alta segura desde el wizard Vue: empresa, membresía ADMIN, monedas, actividad
 * económica, parámetros iniciales y copia independiente de PCGE en una transacción.
 *
 * Sin DDL: tablas/columnas se crean exclusivamente por migraciones Laravel.
 */
final readonly class CreateCompanyFromWizardController
{
    public function __construct(
        private CompanyOnboardingGuard $guard,
        private TenantContext $tenantContext,
        private InitializeCompanyChartOfAccountsService $chartInitializer,
        private CompanyOperationalDefaultsService $operationalDefaults,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $readiness = $this->guard->status($user);

        if (! $readiness['can_create']) {
            return response()->json([
                'message' => $readiness['reason'],
            ], Response::HTTP_CONFLICT);
        }

        $validated = Validator::make($request->all(), [
            'tax_id' => ['required', 'string', 'regex:/^\d{11}$/'],
            'business_name' => ['required', 'string', 'min:3', 'max:200'],
            'legal_address' => ['required', 'string', 'min:5', 'max:300'],
            'legal_form' => ['required', 'string', Rule::in(CompanyEconomicProfile::LEGAL_FORMS)],
            'tax_regime' => ['required', 'string', Rule::in(['NRUS', 'RER', 'MYPE_TRIBUTARIO', 'GENERAL'])],
            'economic_activities' => ['required', 'array', 'min:1', 'max:10'],
            'economic_activities.*' => ['required', 'array:sector_code,ciiu_code,description,is_primary'],
            'economic_activities.*.sector_code' => ['required', Rule::in(CompanyEconomicProfile::SECTORS)],
            'economic_activities.*.ciiu_code' => ['required', 'string', 'regex:/^\d{4}$/'],
            'economic_activities.*.description' => ['required', 'string', 'max:200'],
            'economic_activities.*.is_primary' => ['required', 'boolean'],
            'fiscal_year' => ['required', 'integer', 'between:2000,2100'],
            'income_reference_year' => ['required', 'integer', 'between:2000,2100', 'lte:fiscal_year'],
            'annual_income' => ['required', 'string', 'regex:/^\d{1,14}(?:\.\d{1,2})?$/'],
            'base_currency_code' => ['required', 'string', 'max:3'],
            'secondary_currency_codes' => ['required', 'array', 'max:10'],
            'secondary_currency_codes.*' => ['required', 'string', 'max:3', 'distinct'],
            'pcge_version' => ['required', Rule::in(['PCGE_2019', 'PCGE_2026'])],
            'confirm_early_adoption' => ['sometimes', 'boolean'],
            'confirm_legal_data' => ['required', 'accepted'],
        ])->validate();

        CompanyEconomicProfile::assertValidActivities($validated['economic_activities']);
        CompanyEconomicProfile::assertTaxRegimeEligibility(
            (string) $validated['tax_regime'], (string) $validated['legal_form']
        );
        $taxId = (string) $validated['tax_id'];
        if (! self::isValidRuc($taxId)) {
            throw ValidationException::withMessages([
                'tax_id' => ['El dígito verificador del RUC no es válido.'],
            ]);
        }

        if ($validated['pcge_version'] === 'PCGE_2026'
            && (int) $validated['fiscal_year'] < 2028
            && ! ($validated['confirm_early_adoption'] ?? false)) {
            throw ValidationException::withMessages([
                'pcge_version' => ['La adopción anticipada del PCGE 2026 requiere confirmación expresa.'],
            ]);
        }

        $currencyCodes = array_merge(
            [(string) $validated['base_currency_code']],
            $validated['secondary_currency_codes'],
        );
        if (count($currencyCodes) !== count(array_unique($currencyCodes))) {
            throw ValidationException::withMessages([
                'secondary_currency_codes' => ['La moneda funcional no puede figurar como secundaria.'],
            ]);
        }
        $currencyMap = Currency::query()
            ->whereRaw('currencies.is_active IS TRUE')
            ->whereIn('code', $currencyCodes)
            ->get()->keyBy('code');

        if ($currencyMap->count() !== count($currencyCodes)) {
            throw ValidationException::withMessages([
                'base_currency_code' => ['Una de las monedas seleccionadas no está activa o no existe.'],
            ]);
        }

        if (Company::query()->where('tax_id', $taxId)->exists()) {
            throw ValidationException::withMessages([
                'tax_id' => ['Ya existe una empresa con ese RUC.'],
            ]);
        }

        try {
            $outcome = DB::transaction(function () use ($validated, $currencyMap, $currencyCodes, $taxId, $user, $request): array {
                $company = Company::query()->create([
                    'tax_id' => $taxId,
                    'business_name' => trim($validated['business_name']),
                    'legal_address' => trim($validated['legal_address']),
                    'is_active' => DB::raw('TRUE'),
                ]);
                $companyId = (int) $company->id;

                // Contexto local a una transacción (RLS); nunca usar BYPASSRLS.
                // Si falla algún paso o el PCGE, la transacción EXTERNA revierte
                // empresa, membresía, monedas, actividades y todas sus cuentas.
                $plan = $this->tenantContext->run($companyId, function () use (
                    $company, $companyId, $validated, $currencyMap, $currencyCodes, $taxId, $user, $request
                ): array {
                    $now = now();
                    DB::table('company_user')->insert([
                        'company_id' => $companyId,
                        'user_id' => $user->id,
                        'role' => 'ADMIN',
                        'is_active' => DB::raw('TRUE'),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    CompanySetting::query()->create([
                        'base_currency_id' => $currencyMap[$validated['base_currency_code']]->id,
                        'secondary_currency_id' => isset($validated['secondary_currency_codes'][0])
                            ? $currencyMap[$validated['secondary_currency_codes'][0]]->id : null,
                        'tax_regime' => $validated['tax_regime'],
                        'legal_form' => $validated['legal_form'],
                        'initial_fiscal_year' => $validated['fiscal_year'],
                        'income_reference_year' => $validated['income_reference_year'],
                        'annual_income' => $validated['annual_income'],
                        'initial_pcge_version' => $validated['pcge_version'],
                        'default_vat_rate' => '18.00',
                        // No atribuir obligatoriedad SIRE o PLE sin evaluar reglas SUNAT.
                        'is_sire_obliged' => DB::raw('FALSE'),
                        'is_ple_obliged' => DB::raw('FALSE'),
                    ]);

                    foreach ($currencyCodes as $currencyCode) {
                        CompanyCurrency::query()->create([
                            'company_id' => $companyId,
                            'currency_id' => $currencyMap[$currencyCode]->id,
                            'is_base' => DB::raw($currencyCode === $validated['base_currency_code'] ? 'TRUE' : 'FALSE'),
                            'is_active' => DB::raw('TRUE'),
                        ]);
                    }

                    foreach ($validated['economic_activities'] as $activity) {
                        CompanyEconomicActivity::query()->create([
                            'sector_code' => $activity['sector_code'],
                            'ciiu_code' => $activity['ciiu_code'],
                            'description' => trim($activity['description']),
                            'is_primary' => DB::raw($activity['is_primary'] ? 'TRUE' : 'FALSE'),
                            'created_by' => $user->id,
                            'updated_by' => $user->id,
                        ]);
                    }

                    $plan = $this->chartInitializer->initialize(
                        $companyId, (string) $validated['pcge_version'], false
                    );

                    // En la misma transaccion: 6 áreas organizacionales y 9
                    // áreas NIC 7 aisladas por tenant. No importar personas,
                    // bancos ni centros de costo con cuentas ficticias 94/95.
                    $this->operationalDefaults->installForCompany($companyId);

                    // La auditoria de alta es obligatoria y atomica.
                    // AuditLoggerService captura excepciones para eventos
                    // accesorios; NO usarlo para crear empresas.
                    AuditLog::query()->create([
                        'company_id' => $companyId,
                        'user_id' => (int) $user->id,
                        'event_type' => 'COMPANY_ONBOARDED',
                        'auditable_type' => Company::class,
                        'auditable_id' => (string) $companyId,
                        'old_values' => null,
                        'new_values' => [
                            'tax_id' => $taxId,
                            'pcge_version' => $validated['pcge_version'],
                            'sector_count' => count($validated['economic_activities']),
                        ],
                        'ip_address' => substr((string) ($request->ip() ?? '127.0.0.1'), 0, 45),
                        'user_agent' => substr((string) ($request->userAgent() ?? 'system'), 0, 500),
                        'severity' => 'INFO',
                    ]);
                    return $plan;
                });

                return [
                    'id' => $companyId,
                    'tax_id' => $taxId,
                    'business_name' => (string) $company->business_name,
                    'legal_address' => (string) $company->legal_address,
                    'uuid' => (string) ($company->fresh()?->uuid ?? ''),
                    'plan_version' => $validated['pcge_version'],
                    'plan_accounts_created' => (int) $plan['total_accounts'],
                ];
            });
        } catch (QueryException $exception) {
            // 23505: UNIQUE race (RUC) entre dos solicitudes concurrentes.
            if ($exception->getCode() === '23505') {
                throw ValidationException::withMessages([
                    'tax_id' => ['Ya existe una empresa con ese RUC.'],
                ]);
            }
            throw $exception;
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'pcge_version' => [$exception->getMessage()],
            ]);
        }

        return response()->json(['data' => $outcome], Response::HTTP_CREATED);
    }

    private static function isValidRuc(string $ruc): bool
    {
        if (! preg_match('/^(10|15|16|17|20)\d{9}$/', $ruc)) return false;
        $factors = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        foreach ($factors as $index => $factor) {
            $sum += ((int) $ruc[$index]) * $factor;
        }
        $digit = 11 - ($sum % 11);
        $expected = $digit === 10 ? 0 : ($digit === 11 ? 1 : $digit);
        return ((int) $ruc[10]) === $expected;
    }
}
