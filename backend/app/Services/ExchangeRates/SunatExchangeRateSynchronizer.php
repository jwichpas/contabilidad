<?php

declare(strict_types=1);

namespace App\Services\ExchangeRates;

use App\Models\CompanyCurrency;
use App\Models\ExchangeRate;
use App\Services\Security\AuditLoggerService;
use App\Support\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Importador idempotente por empresa y fecha, usando SIEMPRE el rol restringido
 * y el contexto de RLS. Los tipos de cambio manuales tienen precedencia.
 *
 * NO importa fechas históricas: tipoCambio.txt solo contiene la fecha publicada.
 */
final readonly class SunatExchangeRateSynchronizer
{
    public function __construct(
        private SunatCurrentExchangeRateClient $client,
        private TenantContext $tenant,
        private AuditLoggerService $audit,
    ) {}

    /**
     * @return array{status:string, date:string, currency:string, buy_rate?:string, sell_rate?:string, published_date?:string}
     */
    public function sync(int $companyId, string $date, ?int $userId = null): array
    {
        if ($companyId < 1 || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            throw new \InvalidArgumentException('Empresa o fecha de sincronización inválida.');
        }

        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->toDateString() !== $date) {
            throw new \InvalidArgumentException('Fecha de sincronización inválida.');
        }

        $base = ['date' => $date, 'currency' => 'USD'];

        // First RLS-scoped read: no HTTP call if currency is not configured
        // or the exact-date row already exists (including manually entered rates).
        $existing = $this->tenant->run($companyId, static function () use ($companyId, $date): array {
            $currencies = CompanyCurrency::query()
                ->where('company_id', $companyId)->where('is_active', true)
                ->with('currency')->get();

            $baseCode = $currencies->firstWhere('is_base', true)?->currency?->code;
            $hasUsd = $currencies->where('is_base', false)
                ->contains(static fn (CompanyCurrency $currency): bool => $currency->currency?->code === 'USD');

            if ($baseCode !== 'PEN' || ! $hasUsd) {
                return ['status' => 'unsupported'];
            }

            $rate = ExchangeRate::query()->where('company_id', $companyId)
                ->where('rate_date', $date)
                ->where('source_currency', 'USD')
                ->where('target_currency', 'PEN')->first();

            return $rate === null ? ['status' => 'missing'] : [
                'status' => 'existing', 'buy_rate' => (string) $rate->buy_rate,
                'sell_rate' => (string) $rate->sell_rate,
            ];
        });

        if ($existing['status'] !== 'missing') {
            return [...$base, ...$existing];
        }

        $quote = $this->client->fetch();
        if ($quote === null) {
            // SUNAT may be temporarily inaccessible. Another authorized request
            // could have saved the rate while the HTTP lookup was in flight.
            // Re-check the authoritative tenant-scoped database before reporting 503.
            $concurrent = $this->tenant->run($companyId, static function () use ($companyId, $date): ?ExchangeRate {
                return ExchangeRate::query()
                    ->where('company_id', $companyId)
                    ->where('rate_date', $date)
                    ->where('source_currency', 'USD')
                    ->where('target_currency', 'PEN')
                    ->first();
            });
            if ($concurrent !== null) {
                return [
                    ...$base, 'status' => 'existing',
                    'buy_rate' => (string) $concurrent->buy_rate,
                    'sell_rate' => (string) $concurrent->sell_rate,
                ];
            }
            return [...$base, 'status' => 'unavailable'];
        }
        if ($quote['date'] !== $date) {
            return [...$base, 'status' => 'date_mismatch', 'published_date' => $quote['date']];
        }

        // Separate RLS-scoped transaction: never overwrite an existing rate,
        // even if another operator or request saved one during the HTTP call.
        return $this->tenant->run($companyId, function () use ($companyId, $date, $quote, $userId, $base): array {
            $rate = ExchangeRate::query()->firstOrCreate(
                [
                    'company_id' => $companyId, 'rate_date' => $date,
                    'source_currency' => 'USD', 'target_currency' => 'PEN',
                ],
                [
                    'buy_rate' => $quote['buy'],
                    'sell_rate' => $quote['sell'],
                ]
            );
            $created = $rate->wasRecentlyCreated;
            if ($created) {
                $this->audit->log(
                    eventType: 'SUNAT_EXCHANGE_RATE_IMPORTED',
                    companyId: $companyId,
                    userId: $userId,
                    auditableType: ExchangeRate::class,
                    auditableId: (string) $rate->id,
                    oldValues: null,
                    newValues: [
                        'rate_date' => $date, 'currency' => 'USD',
                        'buy_rate' => $quote['buy'], 'sell_rate' => $quote['sell'],
                        'source' => 'SUNAT_TXT_EXACT',
                    ]
                );
            }
            return [
                ...$base,
                'status' => $created ? 'imported' : 'existing',
                'buy_rate' => (string) $rate->buy_rate,
                'sell_rate' => (string) $rate->sell_rate,
            ];
        });
    }
}
