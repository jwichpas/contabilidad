<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Models\ExchangeRate;
use Illuminate\Support\Facades\DB;
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

        // Both the active currencies and the last 100 eligible daily rates are
        // fetched in ONE PostgreSQL roundtrip. Keep FORCE RLS plus explicit
        // company predicates: neither authentication nor rates are cached.
        // The 100-row cap, default prices and historic fallback are unchanged.
        $row = DB::selectOne(<<<'SQL'
            SELECT
              COALESCE((
                SELECT jsonb_agg(jsonb_build_object(
                  'currency_id', cc.currency_id,
                  'is_base', cc.is_base,
                  'currency_code', cur.code,
                  'currency_name', cur.name,
                  'currency_symbol', cur.symbol
                ) ORDER BY cc.is_base DESC, cc.id)
                FROM public.company_currencies cc
                JOIN public.currencies cur ON cur.id = cc.currency_id
                WHERE cc.company_id = ?::bigint AND cc.is_active IS TRUE
              ), '[]'::jsonb) AS company_currencies,
              COALESCE((
                SELECT jsonb_agg(jsonb_build_object(
                  'id', rates.id,
                  'rate_date', rates.rate_date::text,
                  'source_currency', rates.source_currency,
                  'target_currency', rates.target_currency,
                  'buy_rate', rates.buy_rate::text,
                  'sell_rate', rates.sell_rate::text
                ) ORDER BY rates.rate_date DESC)
                FROM (
                  SELECT id, rate_date, source_currency, target_currency,
                         buy_rate, sell_rate
                  FROM public.exchange_rates
                  WHERE company_id = ?::bigint AND rate_date <= ?::date
                  ORDER BY rate_date DESC
                  LIMIT 100
                ) rates
              ), '[]'::jsonb) AS recent_rates
            SQL, [$companyId, $companyId, $rateDate->toDateString()]);

        $companyCurrencies = collect(json_decode(
            (string) ($row?->company_currencies ?? '[]'), true, 512, JSON_THROW_ON_ERROR
        ));
        $allRates = collect(json_decode(
            (string) ($row?->recent_rates ?? '[]'), true, 512, JSON_THROW_ON_ERROR
        ));

        $baseCompanyCurrency = $companyCurrencies->first(fn (array $currency): bool => $currency['is_base'] === true);
        $baseCode = $baseCompanyCurrency['currency_code'] ?? 'PEN';
        $secondaryCompanyCurrencies = $companyCurrencies->filter(
            fn (array $currency): bool => $currency['is_base'] === false
        );

        $ratesList = [];

        foreach ($secondaryCompanyCurrencies as $sec) {
            $currencyCode = $sec['currency_code'];

            // Exact rate takes precedence over historical fallback.
            $exactRate = $allRates->first(fn (array $rate): bool =>
                $rate['source_currency'] === $currencyCode
                && $rate['rate_date'] === $rateDate->toDateString()
            );

            if ($exactRate !== null) {
                $ratesList[] = [
                    'currency_id' => $sec['currency_id'],
                    'code' => $currencyCode,
                    'name' => $sec['currency_name'],
                    'symbol' => $sec['currency_symbol'],
                    'buy_rate' => $exactRate['buy_rate'],
                    'sell_rate' => $exactRate['sell_rate'],
                    'is_recorded_for_date' => true,
                    'source' => 'DATABASE_EXACT',
                ];
                continue;
            }

            $latestRate = $allRates->first(fn (array $rate): bool =>
                $rate['source_currency'] === $currencyCode
            );

            $defaultBuy = $currencyCode === 'USD' ? '3.742000' : ($currencyCode === 'EUR' ? '4.085000' : '1.000000');
            $defaultSell = $currencyCode === 'USD' ? '3.758000' : ($currencyCode === 'EUR' ? '4.112000' : '1.000000');

            $ratesList[] = [
                'currency_id' => $sec['currency_id'],
                'code' => $currencyCode,
                'name' => $sec['currency_name'],
                'symbol' => $sec['currency_symbol'],
                'buy_rate' => $latestRate ? $latestRate['buy_rate'] : $defaultBuy,
                'sell_rate' => $latestRate ? $latestRate['sell_rate'] : $defaultSell,
                'is_recorded_for_date' => false,
                'source' => $latestRate ? 'LATEST_HISTORICAL' : 'DEFAULT_PRESET',
            ];
        }

        $recentHistory = $allRates->take(40)->values()->map(fn (array $rate): array => [
            'id' => $rate['id'],
            'rate_date' => $rate['rate_date'],
            'source_currency' => $rate['source_currency'],
            'target_currency' => $rate['target_currency'],
            'buy_rate' => $rate['buy_rate'],
            'sell_rate' => $rate['sell_rate'],
        ]);

        return response()->json([
            'data' => [
                'rate_date' => $rateDate->toDateString(),
                'base_currency' => [
                    'code' => $baseCode,
                    'name' => $baseCompanyCurrency['currency_name'] ?? 'Soles',
                    'symbol' => $baseCompanyCurrency['currency_symbol'] ?? 'S/',
                ],
                'required_currencies' => $ratesList,
                'recent_history' => $recentHistory,
            ],
        ]);
    }
}
