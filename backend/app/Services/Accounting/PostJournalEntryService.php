<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Support\Math\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de dominio para el registro de Asientos Contables Bimoneda y Asientos de Extorno.
 *
 * Garantiza la Partida Doble en las 3 capas exigidas por la arquitectura:
 * 1. Validación matemática exacta en PHP con `Decimal` (BCMath) sin punto flotante (`SUM(debe) - SUM(haber) = 0.0000`).
 * 2. Transaccion atomica Laravel, con bloqueo FOR UPDATE del periodo.
 *    El aislamiento SQL es el predeterminado de la conexion (no se fuerza
 *    REPEATABLE READ). Su concurrencia debe cubrirse con tests especificos.
 * 3. `CONSTRAINT TRIGGER ... DEFERRABLE INITIALLY DEFERRED` en PostgreSQL
 *    al confirmar la transaccion; en tests ROLLBACK forzar SET CONSTRAINTS.
 */
final readonly class PostJournalEntryService
{
    public function __construct(
        private JournalEntryNumberService $entryNumbers,
    ) {}

    /**
     * @param  array{
     *     fiscal_period_id: int,
     *     entry_number?: string,
     *     entry_date: string,
     *     entry_type?: string,
     *     currency: string,
     *     exchange_rate: string,
     *     exchange_rate_type: string,
     *     description: string,
     *     created_by_user_id: int,
     *     lines: non-empty-list<array{
     *         accounting_account_id: int,
     *         description?: string|null,
     *         debit_local: string,
     *         credit_local: string,
     *         debit_foreign: string,
     *         credit_foreign: string
     *     }>
     * }  $payload
     */
    public function post(array $payload): JournalEntry
    {
        if (count($payload['lines']) < 2) {
            throw new DomainException('Todo asiento contable debe contener al menos 2 líneas.');
        }

        $exchangeRate = Decimal::ofExchangeRate($payload['exchange_rate']);
        if (! $exchangeRate->isPositive()) {
            throw new DomainException('El tipo de cambio del asiento debe ser mayor a 0.000000.');
        }

        $totalDebitLocal = Decimal::zero(Decimal::MONETARY_SCALE);
        $totalCreditLocal = Decimal::zero(Decimal::MONETARY_SCALE);
        $totalDebitForeign = Decimal::zero(Decimal::MONETARY_SCALE);
        $totalCreditForeign = Decimal::zero(Decimal::MONETARY_SCALE);

        foreach ($payload['lines'] as $index => $line) {
            $debitLocal = Decimal::ofMonetary($line['debit_local']);
            $creditLocal = Decimal::ofMonetary($line['credit_local']);
            $debitForeign = Decimal::ofMonetary($line['debit_foreign']);
            $creditForeign = Decimal::ofMonetary($line['credit_foreign']);

            $isDebit = $debitLocal->isPositive() && $creditLocal->isZero() && $debitForeign->isPositive() && $creditForeign->isZero();
            $isCredit = $creditLocal->isPositive() && $debitLocal->isZero() && $creditForeign->isPositive() && $debitForeign->isZero();

            if (! $isDebit && ! $isCredit) {
                throw new DomainException(sprintf(
                    'La línea #%d debe tener monto positivo exclusivamente en el Debe o en el Haber en ambas monedas.',
                    $index + 1
                ));
            }

            $totalDebitLocal = $totalDebitLocal->add($debitLocal);
            $totalCreditLocal = $totalCreditLocal->add($creditLocal);
            $totalDebitForeign = $totalDebitForeign->add($debitForeign);
            $totalCreditForeign = $totalCreditForeign->add($creditForeign);
        }

        $diffLocal = $totalDebitLocal->sub($totalCreditLocal);
        $diffForeign = $totalDebitForeign->sub($totalCreditForeign);

        if (! $diffLocal->isZero() || ! $diffForeign->isZero()) {
            throw new DomainException(sprintf(
                'El asiento no cuadra por Partida Doble. Diferencia MN: %s | Diferencia ME: %s.',
                $diffLocal->toString(),
                $diffForeign->toString()
            ));
        }

        return DB::transaction(function () use ($payload, $exchangeRate): JournalEntry {
            /** @var FiscalPeriod $period */
            $period = FiscalPeriod::query()->lockForUpdate()->findOrFail($payload['fiscal_period_id']);
            if (! $period->isOpen()) {
                throw new DomainException('No se puede registrar un asiento en un periodo contable cerrado o bloqueado.');
            }

            // El periodo queda bloqueado en esta transaccion hasta el COMMIT;
            // no calcular el correlativo en el controlador ANTES del bloqueo.
            // Los asientos con numero manual mantienen su numero; PostgreSQL
            // aplica UNIQUE (company_id, fiscal_period_id, entry_number).
            $entryNumber = trim((string) ($payload['entry_number'] ?? ''));
            if ($entryNumber === '') {
                $entryNumber = $this->entryNumbers->nextForPeriod(
                    (int) $period->company_id,
                    (int) $period->id,
                    (int) $period->month
                );
            }

            $entry = JournalEntry::query()->create([
                'fiscal_period_id' => $period->id,
                'entry_number' => $entryNumber,
                'entry_date' => $payload['entry_date'],
                'entry_type' => $payload['entry_type'] ?? 'STANDARD',
                'status' => 'POSTED',
                'currency' => strtoupper($payload['currency']),
                'exchange_rate' => $exchangeRate->toString(),
                'exchange_rate_type' => $payload['exchange_rate_type'],
                'description' => trim($payload['description']),
                'created_by_user_id' => $payload['created_by_user_id'],
            ]);

            foreach ($payload['lines'] as $index => $line) {
                JournalEntryLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'line_number' => $index + 1,
                    'accounting_account_id' => $line['accounting_account_id'],
                    'description' => $line['description'] ?? null,
                    'debit_local' => Decimal::ofMonetary($line['debit_local'])->toString(),
                    'credit_local' => Decimal::ofMonetary($line['credit_local'])->toString(),
                    'debit_foreign' => Decimal::ofMonetary($line['debit_foreign'])->toString(),
                    'credit_foreign' => Decimal::ofMonetary($line['credit_foreign'])->toString(),
                    'exchange_rate' => $exchangeRate->toString(),
                ]);
            }

            return $entry->load('lines');
        });
    }

    /**
     * Genera un Asiento de Extorno (inverso exacto Debe <-> Haber) para revertir un asiento aprobado
     * sin violar la inmutabilidad del libro diario.
     */
    public function reverse(
        int $originalEntryId,
        int $targetFiscalPeriodId,
        string $reversalEntryNumber,
        string $reversalDate,
        string $reason,
        int $userId,
    ): JournalEntry {
        return DB::transaction(function () use (
            $originalEntryId,
            $targetFiscalPeriodId,
            $reversalEntryNumber,
            $reversalDate,
            $reason,
            $userId,
        ): JournalEntry {
            /** @var JournalEntry $original */
            $original = JournalEntry::query()->with('lines')->lockForUpdate()->findOrFail($originalEntryId);

            if ($original->status !== 'POSTED') {
                throw new DomainException('Solo se puede extornar un asiento que se encuentre en estado POSTED.');
            }

            $original->update(['status' => 'REVERSED']);

            $reversal = JournalEntry::query()->create([
                'fiscal_period_id' => $targetFiscalPeriodId,
                'entry_number' => trim($reversalEntryNumber),
                'entry_date' => $reversalDate,
                'entry_type' => 'REVERSAL',
                'status' => 'POSTED',
                'currency' => $original->currency,
                'exchange_rate' => (string) $original->exchange_rate,
                'exchange_rate_type' => $original->exchange_rate_type,
                'description' => sprintf('EXTORNO ASIENTO %s: %s', $original->entry_number, trim($reason)),
                'reversed_entry_id' => $original->id,
                'created_by_user_id' => $userId,
            ]);

            foreach ($original->lines as $index => $line) {
                JournalEntryLine::query()->create([
                    'journal_entry_id' => $reversal->id,
                    'line_number' => $index + 1,
                    'accounting_account_id' => $line->accounting_account_id,
                    'description' => sprintf('Extorno línea #%d', $line->line_number),
                    // Inversión exacta Debe <-> Haber en ambas monedas
                    'debit_local' => (string) $line->credit_local,
                    'credit_local' => (string) $line->debit_local,
                    'debit_foreign' => (string) $line->credit_foreign,
                    'credit_foreign' => (string) $line->debit_foreign,
                    'exchange_rate' => (string) $line->exchange_rate,
                ]);
            }

            return $reversal->load('lines');
        });
    }
}
