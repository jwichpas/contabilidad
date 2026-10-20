<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalogs;

use App\Models\TaxCalendarDeadline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint para consultar el Cronograma Oficial de Vencimientos SUNAT (SIRE / PLE y PDT 621).
 */
final readonly class ListTaxCalendarDeadlinesController
{
    public function __invoke(Request $request, int $year = 2026): JsonResponse
    {
        $query = TaxCalendarDeadline::query()
            ->where('fiscal_year', $year);

        if ($month = $request->query('month')) {
            $query->where('fiscal_month', (int) $month);
        }

        if ($digit = $request->query('last_digit_ruc')) {
            $query->where('last_digit_ruc', strtoupper((string) $digit));
        }

        $deadlines = $query
            ->orderBy('fiscal_month')
            ->orderBy('last_digit_ruc')
            ->get();

        return response()->json([
            'data' => $deadlines,
            'meta' => [
                'fiscal_year' => $year,
                'total' => $deadlines->count(),
            ],
        ]);
    }
}
