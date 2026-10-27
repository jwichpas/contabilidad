<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Read-only, tenant-scoped balances derived from posted journal lines. */
final readonly class GetFinancialSnapshotController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'as_of' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
        ]);
        $asOf = CarbonImmutable::createFromFormat('!Y-m-d', $validated['as_of'])->format('Y-m-d');
        $yearStart = substr($asOf, 0, 4).'-01-01';
        $companyId = (int) $request->header('X-Company-Id', $request->user()->current_company_id);

        // NUMERIC sums stay strings end to end. Include original REVERSED entries
        // alongside their posted reversal so the two movements cancel each other.
        $rows = DB::table('journal_entry_lines as l')
            ->join('journal_entries as j', function ($join): void {
                $join->on('j.id', '=', 'l.journal_entry_id')->on('j.company_id', '=', 'l.company_id');
            })
            ->join('accounting_accounts as a', function ($join): void {
                $join->on('a.id', '=', 'l.accounting_account_id')->on('a.company_id', '=', 'l.company_id');
            })
            ->where('l.company_id', $companyId)
            ->where('j.company_id', $companyId)
            ->where('j.entry_date', '<=', $asOf)
            ->whereIn('j.status', ['POSTED', 'REVERSED'])
            ->groupBy('a.id', 'a.code', 'a.name', 'a.account_type', 'a.financial_statement_rubric')
            ->orderBy('a.code')
            ->select('a.id', 'a.code', 'a.name', 'a.account_type', 'a.financial_statement_rubric')
            ->selectRaw('SUM(l.debit_local) AS debit_local, SUM(l.credit_local) AS credit_local')
            ->selectRaw('SUM(l.debit_foreign) AS debit_foreign, SUM(l.credit_foreign) AS credit_foreign')
            ->selectRaw('SUM(CASE WHEN j.entry_date >= ? THEN l.debit_local ELSE 0 END) AS period_debit_local', [$yearStart])
            ->selectRaw('SUM(CASE WHEN j.entry_date >= ? THEN l.credit_local ELSE 0 END) AS period_credit_local', [$yearStart])
            ->get();

        $totals = array_fill_keys(['assets', 'liabilities', 'equity', 'result', 'unclassified', 'excluded', 'debit_local', 'credit_local'], '0.0000');
        $accounts = [];
        $income = ['revenue' => '0.0000', 'expenses' => '0.0000'];
        foreach ($rows as $row) {
            $debit = (string) $row->debit_local;
            $credit = (string) $row->credit_local;
            $debitForeign = (string) $row->debit_foreign;
            $creditForeign = (string) $row->credit_foreign;
            $totals['debit_local'] = bcadd($totals['debit_local'], $debit, 4);
            $totals['credit_local'] = bcadd($totals['credit_local'], $credit, 4);

            $section = match (true) {
                str_starts_with((string) $row->code, '9'),
                str_starts_with((string) $row->code, '79'),
                $row->account_type === 'MEMORANDUM' => 'excluded',
                $row->account_type === 'ASSET' => 'assets',
                $row->account_type === 'LIABILITY' => 'liabilities',
                $row->account_type === 'EQUITY' => 'equity',
                in_array($row->account_type, ['REVENUE', 'EXPENSE', 'COST'], true) => 'result',
                default => 'unclassified',
            };
            // Debit-normal assets/expenses are positive; credit-normal liabilities,
            // equity and revenue are positive. Keep contra accounts signed.
            $amount = in_array($section, ['assets', 'unclassified', 'excluded'], true)
                || in_array($row->account_type, ['EXPENSE', 'COST'], true)
                ? bcsub($debit, $credit, 4)
                : bcsub($credit, $debit, 4);
            // Current result is revenue minus expenses (including cost accounts).
            if ($section === 'result' && in_array($row->account_type, ['EXPENSE', 'COST'], true)) {
                $amount = bcsub($credit, $debit, 4);
            }
            $totals[$section] = bcadd($totals[$section], $amount, 4);
            $periodAmount = null;
            if ($section === 'result') {
                $periodAmount = $row->account_type === 'REVENUE'
                    ? bcsub((string) $row->period_credit_local, (string) $row->period_debit_local, 4)
                    : bcsub((string) $row->period_debit_local, (string) $row->period_credit_local, 4);
                $income[$row->account_type === 'REVENUE' ? 'revenue' : 'expenses'] = bcadd(
                    $income[$row->account_type === 'REVENUE' ? 'revenue' : 'expenses'], $periodAmount, 4
                );
            }
            $accounts[] = [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'account_type' => (string) $row->account_type,
                'rubric' => (string) $row->financial_statement_rubric,
                'section' => $section,
                'debit_local' => bcadd($debit, '0', 4),
                'credit_local' => bcadd($credit, '0', 4),
                'debit_foreign' => bcadd($debitForeign, '0', 4),
                'credit_foreign' => bcadd($creditForeign, '0', 4),
                'amount_local' => $amount,
                'period_amount_local' => $periodAmount,
            ];
        }

        $right = bcadd(bcadd($totals['liabilities'], $totals['equity'], 4), $totals['result'], 4);
        $difference = bcsub($totals['assets'], $right, 4);

        return response()->json(['data' => [
            'as_of' => $asOf,
            'currency' => 'PEN',
            'accounts' => $accounts,
            'totals' => array_merge($totals, [
                'liabilities_equity_result' => $right,
                'difference' => $difference,
                'trial_difference' => bcsub($totals['debit_local'], $totals['credit_local'], 4),
            ]),
            'income_statement' => [
                'from' => $yearStart,
                'to' => $asOf,
                'revenue' => $income['revenue'],
                'expenses' => $income['expenses'],
                'net' => bcsub($income['revenue'], $income['expenses'], 4),
            ],
            'is_preliminary' => true,
        ]]);
    }
}
