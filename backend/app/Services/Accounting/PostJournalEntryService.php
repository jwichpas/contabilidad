<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Support\Math\Decimal;
use Carbon\CarbonImmutable;
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
        private AnalyticRoutingService $analyticRouting,
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
     *         cost_center_id?: int|null,
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
        if (count($payload['lines']) > 10000) {
            throw new DomainException('Maximo 10000 partidas originales para reservar numeros analiticos.');
        }
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

            $versionId = $this->analyticRouting->activeVersionId((int)$period->company_id);
            $analyticSources = [];
            foreach ($payload['lines'] as $index => $line) {
                $centerId = $line['cost_center_id'] ?? null;
                $account = DB::table('accounting_accounts')
                    ->where('company_id', (int) $period->company_id)
                    ->where('id', (int) $line['accounting_account_id'])
                    ->first(['requires_cost_center','has_automatic_destination','account_type','code','is_operational','is_active']);
                if ($account === null) {
                    throw new DomainException(sprintf('Cuenta de partida #%d no pertenece a la empresa activa.', $index + 1));
                }
                if ((bool) $account->requires_cost_center && $centerId === null) {
                    throw new DomainException(sprintf('La partida #%d requiere un centro de costos activo.', $index + 1));
                }
                if ($centerId !== null) {
                    // RLS + same-tenant composite FK: never accept a center of
                    // a different company, or one explicitly deactivated.
                    $isValidCenter = DB::table('cost_centers')
                        ->where('company_id', (int) $period->company_id)
                        ->where('id', (int) $centerId)
                        ->where('is_active', true)
                        ->exists();
                    if (! $isValidCenter) {
                        throw new DomainException(sprintf('Centro de costos no activo o ajeno a la empresa en partida #%d.', $index + 1));
                    }
                }
                $sourceLine = JournalEntryLine::query()->create([
                    'journal_entry_id' => $entry->id,
                    'line_number' => $index + 1,
                    'accounting_account_id' => $line['accounting_account_id'],
                    'cost_center_id' => $centerId,
                    'description' => $line['description'] ?? null,
                    'debit_local' => Decimal::ofMonetary($line['debit_local'])->toString(),
                    'credit_local' => Decimal::ofMonetary($line['credit_local'])->toString(),
                    'debit_foreign' => Decimal::ofMonetary($line['debit_foreign'])->toString(),
                    'credit_foreign' => Decimal::ofMonetary($line['credit_foreign'])->toString(),
                    'exchange_rate' => $exchangeRate->toString(),
                ]);
                if ($versionId !== null && (bool)$account->has_automatic_destination) {
                    if (! (bool)$account->is_operational || ! (bool)$account->is_active
                        || (string)$account->account_type !== 'EXPENSE'
                        || ! str_starts_with((string)$account->code, '6')
                        || strlen((string)$account->code) !== 6
                        || ! in_array(($payload['entry_type'] ?? 'STANDARD'), ['STANDARD','ADJUSTMENT'], true)) {
                        throw new DomainException('Cuenta o tipo de asiento incompatible con imputacion analitica activa.');
                    }
                    if ($centerId === null) {
                        throw new DomainException('La regla automatica requiere centro de costos.');
                    }
                    $analyticSources[] = [$sourceLine, $line, (int)$centerId];
                }
            }

            // SOLO cuando existe una matriz ACTIVE. Mientras sea DRAFT,
            // registrar asientos manuales conserva el comportamiento F2.3a.
            $nextLineNumber = count($payload['lines']);
            foreach ($analyticSources as [$sourceLine, $line, $centerId]) {
                $destination = $this->analyticRouting->resolve(
                    (int)$period->company_id, $versionId,
                    (int)$sourceLine->accounting_account_id, $centerId
                );
                $mirrored = AnalyticRoutingService::mirrorAmounts($line);
                $sourceIsDebit = Decimal::ofMonetary($line['debit_local'])->isPositive();
                // Gasto Debe 6 -> Debe 94/95, Haber 79.
                // Nota de ajuste Haber 6 -> Debe 79, Haber 94/95.
                $debitTarget = $sourceIsDebit
                    ? $destination['debit_account_id'] : $destination['credit_account_id'];
                $creditTarget = $sourceIsDebit
                    ? $destination['credit_account_id'] : $destination['debit_account_id'];

                $debitLine = JournalEntryLine::query()->create([
                    'journal_entry_id'=>$entry->id,
                    'line_number'=>++$nextLineNumber,
                    'accounting_account_id'=>$debitTarget,
                    'cost_center_id'=>$centerId,
                    'description'=>'Analitica Debe de partida #'.$sourceLine->line_number,
                    ...$mirrored['debit'],
                    'exchange_rate'=>$exchangeRate->toString(),
                ]);
                $creditLine = JournalEntryLine::query()->create([
                    'journal_entry_id'=>$entry->id,
                    'line_number'=>++$nextLineNumber,
                    'accounting_account_id'=>$creditTarget,
                    'cost_center_id'=>$centerId,
                    'description'=>'Analitica Haber de partida #'.$sourceLine->line_number,
                    ...$mirrored['credit'],
                    'exchange_rate'=>$exchangeRate->toString(),
                ]);
                DB::table('journal_analytic_allocations')->insert([
                    'company_id'=>(int)$period->company_id,
                    'journal_entry_id'=>(int)$entry->id,
                    'source_line_id'=>(int)$sourceLine->id,
                    'debit_line_id'=>(int)$debitLine->id,
                    'credit_line_id'=>(int)$creditLine->id,
                    'rule_version_id'=>$versionId,
                    'cost_center_id'=>$centerId,
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
            // El extorno debe registrarse en un periodo ABIERTO que corresponda
            // exactamente a la fecha. Se bloquea para impedir cierres
            // concurrentes antes de insertar las partidas.
            /** @var FiscalPeriod $targetPeriod */
            $targetPeriod = FiscalPeriod::query()->lockForUpdate()->findOrFail($targetFiscalPeriodId);
            if (! $targetPeriod->isOpen()) {
                throw new DomainException('No se pueden registrar extornos en un periodo cerrado o bloqueado.');
            }
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $reversalDate);
            if ($date === false || (int) $date->year !== (int) $targetPeriod->year
                || (int) $date->month !== (int) $targetPeriod->month) {
                throw new DomainException('La fecha del extorno debe pertenecer al periodo contable seleccionado.');
            }

            /** @var JournalEntry $original */
            $original = JournalEntry::query()->with('lines')->lockForUpdate()->findOrFail($originalEntryId);
            if ((int) $original->company_id !== (int) $targetPeriod->company_id) {
                throw new DomainException('El asiento original y el periodo destino deben pertenecer a la misma empresa.');
            }

            if ($original->status !== 'POSTED') {
                throw new DomainException('Solo se puede extornar un asiento que se encuentre en estado POSTED.');
            }

            // Asignacion de correlativo protegida por el bloqueo del periodo.
            $number = trim($reversalEntryNumber);
            if ($number === '') {
                $number = $this->entryNumbers->nextForPeriod(
                    (int) $targetPeriod->company_id,
                    (int) $targetPeriod->id,
                    (int) $targetPeriod->month
                );
            }

            $original->update(['status' => 'REVERSED']);

            $reversal = JournalEntry::query()->create([
                'fiscal_period_id' => $targetFiscalPeriodId,
                'entry_number' => $number,
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

            $reversedLineIds = [];
            foreach ($original->lines as $index => $line) {
                $newLine = JournalEntryLine::query()->create([
                    'journal_entry_id' => $reversal->id,
                    'line_number' => $index + 1,
                    'accounting_account_id' => $line->accounting_account_id,
                    'cost_center_id' => $line->cost_center_id,
                    'description' => sprintf('Extorno línea #%d', $line->line_number),
                    // Inversión exacta Debe <-> Haber en ambas monedas
                    'debit_local' => (string) $line->credit_local,
                    'credit_local' => (string) $line->debit_local,
                    'debit_foreign' => (string) $line->credit_foreign,
                    'credit_foreign' => (string) $line->debit_foreign,
                    'exchange_rate' => (string) $line->exchange_rate,
                ]);
                $reversedLineIds[(int)$line->id] = (int)$newLine->id;
            }

            // Copy analytic lineage without regenerating allocations or querying
            // a now-retired rule. Reversed Debit/credit legs SWAP roles.
            $originalAllocations = DB::table('journal_analytic_allocations')
                ->where('company_id', (int)$original->company_id)
                ->where('journal_entry_id', (int)$original->id)
                ->get();
            foreach ($originalAllocations as $link) {
                $newSource=$reversedLineIds[(int)$link->source_line_id] ?? null;
                $newDebit=$reversedLineIds[(int)$link->credit_line_id] ?? null;
                $newCredit=$reversedLineIds[(int)$link->debit_line_id] ?? null;
                if ($newSource===null || $newDebit===null || $newCredit===null) {
                    throw new DomainException('Extorno sin trazabilidad analitica completa.');
                }
                DB::table('journal_analytic_allocations')->insert([
                    'company_id'=>(int)$original->company_id,
                    'journal_entry_id'=>(int)$reversal->id,
                    'source_line_id'=>$newSource,
                    'debit_line_id'=>$newDebit,
                    'credit_line_id'=>$newCredit,
                    'rule_version_id'=>(int)$link->rule_version_id,
                    'cost_center_id'=>(int)$link->cost_center_id,
                ]);
            }

            return $reversal->load('lines');
        });
    }
}
