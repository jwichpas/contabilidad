<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Services\Accounting\CashFlowCalculator;
use App\Services\Accounting\CashFlowReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final readonly class GetCashFlowStatementController
{
    public function __invoke(Request $request, CashFlowReportService $report): JsonResponse
    {
        $input = $request->validate([
            'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:2100-12-31'],
            'section' => ['sometimes', 'string', Rule::in(['ALL', ...CashFlowCalculator::SECTIONS])],
            'area_id' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
        ]);
        return response()->json(['data' => $report->get(
            $input['from'], $input['to'], $input['section'] ?? 'ALL',
            isset($input['area_id']) ? (int) $input['area_id'] : null, (int) ($input['page'] ?? 1),
        )])->header('Cache-Control', 'private, no-store');
    }
}
