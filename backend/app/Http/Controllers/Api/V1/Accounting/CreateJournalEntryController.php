<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Models\CompanyCurrency;
use App\Models\ExchangeRate;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Services\Accounting\PostJournalEntryService;
use App\Services\Security\AuditLoggerService;
use App\Support\Math\Decimal;
use App\Support\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Single Action Controller para el registro atómico de Asientos Contables Bimoneda (Vouchers)
 * cumpliendo con Partida Doble matemática estricta, obligatoriedad de tipos de cambio por moneda de trabajo
 * y auditoría ISO/IEC 27001:2023.
 */
final readonly class CreateJournalEntryController
{
    public function __construct(
        private PostJournalEntryService $postJournalEntryService,
        private AuditLoggerService $auditLogger,
        private TenantContext $tenantContext,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'entry_number' => ['nullable', 'string', 'max:30'],
            'entry_type' => ['sometimes', 'string', Rule::in(['STANDARD', 'OPENING', 'ADJUSTMENT', 'CLOSING'])],
            'currency' => ['required', 'string'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0', 'regex:/^\\d+(?:\\.\\d{1,6})?$/'],
            'exchange_rate_type' => ['sometimes', 'string', Rule::in(['BUY', 'SELL', 'PARITY'])],
            'exchange_rates' => ['sometimes', 'array'],
            'exchange_rates.*.currency' => ['required_with:exchange_rates', 'string'],
            'exchange_rates.*.buy_rate' => ['nullable', 'numeric', 'gt:0', 'regex:/^\\d+(?:\\.\\d{1,6})?$/'],
            'exchange_rates.*.sell_rate' => ['nullable', 'numeric', 'gt:0', 'regex:/^\\d+(?:\\.\\d{1,6})?$/'],
            'exchange_rates.*.rate' => ['nullable', 'numeric', 'gt:0', 'regex:/^\\d+(?:\\.\\d{1,6})?$/'],
            'description' => ['required', 'string', 'max:350'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.accounting_account_id' => ['required', 'integer', 'min:1'],
            'lines.*.cost_center_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.description' => ['nullable', 'string', 'max:250'],
            'lines.*.debit_local' => ['required', 'numeric', 'min:0', 'regex:/^\\d+(?:\\.\\d{1,4})?$/'],
            'lines.*.credit_local' => ['required', 'numeric', 'min:0', 'regex:/^\\d+(?:\\.\\d{1,4})?$/'],
            'lines.*.debit_foreign' => ['required', 'numeric', 'min:0', 'regex:/^\\d+(?:\\.\\d{1,4})?$/'],
            'lines.*.credit_foreign' => ['required', 'numeric', 'min:0', 'regex:/^\\d+(?:\\.\\d{1,4})?$/'],
        ]);

        $companyId = $this->tenantContext->requireCompanyId(JournalEntry::class);
        $user = $request->user();

        $entryDate = CarbonImmutable::parse($validated['entry_date']);
        $year = $entryDate->year;
        $month = $entryDate->month;

        // 1. Resuelve o abre automáticamente el periodo contable para la empresa
        /** @var FiscalPeriod $fiscalPeriod */
        $fiscalPeriod = FiscalPeriod::query()->firstOrCreate(
            [
                'year' => $year,
                'month' => $month,
            ],
            [
                'start_date' => $entryDate->startOfMonth()->toDateString(),
                'end_date' => $entryDate->endOfMonth()->toDateString(),
                'status' => 'OPEN',
            ]
        );

        if (! $fiscalPeriod->isOpen()) {
            return response()->json([
                'message' => 'El periodo contable ' . sprintf('%04d-%02d', $year, $month) . ' se encuentra cerrado.',
            ], 422);
        }

        // 2. Validación de obligatoriedad de tipos de cambio según las monedas configuradas en la empresa
        $companyCurrencies = CompanyCurrency::query()
            ->where('company_id', $companyId)
            ->with('currency')
            ->whereRaw('company_currencies.is_active IS TRUE')
            ->get();

        $baseCurrencyCode = $companyCurrencies->firstWhere('is_base', true)?->currency?->code ?? 'PEN';
        $secondaryCurrencies = $companyCurrencies->where('is_base', false);

        // Procesar array de tipos de cambio enviado en la petición (si existe)
        $inputRatesByCode = [];
        if (! empty($validated['exchange_rates'])) {
            foreach ($validated['exchange_rates'] as $rateItem) {
                $code = strtoupper(trim((string) $rateItem['currency']));
                $rateVal = $rateItem['rate'] ?? $rateItem['sell_rate'] ?? $rateItem['buy_rate'] ?? null;
                if ($rateVal !== null) {
                    $inputRatesByCode[$code] = [
                        'rate' => Decimal::ofExchangeRate((string) $rateVal)->toString(),
                        'buy_rate' => Decimal::ofExchangeRate((string) ($rateItem['buy_rate'] ?? $rateVal))->toString(),
                        'sell_rate' => Decimal::ofExchangeRate((string) ($rateItem['sell_rate'] ?? $rateVal))->toString(),
                    ];
                }
            }
        }

        // Si se envió un exchange_rate directo y hay exactamente una moneda secundaria
        if (isset($validated['exchange_rate']) && $secondaryCurrencies->count() === 1) {
            $singleSecCode = $secondaryCurrencies->first()->currency->code;
            if (! isset($inputRatesByCode[$singleSecCode])) {
                $rateExact = Decimal::ofExchangeRate((string) $validated['exchange_rate'])->toString();
                $inputRatesByCode[$singleSecCode] = [
                    'rate' => $rateExact,
                    'buy_rate' => $rateExact,
                    'sell_rate' => $rateExact,
                ];
            }
        }

        // Verificar obligatoriedad para CADA moneda secundaria configurada en la empresa
        foreach ($secondaryCompanyCurrencies = $secondaryCurrencies as $secCurrency) {
            $code = $secCurrency->currency->code;

            $hasInputRate = isset($inputRatesByCode[$code]) && Decimal::ofExchangeRate($inputRatesByCode[$code]['rate'])->isPositive();
            $hasDbRate = ExchangeRate::query()
                ->where('company_id', $companyId)
                ->where('rate_date', $entryDate->toDateString())
                ->where('source_currency', $code)
                ->exists();

            if (! $hasInputRate && ! $hasDbRate) {
                return response()->json([
                    'message' => sprintf(
                        'Es obligatorio el tipo de cambio para la moneda %s (%s) en la fecha %s. La empresa tiene configuradas %d monedas de trabajo.',
                        $code,
                        $secCurrency->currency->name,
                        $entryDate->toDateString(),
                        $companyCurrencies->count()
                    ),
                    'missing_currency' => $code,
                    'entry_date' => $entryDate->toDateString(),
                ], 422);
            }

            // Persistir o actualizar en exchange_rates si vino en el payload
            if ($hasInputRate) {
                ExchangeRate::query()->updateOrCreate(
                    [
                        'company_id' => $companyId,
                        'rate_date' => $entryDate->toDateString(),
                        'source_currency' => $code,
                        'target_currency' => $baseCurrencyCode,
                    ],
                    [
                        'buy_rate' => $inputRatesByCode[$code]['buy_rate'],
                        'sell_rate' => $inputRatesByCode[$code]['sell_rate'],
                    ]
                );
            }
        }

        // Determinar la tasa efectiva del voucher para el campo exchange_rate
        $voucherCurrency = strtoupper(trim((string) $validated['currency']));
        $effectiveRate = '1.000000';

        if ($voucherCurrency !== $baseCurrencyCode && isset($inputRatesByCode[$voucherCurrency])) {
            $effectiveRate = $inputRatesByCode[$voucherCurrency]['rate'];
        } elseif (isset($validated['exchange_rate']) && Decimal::ofExchangeRate((string) $validated['exchange_rate'])->isPositive()) {
            $effectiveRate = Decimal::ofExchangeRate((string) $validated['exchange_rate'])->toString();
        } else {
            // Buscar en base de datos para la moneda secundaria
            $recordedRate = ExchangeRate::query()
                ->where('company_id', $companyId)
                ->where('rate_date', $entryDate->toDateString())
                ->where('source_currency', $voucherCurrency)
                ->first();

            if ($recordedRate) {
                $effectiveRate = (string) $recordedRate->sell_rate;
            } elseif ($voucherCurrency === $baseCurrencyCode && $secondaryCurrencies->isNotEmpty()) {
                // Si el voucher es en moneda base, tomar la tasa de la primera moneda secundaria para la conversión
                $firstSec = $secondaryCurrencies->first()->currency->code;
                $secRate = ExchangeRate::query()
                    ->where('company_id', $companyId)
                    ->where('rate_date', $entryDate->toDateString())
                    ->where('source_currency', $firstSec)
                    ->first();
                $effectiveRate = $secRate
                    ? (string) $secRate->sell_rate
                    : Decimal::ofExchangeRate((string) ($validated['exchange_rate'] ?? '1'))->toString();
            }
        }

        // 3. El correlativo AUTOMATICO se calcula dentro del servicio
        // bajo FOR UPDATE del periodo, nunca aqui antes del bloqueo.
        $entryNumber = trim((string) ($validated['entry_number'] ?? ''));

        $formattedLines = array_map(static function (array $line) use ($validated): array {
            return [
                'accounting_account_id' => (int) $line['accounting_account_id'],
                'cost_center_id' => isset($line['cost_center_id']) ? (int) $line['cost_center_id'] : null,
                'description' => $line['description'] ?? $validated['description'],
                'debit_local' => Decimal::ofMonetary((string) $line['debit_local'])->toString(),
                'credit_local' => Decimal::ofMonetary((string) $line['credit_local'])->toString(),
                'debit_foreign' => Decimal::ofMonetary((string) $line['debit_foreign'])->toString(),
                'credit_foreign' => Decimal::ofMonetary((string) $line['credit_foreign'])->toString(),
            ];
        }, $validated['lines']);

        try {
            $journalEntry = $this->postJournalEntryService->post([
                'fiscal_period_id' => $fiscalPeriod->id,
                'entry_number' => $entryNumber,
                'entry_date' => $entryDate->toDateString(),
                'entry_type' => $validated['entry_type'] ?? 'STANDARD',
                'currency' => $voucherCurrency,
                'exchange_rate' => $effectiveRate,
                'exchange_rate_type' => $validated['exchange_rate_type'] ?? 'SELL',
                'description' => $validated['description'],
                'created_by_user_id' => $user->id,
                'lines' => $formattedLines,
            ]);
        } catch (DomainException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        $this->auditLogger->log(
            eventType: 'JOURNAL_ENTRY_CREATED',
            companyId: $companyId,
            userId: $user->id,
            auditableType: JournalEntry::class,
            auditableId: (string) $journalEntry->id,
            oldValues: null,
            newValues: [
                'entry_number' => $journalEntry->entry_number,
                'entry_date' => $journalEntry->entry_date->toDateString(),
                'currency' => $journalEntry->currency,
                'exchange_rate' => $journalEntry->exchange_rate,
                'lines_count' => $journalEntry->lines->count(),
                'manual_lines_count' => count($formattedLines),
                'analytic_lines_count' => $journalEntry->lines->count() - count($formattedLines),
            ],
            request: $request,
            severity: 'INFO',
        );

        $journalEntry->load(['lines.account', 'lines.costCenter', 'fiscalPeriod']);

        return response()->json([
            'message' => 'Asiento contable registrado exitosamente con Partida Doble balanceada.',
            'data' => $journalEntry,
        ], 201);
    }
}
