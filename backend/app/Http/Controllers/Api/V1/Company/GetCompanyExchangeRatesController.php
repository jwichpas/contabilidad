<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Models\CompanyCurrency;
use App\Models\ExchangeRate;
use App\Support\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint para consultar los tipos de cambio diarios de las monedas secundarias configuradas
 * en la empresa activa para una fecha contable determinada.
 */
final readonly class GetCompanyExchangeRatesController
{
    public function __construct(
        private TenantContext $tenantContext,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $companyId = $this->tenantContext->requireCompanyId(ExchangeRate::class);

        $dateParam = $request->query('date');
        $rateDate = is_string($dateParam) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)
            ? CarbonImmutable::parse($dateParam)
            : CarbonImmutable::today();

        // Obtener monedas de la empresa
        $companyCurrencies = CompanyCurrency::query()
            ->where('company_id', $companyId)
            ->with('currency')
            ->whereRaw('company_currencies.is_active IS TRUE')
            ->orderByDesc('is_base')
            ->orderBy('id')
            ->get();

        $baseCompanyCurrency = $companyCurrencies->firstWhere('is_base', true);
        $baseCode = $baseCompanyCurrency?->currency?->code ?? 'PEN';

        $secondaryCompanyCurrencies = $companyCurrencies->where('is_base', false);

        // Optimización: consultar en una sola sentencia las tasas históricas y exactas en lugar de N consultas en bucle
        $allRates = ExchangeRate::query()
            ->where('company_id', $companyId)
            ->where('rate_date', '<=', $rateDate->toDateString())
            ->orderByDesc('rate_date')
            ->limit(100)
            ->get();

        $ratesList = [];

        foreach ($secondaryCompanyCurrencies as $sec) {
            $currencyCode = $sec->currency->code;

            // 1. Buscar en memoria tipo de cambio exacto para la fecha
            /** @var ExchangeRate|null $exactRate */
            $exactRate = $allRates
                ->where('source_currency', $currencyCode)
                ->first(fn (ExchangeRate $r) => $r->rate_date->toDateString() === $rateDate->toDateString());

            if ($exactRate !== null) {
                $ratesList[] = [
                    'currency_id' => $sec->currency->id,
                    'code' => $currencyCode,
                    'name' => $sec->currency->name,
                    'symbol' => $sec->currency->symbol,
                    'buy_rate' => (string) $exactRate->buy_rate,
                    'sell_rate' => (string) $exactRate->sell_rate,
                    'is_recorded_for_date' => true,
                    'source' => 'DATABASE_EXACT',
                ];
                continue;
            }

            // 2. Si no existe para la fecha exacta, buscar el último registrado en memoria
            /** @var ExchangeRate|null $latestRate */
            $latestRate = $allRates->firstWhere('source_currency', $currencyCode);

            $defaultBuy = $currencyCode === 'USD' ? '3.742000' : ($currencyCode === 'EUR' ? '4.085000' : '1.000000');
            $defaultSell = $currencyCode === 'USD' ? '3.758000' : ($currencyCode === 'EUR' ? '4.112000' : '1.000000');

            $ratesList[] = [
                'currency_id' => $sec->currency->id,
                'code' => $currencyCode,
                'name' => $sec->currency->name,
                'symbol' => $sec->currency->symbol,
                'buy_rate' => $latestRate ? (string) $latestRate->buy_rate : $defaultBuy,
                'sell_rate' => $latestRate ? (string) $latestRate->sell_rate : $defaultSell,
                'is_recorded_for_date' => false,
                'source' => $latestRate ? 'LATEST_HISTORICAL' : 'DEFAULT_PRESET',
            ];
        }

        $recentHistory = $allRates->take(40)->values()->map(fn (ExchangeRate $er) => [
            'id' => $er->id,
            'rate_date' => $er->rate_date->toDateString(),
            'source_currency' => $er->source_currency,
            'target_currency' => $er->target_currency,
            'buy_rate' => (string) $er->buy_rate,
            'sell_rate' => (string) $er->sell_rate,
        ]);

        return response()->json([
            'data' => [
                'rate_date' => $rateDate->toDateString(),
                'base_currency' => [
                    'code' => $baseCode,
                    'name' => $baseCompanyCurrency?->currency?->name ?? 'Soles',
                    'symbol' => $baseCompanyCurrency?->currency?->symbol ?? 'S/',
                ],
                'required_currencies' => $ratesList,
                'recent_history' => $recentHistory,
            ],
        ]);
    }
}
