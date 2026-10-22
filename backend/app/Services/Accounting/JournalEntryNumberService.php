<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Calcula correlativos 02-00001 a 02-99999 sin usar COUNT(*).
 *
 * IMPORTANTE: nextForPeriod() no reserva números. Para emitir, invocarlo
 * exclusivamente DESPUES de SELECT ... FOR UPDATE del periodo fiscal, dentro
 * de la MISMA transaccion que inserta el asiento (PostJournalEntryService).
 *
 * La API next-number solo ofrece una vista previa referencial.
 */
final readonly class JournalEntryNumberService
{
    public function nextForPeriod(int $companyId, int $periodId, int $month): string
    {
        if ($companyId <= 0 || $periodId <= 0 || $month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Empresa, periodo o mes contable invalidos.');
        }

        $prefix = sprintf('%02d-', $month);

        // Regex restringe a exactamente dos dígitos, guion y cinco dígitos;
        // el LIKE conserva sólo el mes solicitado. No interpolar tenant IDs.
        $row = DB::selectOne(
            <<<'SQL'
                SELECT COALESCE(MAX(right(entry_number, 5)::integer), 0)::integer AS max_suffix
                FROM public.journal_entries
                WHERE company_id = ? AND fiscal_period_id = ?
                  AND entry_number LIKE ?
                  AND entry_number ~ '^[0-9]{2}-[0-9]{5}$'
                SQL,
            [$companyId, $periodId, $prefix.'%']
        );

        $next = (int) ($row->max_suffix ?? 0) + 1;
        if ($next > 99999) {
            throw new DomainException('El periodo alcanzó el límite del correlativo contable.');
        }

        return $prefix.sprintf('%05d', $next);
    }

    public function firstForMonth(int $month): string
    {
        if ($month < 1 || $month > 12) {
            throw new \InvalidArgumentException('Mes contable invalido.');
        }

        return sprintf('%02d-00001', $month);
    }
}
