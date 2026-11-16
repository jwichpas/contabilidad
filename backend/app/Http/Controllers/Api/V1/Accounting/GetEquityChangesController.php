<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Services\Accounting\EquityChangesCalculator;
use App\Services\Accounting\PeriodLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class GetEquityChangesController
{
    public function __invoke(Request $request, PeriodLedgerService $ledger, EquityChangesCalculator $calculator): JsonResponse
    {
        $input = $request->validate([
            'as_of' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
        ]);
        $from = substr($input['as_of'],0,4).'-01-01';
        $source = $ledger->read($from, $input['as_of']);
        return response()->json(['data' => [
            'from' => $from, 'to' => $input['as_of'], 'currency' => $source['currency'],
        ] + $calculator->calculate($source['accounts'])])->header('Cache-Control','private, no-store');
    }
}
