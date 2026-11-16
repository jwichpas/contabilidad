<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Services\Accounting\CashFlowReportService;
use App\Services\Accounting\IndirectCashFlowCalculator;
use App\Services\Accounting\PeriodLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class GetIndirectCashFlowController
{
    public function __invoke(
        Request $request,
        PeriodLedgerService $ledger,
        CashFlowReportService $direct,
        IndirectCashFlowCalculator $calculator
    ): JsonResponse {
        $input = $request->validate([
            'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:2100-12-31'],
        ]);
        $source = $ledger->read($input['from'], $input['to']);
        $directData = $direct->get($input['from'], $input['to']);
        return response()->json(['data' => [
            'from' => $input['from'], 'to' => $input['to'], 'currency' => $source['currency'],
            'method' => 'INDIRECT',
        ] + $calculator->calculate($source['accounts'], $directData)])
            ->header('Cache-Control', 'private, no-store');
    }
}
