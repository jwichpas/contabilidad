<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Services\Accounting\CashFlowReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NIC 7 - matriz anual de meses y saldos. No equivale por si misma a un
 * fichero SUNAT/PLE Formato 3.18 validado ni inventa desgloses por glosas.
 */
final readonly class GetAnnualCashFlowStatementController
{
    public function __invoke(Request $request, CashFlowReportService $report): JsonResponse
    {
        $input = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'through_month' => ['sometimes', 'integer', 'between:1,12'],
        ]);
        $year = (int) $input['year'];
        $month = (int) ($input['through_month'] ?? 12);
        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $to = $monthStart->modify('last day of this month')->format('Y-m-d');
        $data = $report->get(
            sprintf('%04d-01-01', $year), $to, 'ALL', null, 1, true
        );

        return response()->json(['data' => ['year' => $year, 'through_month' => $month] + $data])
            ->header('Cache-Control', 'private, no-store');
    }
}
