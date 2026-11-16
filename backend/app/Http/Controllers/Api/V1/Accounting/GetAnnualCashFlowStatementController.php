<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Services\Accounting\CashFlowReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NIC 7 - matriz anual de meses y saldos. No equivale por si misma a un
 * fichero SUNAT/P​​LE Formato 3.18 validado ni inventa desgloses por glosas.
 */
final readonly class GetAnnualCashFlowStatementController
{
    public function __invoke(Request $request, CashFlowReportService $report): JsonResponse
    {
        $input = $request->validate(['year' => ['required', 'integer', 'between:2000,2100']]);
        $year = (int) $input['year'];
        $data = $report->get(
            sprintf('%04d-01-01', $year),
            sprintf('%04d-12-31', $year),
            'ALL', null, 1, true
        );

        return response()->json(['data' => ['year' => $year] + $data])
            ->header('Cache-Control', 'private, no-store');
    }
}
