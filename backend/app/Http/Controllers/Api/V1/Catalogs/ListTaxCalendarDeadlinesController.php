<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalogs;

use App\Services\Accounting\SunatCalendar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class ListTaxCalendarDeadlinesController
{
    public function __invoke(Request $request, SunatCalendar $calendar, int $year = 2026): JsonResponse
    {
        $input = $request->validate([
            'month' => ['sometimes', 'integer', 'between:1,12'],
            'last_digit_ruc' => ['sometimes', 'regex:/^(?:[0-9]|BC)$/iD'],
        ]);
        $edition = $calendar->edition($year);
        $rows = [];
        if ($edition) {
            $digits = isset($input['last_digit_ruc']) ? [strtoupper($input['last_digit_ruc'])]
                : [...array_map('strval', range(0, 9)), 'BC'];
            $months = isset($input['month']) ? [(int) $input['month']] : range(1, 12);
            foreach ($months as $month) {
                foreach ($digits as $digit) {
                    $group = $calendar->group($digit);
                    $rows[] = [
                        'fiscal_year' => $year, 'fiscal_month' => $month, 'last_digit_ruc' => $digit,
                        'electronic_books_deadline' => $edition['books'][$month][$group],
                        'tax_returns_deadline' => $edition['tax'][$month][$group],
                        'legal_resolution' => SunatCalendar::RESOLUTION,
                    ];
                }
            }
        }

        return response()->json([
            'data' => $rows,
            'meta' => [
                'fiscal_year' => $year, 'total' => count($rows),
                'tax_source' => $edition['tax_source'] ?? null, 'books_source' => $edition['books_source'] ?? null,
            ],
        ]);
    }
}
