<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** One tenant-scoped, read-only account aggregation for exact fiscal reports. */
final readonly class PeriodLedgerService
{
    /**
     * @return array{currency:string,accounts:list<array<string,string|int>>}
     */
    public function read(string $from, string $to, bool $splitClosing = false): array
    {
        $company = app(TenantContext::class)->requireCompanyId(self::class);
        $currency = DB::table('company_settings as s')
            ->join('currencies as c', 'c.id', '=', 's.base_currency_id')
            ->where('s.company_id', $company)->where('c.is_active', true)->value('c.code');
        if (! is_string($currency) || $currency === '') {
            throw new ConflictHttpException('Configure la moneda funcional para generar reportes.');
        }
        $rows = DB::table('accounting_accounts as a')
            ->join('journal_entry_lines as l', function ($join): void {
                $join->on('l.accounting_account_id', '=', 'a.id')
                    ->on('l.company_id', '=', 'a.company_id');
            })
            ->join('journal_entries as j', function ($join): void {
                $join->on('j.id', '=', 'l.journal_entry_id')
                    ->on('j.company_id', '=', 'l.company_id');
            })
            ->where('a.company_id', $company)->where('l.company_id', $company)
            ->where('j.company_id', $company)
            ->where('j.entry_date', '<=', $to)
            ->whereIn('j.status', ['POSTED', 'REVERSED'])
            ->groupBy('a.id', 'a.code', 'a.name', 'a.account_type', 'a.financial_statement_rubric')
            ->orderBy('a.code')->orderBy('a.id')
            ->select('a.id', 'a.code', 'a.name', 'a.account_type', 'a.financial_statement_rubric as rubric')
            ->selectRaw('COALESCE(SUM(CASE WHEN j.entry_date < ? THEN l.debit_local ELSE 0 END),0) AS opening_debit', [$from])
            ->selectRaw('COALESCE(SUM(CASE WHEN j.entry_date < ? THEN l.credit_local ELSE 0 END),0) AS opening_credit', [$from])
            ->selectRaw('COALESCE(SUM(CASE WHEN j.entry_date >= ? THEN l.debit_local ELSE 0 END),0) AS period_debit', [$from])
            ->selectRaw('COALESCE(SUM(CASE WHEN j.entry_date >= ? THEN l.credit_local ELSE 0 END),0) AS period_credit', [$from])
            ->selectRaw("COALESCE(SUM(CASE WHEN j.entry_date >= ? AND j.entry_type = 'OPENING' THEN l.debit_local ELSE 0 END),0) AS opening_entry_debit", [$from])
            ->selectRaw("COALESCE(SUM(CASE WHEN j.entry_date >= ? AND j.entry_type = 'OPENING' THEN l.credit_local ELSE 0 END),0) AS opening_entry_credit", [$from]);
        if ($splitClosing) {
            $rows->leftJoin('journal_entries as original', function ($join): void {
                $join->on('original.id', '=', 'j.reversed_entry_id')->on('original.company_id', '=', 'j.company_id');
            });
            // An extorno retains the source category only inside the same fiscal year.
            $effectiveType = "CASE WHEN j.entry_type = 'REVERSAL' AND original.entry_date >= ? AND original.entry_date <= ?
                THEN original.entry_type ELSE j.entry_type END";
            foreach (['debit', 'credit'] as $side) {
                $rows->selectRaw("COALESCE(SUM(CASE WHEN j.entry_date >= ? AND ($effectiveType) = 'OPENING'
                    THEN l.{$side}_local ELSE 0 END), 0) AS annual_opening_$side", [$from, $from, $to]);
                $rows->selectRaw("COALESCE(SUM(CASE WHEN j.entry_date >= ? AND ($effectiveType) = 'CLOSING'
                    THEN l.{$side}_local ELSE 0 END), 0) AS transfer_$side", [$from, $from, $to]);
            }
        }

        return ['currency' => $currency, 'accounts' => $rows->get()->map(static fn ($row): array => [
            'id' => (int) $row->id,
            'code' => (string) $row->code, 'name' => (string) $row->name,
            'account_type' => (string) $row->account_type, 'rubric' => (string) $row->rubric,
            'opening_debit' => bcadd((string) $row->opening_debit, '0', 4),
            'opening_credit' => bcadd((string) $row->opening_credit, '0', 4),
            'period_debit' => bcadd((string) $row->period_debit, '0', 4),
            'period_credit' => bcadd((string) $row->period_credit, '0', 4),
            'opening_entry_debit' => bcadd((string) $row->opening_entry_debit, '0', 4),
            'opening_entry_credit' => bcadd((string) $row->opening_entry_credit, '0', 4),
        ] + ($splitClosing ? [
            'annual_opening_debit' => bcadd((string) $row->annual_opening_debit, '0', 4),
            'annual_opening_credit' => bcadd((string) $row->annual_opening_credit, '0', 4),
            'transfer_debit' => bcadd((string) $row->transfer_debit, '0', 4),
            'transfer_credit' => bcadd((string) $row->transfer_credit, '0', 4),
        ] : []))->all()];
    }
}
