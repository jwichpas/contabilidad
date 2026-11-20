<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Services\Accounting\PeriodLedgerService;
use App\Services\Accounting\TrialBalanceCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class GetTrialBalanceController
{
    public function __invoke(Request $request, PeriodLedgerService $ledger, TrialBalanceCalculator $calculator): JsonResponse
    {
        $input = $request->validate([
            'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:2100-12-31'],
        ]);
        $source = $ledger->read($input['from'], $input['to']);
        return response()->json(['data' => [
            'from' => $input['from'], 'to' => $input['to'], 'currency' => $source['currency'],
        ] + $calculator->calculate($source['accounts'])])->header('Cache-Control','private, no-store');
    }
}
