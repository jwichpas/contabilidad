<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Models\CompanyCurrency;
use App\Models\ExchangeRate;
use App\Services\Security\AuditLoggerService;
use App\Support\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint para registrar o actualizar los tipos de cambio diarios de las monedas de la empresa.
 */
final readonly class SaveCompanyExchangeRatesController
{
    public function __construct(
        private TenantContext $tenantContext,
        private AuditLoggerService $auditLogger,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $companyId = $this->tenantContext->requireCompanyId(ExchangeRate::class);

        $validated = $request->validate([
            'rate_date' => ['required', 'date_format:Y-m-d'],
            'rates' => ['required', 'array', 'min:1'],
            'rates.*.currency' => ['required', 'string', 'max:3'],
            'rates.*.buy_rate' => ['required', 'numeric', 'gt:0'],
            'rates.*.sell_rate' => ['required', 'numeric', 'gt:0'],
        ]);

        $rateDate = CarbonImmutable::parse($validated['rate_date'])->toDateString();

        // Obtener la moneda base de la empresa
        $baseCompanyCurrency = CompanyCurrency::query()
            ->where('company_id', $companyId)
            ->where('is_base', true)
            ->with('currency')
            ->first();

        $targetCurrency = $baseCompanyCurrency?->currency?->code ?? 'PEN';

        $savedRates = [];

        foreach ($validated['rates'] as $rateItem) {
            $sourceCurrency = strtoupper(trim((string) $rateItem['currency']));
            $buyRate = number_format((float) $rateItem['buy_rate'], 6, '.', '');
            $sellRate = number_format((float) $rateItem['sell_rate'], 6, '.', '');

            if ($sourceCurrency === $targetCurrency) {
                continue;
            }

            /** @var ExchangeRate $rate */
            $rate = ExchangeRate::query()->updateOrCreate(
                [
                    'company_id' => $companyId,
                    'rate_date' => $rateDate,
                    'source_currency' => $sourceCurrency,
                    'target_currency' => $targetCurrency,
                ],
                [
                    'buy_rate' => $buyRate,
                    'sell_rate' => $sellRate,
                ]
            );

            $savedRates[] = $rate;
        }

        $this->auditLogger->log(
            eventType: 'EXCHANGE_RATES_UPDATED',
            companyId: $companyId,
            userId: $request->user()?->id,
            auditableType: ExchangeRate::class,
            auditableId: $rateDate,
            oldValues: null,
            newValues: [
                'rate_date' => $rateDate,
                'target_currency' => $targetCurrency,
                'rates_count' => count($savedRates),
            ],
            request: $request,
            severity: 'INFO',
        );

        return response()->json([
            'message' => 'Tipos de cambio guardados exitosamente.',
            'data' => $savedRates,
        ]);
    }
}
