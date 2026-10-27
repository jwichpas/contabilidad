<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Models\AccountingAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Paginated general ledger with a stable opening and running debit balance. */
final readonly class GetGeneralLedgerController
{
    public function __invoke(Request $request): JsonResponse
    {
        $input = $request->validate([
            'account_id' => ['required', 'integer', 'min:1'],
            'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:2100-12-31'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $companyId = (int) $request->header('X-Company-Id', $request->user()->current_company_id);
        $account = AccountingAccount::query()
            ->where('company_id', $companyId)
            ->findOrFail((int) $input['account_id'], ['id', 'code', 'name']);

        $base = static function () use ($companyId, $account) {
            return DB::table('journal_entry_lines as l')
                ->join('journal_entries as j', function ($join): void {
                    $join->on('j.id', '=', 'l.journal_entry_id')->on('j.company_id', '=', 'l.company_id');
                })
                ->where('l.company_id', $companyId)
                ->where('j.company_id', $companyId)
                ->where('l.accounting_account_id', $account->id)
                // The original REVERSED entry and its reversal must both count.
                ->whereIn('j.status', ['POSTED', 'REVERSED']);
        };

        $opening = $base()->where('j.entry_date', '<', $input['from'])
            ->selectRaw('COALESCE(SUM(l.debit_local - l.credit_local), 0) AS local_balance')
            ->selectRaw('COALESCE(SUM(l.debit_foreign - l.credit_foreign), 0) AS foreign_balance')
            ->first();
        $openingLocal = bcadd((string) $opening->local_balance, '0', 4);
        $openingForeign = bcadd((string) $opening->foreign_balance, '0', 4);

        // Windows run on the complete selected date range before LIMIT/OFFSET,
        // so page two continues exactly where page one ended.
        $page = $base()
            ->whereBetween('j.entry_date', [$input['from'], $input['to']])
            ->select('l.id', 'l.line_number', 'j.id as entry_id', 'j.entry_date', 'j.entry_number', 'j.status')
            ->selectRaw('COALESCE(l.description, j.description) AS description')
            ->selectRaw('l.debit_local, l.credit_local, l.debit_foreign, l.credit_foreign')
            ->selectRaw('SUM(l.debit_local - l.credit_local) OVER (ORDER BY j.entry_date, j.id, l.line_number, l.id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS running_local')
            ->selectRaw('SUM(l.debit_foreign - l.credit_foreign) OVER (ORDER BY j.entry_date, j.id, l.line_number, l.id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS running_foreign')
            ->orderBy('j.entry_date')->orderBy('j.id')->orderBy('l.line_number')->orderBy('l.id')
            ->paginate(50, ['*'], 'page', (int) ($input['page'] ?? 1));

        return response()->json(['data' => [
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name],
            'from' => $input['from'],
            'to' => $input['to'],
            'opening_local' => $openingLocal,
            'opening_foreign' => $openingForeign,
            'rows' => $page->getCollection()->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'entry_id' => (int) $row->entry_id,
                'entry_date' => (string) $row->entry_date,
                'entry_number' => (string) $row->entry_number,
                'description' => (string) $row->description,
                'status' => (string) $row->status,
                'debit_local' => bcadd((string) $row->debit_local, '0', 4),
                'credit_local' => bcadd((string) $row->credit_local, '0', 4),
                'debit_foreign' => bcadd((string) $row->debit_foreign, '0', 4),
                'credit_foreign' => bcadd((string) $row->credit_foreign, '0', 4),
                'balance_local' => bcadd($openingLocal, (string) $row->running_local, 4),
                'balance_foreign' => bcadd($openingForeign, (string) $row->running_foreign, 4),
            ])->all(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
        ]]);
    }
}
