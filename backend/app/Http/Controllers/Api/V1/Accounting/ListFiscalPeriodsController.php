<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Models\FiscalPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only fiscal-period board for the active company (TenantScope + FORCE RLS).
 * No implicit INSERT: periods appear only when explicitly created by a
 * journal posting. Returned amounts are counts, not estimated balances.
 */
final readonly class ListFiscalPeriodsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated=$request->validate([
            'year'=>['sometimes','required','integer','between:2000,2100'],
        ]);
        $year=(int)($validated['year']??now()->year);

        $periods=FiscalPeriod::query()
            ->where('year',$year)
            ->orderBy('month')
            ->get(['id','company_id','year','month','start_date','end_date',
                'status','closed_at','created_at','updated_at']);

        $companyId=(int)app(\App\Support\Tenant\TenantContext::class)->requireCompanyId();
        $counts=DB::table('journal_entries')
            ->where('company_id',$companyId)
            ->whereIn('fiscal_period_id',$periods->pluck('id'))
            ->selectRaw('fiscal_period_id, COUNT(*)::integer AS entry_count')
            ->groupBy('fiscal_period_id')
            ->pluck('entry_count','fiscal_period_id');

        return response()->json([
            'data'=>$periods->map(static fn(FiscalPeriod $period): array => [
                'id'=>(int)$period->id,
                'year'=>(int)$period->year,
                'month'=>(int)$period->month,
                'start_date'=>$period->start_date->toDateString(),
                'end_date'=>$period->end_date->toDateString(),
                'status'=>(string)$period->status,
                'closed_at'=>$period->closed_at?->toIso8601String(),
                'entry_count'=>(int)($counts[(int)$period->id]??0),
            ])->values(),
            'year'=>$year,
        ]);
    }
}
