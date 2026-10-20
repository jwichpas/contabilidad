<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint para obtener los datos de la empresa, su régimen de divisas (moneda base y secundarias)
 * y los parámetros contable-tributarios bajo controles ISO/IEC 27001 y RLS.
 */
final readonly class GetCompanySettingsController
{
    public function __construct(
        private TenantContext $tenantContext,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $companyId = $this->tenantContext->requireCompanyId(CompanySetting::class);

        /** @var Company|null $company */
        $company = Company::query()->find($companyId);

        /** @var CompanySetting|null $settings */
        $settings = CompanySetting::query()
            ->where('company_id', $companyId)
            ->with([
                'baseCurrency',
                'secondaryCurrency',
                'salesVatAccount',
                'purchaseVatAccount',
                'fxGainAccount',
                'fxLossAccount',
                'costAbsorptionAccount',
                'retainedEarningsAccount',
            ])
            ->first();

        // Inicializar parámetros por defecto si no existen
        if ($settings === null) {
            $pen = Currency::query()->where('code', 'PEN')->first() ?? Currency::query()->first();
            $usd = Currency::query()->where('code', 'USD')->first();

            $settings = CompanySetting::query()->create([
                'company_id' => $companyId,
                'base_currency_id' => $pen?->id,
                'secondary_currency_id' => $usd?->id,
                'tax_regime' => 'GENERAL',
                'default_vat_rate' => '18.00',
                'is_retention_agent' => false,
                'is_perception_agent' => false,
                'is_good_taxpayer' => false,
                'is_sire_obliged' => true,
                'is_ple_obliged' => false,
            ]);

            $settings->load(['baseCurrency', 'secondaryCurrency']);
        }

        // Obtener monedas activas de la empresa
        $companyCurrencies = CompanyCurrency::query()
            ->where('company_id', $companyId)
            ->with('currency')
            ->where('is_active', true)
            ->orderByDesc('is_base')
            ->orderBy('id')
            ->get();

        // Si la empresa aún no tiene registros en company_currencies, poblarlos desde settings
        if ($companyCurrencies->isEmpty()) {
            if ($settings->base_currency_id) {
                CompanyCurrency::query()->firstOrCreate([
                    'company_id' => $companyId,
                    'currency_id' => $settings->base_currency_id,
                ], [
                    'is_base' => true,
                    'is_active' => true,
                ]);
            }

            if ($settings->secondary_currency_id && $settings->secondary_currency_id !== $settings->base_currency_id) {
                CompanyCurrency::query()->firstOrCreate([
                    'company_id' => $companyId,
                    'currency_id' => $settings->secondary_currency_id,
                ], [
                    'is_base' => false,
                    'is_active' => true,
                ]);
            }

            $companyCurrencies = CompanyCurrency::query()
                ->where('company_id', $companyId)
                ->with('currency')
                ->where('is_active', true)
                ->orderByDesc('is_base')
                ->orderBy('id')
                ->get();
        }

        // Catálogo de todas las monedas del sistema
        $allCurrencies = Currency::query()->where('is_active', true)->orderBy('id')->get();

        $settingsArray = $settings ? $settings->toArray() : [];

        return response()->json([
            'data' => array_merge($settingsArray, [
                'company' => $company,
                'settings' => $settings,
                'company_currencies' => $companyCurrencies,
                'all_currencies' => $allCurrencies,
            ]),
        ]);
    }
}
