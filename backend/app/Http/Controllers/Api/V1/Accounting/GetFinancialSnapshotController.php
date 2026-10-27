<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Support\Tenant\TenantContext;
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
        // Middleware SetTenantContext already checked active membership and RLS.
        $companyId = app(TenantContext::class)->requireCompanyId(self::class);
        $currency = DB::table('company_settings as s')
            ->join('currencies as c', 'c.id', '=', 's.base_currency_id')
            ->where('s.company_id', $companyId)
            ->where('c.is_active', true)
            ->value('c.code');

        if (! is_string($currency) || $currency === '') {
            return response()->json([
                'message' => 'Configure la moneda funcional de la empresa antes de consultar estados financieros.',
            ], 409);
        }

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

        // Presentation rubrics are global, versioned reference metadata; amounts
        // continue to come exclusively from this tenant's posted journal lines.
        // Keep account-level detail for the Libro Mayor drill-down.
        $analyticStatuses = DB::table('accounting_destination_rule_versions')
            ->where('company_id', $companyId)->pluck('status')->all();
        $analyticRoutingStatus = in_array('ACTIVE', $analyticStatuses, true)
            ? 'ACTIVE'
            : (in_array('DRAFT', $analyticStatuses, true) ? 'DRAFT' : 'NOT_CONFIGURED');

        $rubricDefinitions = DB::table('financial_statement_rubrics')
            ->where('is_active', true)
            ->get(['code', 'name', 'group_name', 'presentation_order'])
            ->keyBy('code');
        $rubricTotals = [];
        $unmappedAccountIds = [];
        foreach ($accounts as $account) {
            if (! in_array($account['section'], ['assets', 'liabilities', 'equity', 'result'], true)) {
                continue;
            }

            $rubricCode = $account['rubric'];
            $definition = $rubricDefinitions->get($rubricCode);
            if ($rubricCode === 'NONE' || $definition === null) {
                $unmappedAccountIds[] = $account['id'];
            }

            $key = $account['section'].'|'.$rubricCode;
            if (! isset($rubricTotals[$key])) {
                $rubricTotals[$key] = [
                    'code' => $rubricCode,
                    'name' => $definition === null ? 'Sin rubro configurado' : (string) $definition->name,
                    'group_name' => $definition === null ? 'SIN_CLASIFICAR' : (string) $definition->group_name,
                    'section' => $account['section'],
                    'presentation_order' => $definition === null ? 32767 : (int) $definition->presentation_order,
                    'amount_local' => '0.0000',
                    'period_amount_local' => '0.0000',
                    'account_ids' => [],
                ];
            }

            $rubricTotals[$key]['amount_local'] = bcadd(
                $rubricTotals[$key]['amount_local'], $account['amount_local'], 4
            );
            if ($account['period_amount_local'] !== null) {
                // period_amount_local is debit-positive for expenses and
                // credit-positive for revenue; it is not the ESF signed result.
                $rubricTotals[$key]['period_amount_local'] = bcadd(
                    $rubricTotals[$key]['period_amount_local'], $account['period_amount_local'], 4
                );
            }
            $rubricTotals[$key]['account_ids'][] = $account['id'];
        }
        $rubrics = array_values($rubricTotals);
        usort($rubrics, static function (array $a, array $b): int {
            $sections = ['assets' => 0, 'liabilities' => 1, 'equity' => 2, 'result' => 3];
            return ($sections[$a['section']] <=> $sections[$b['section']])
                ?: ($a['presentation_order'] <=> $b['presentation_order'])
                ?: strcmp($a['code'], $b['code']);
        });

        $right = bcadd(bcadd($totals['liabilities'], $totals['equity'], 4), $totals['result'], 4);
        $difference = bcsub($totals['assets'], $right, 4);

        return response()->json(['data' => [
            'as_of' => $asOf,
            'currency' => $currency,
            'accounts' => $accounts,
            'rubrics' => $rubrics,
            'unmapped_account_ids' => $unmappedAccountIds,
            'analytic_routing_status' => $analyticRoutingStatus,
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
