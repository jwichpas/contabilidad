<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Support\Tenant\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final readonly class CashFlowReportService
{
    public function __construct(private CashFlowCalculator $calculator) {}

    /** @return array<string, mixed> */
    public function get(string $from, string $to, string $section = 'ALL', ?int $areaId = null, int $page = 1): array
    {
        $companyId = app(TenantContext::class)->requireCompanyId(self::class);
        $settings = DB::table('company_settings as s')
            ->join('currencies as c', 'c.id', '=', 's.base_currency_id')
            ->where('s.company_id', $companyId)->where('c.is_active', true)
            ->first(['c.code as currency', 's.fx_gain_account_id', 's.fx_loss_account_id']);
        if ($settings === null) {
            throw new ConflictHttpException('Configure la moneda funcional de la empresa antes de consultar el flujo de efectivo.');
        }

        // One SELECT/cursor gives opening, closing, classifications and the detail
        // page the SAME PostgreSQL statement snapshot, including concurrent posts.
        // No active-master filter: historical accounts/areas must remain visible.
        $rows = DB::table('journal_entry_lines as l')
            ->join('journal_entries as j', function ($join): void {
                $join->on('j.id', '=', 'l.journal_entry_id')->on('j.company_id', '=', 'l.company_id');
            })
            ->join('accounting_accounts as a', function ($join): void {
                $join->on('a.id', '=', 'l.accounting_account_id')->on('a.company_id', '=', 'l.company_id');
            })
            ->leftJoin('cash_flow_areas as f', function ($join): void {
                $join->on('f.id', '=', 'l.cash_flow_area_id')->on('f.company_id', '=', 'l.company_id');
            })
            ->leftJoin('journal_entries as original', function ($join): void {
                $join->on('original.id', '=', 'j.reversed_entry_id')->on('original.company_id', '=', 'j.company_id');
            })
            ->where('l.company_id', $companyId)->where('j.company_id', $companyId)
            ->where('j.entry_date', '<=', $to)->whereIn('j.status', ['POSTED', 'REVERSED'])
            ->whereExists(function (Builder $query) use ($companyId): void {
                $query->selectRaw('1')->from('journal_entry_lines as cl')
                    ->join('accounting_accounts as ca', function ($join): void {
                        $join->on('ca.id', '=', 'cl.accounting_account_id')->on('ca.company_id', '=', 'cl.company_id');
                    })
                    ->whereColumn('cl.journal_entry_id', 'j.id')->where('cl.company_id', $companyId)
                    ->where(function (Builder $cash): void {
                        $cash->where('ca.financial_statement_rubric', 'ESF_ACT_CORR_EFE')
                            ->orWhere('ca.cash_flow_category', 'CASH_EQUIVALENT')
                            ->orWhere(function (Builder $candidate): void {
                                $candidate->where('ca.code', 'like', '10%')->where('ca.financial_statement_rubric', 'NONE');
                            });
                    });
            })
            ->select([
                'l.id as line_id', 'j.id as entry_id', 'j.entry_date', 'j.entry_number', 'j.entry_type', 'j.status',
                'original.entry_type as original_entry_type', 'a.id as account_id', 'a.code as account_code', 'a.name as account_name',
                'a.account_type', 'a.financial_statement_rubric as rubric', 'a.cash_flow_category',
                'l.debit_local', 'l.credit_local', 'l.debit_foreign', 'l.credit_foreign',
                'f.id as area_id', 'f.code as area_code', 'f.name as area_name', 'f.activity_category', 'f.flow_direction',
            ])
            ->selectRaw('COALESCE(l.description, j.description) AS description')
            ->orderBy('j.entry_date')->orderBy('j.id')->orderBy('l.line_number')->orderBy('l.id')
            ->cursor();
        $fxIds = array_values(array_map('intval', array_filter([$settings->fx_gain_account_id, $settings->fx_loss_account_id])));
        return ['currency' => (string) $settings->currency] + $this->calculator->calculate($rows, $from, $to, $fxIds, $section, $areaId, $page);
    }
}
