<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Precalcula el siguiente correlativo contable para la fecha indicada
 * en base al periodo fiscal correspondiente.
 */
final readonly class GetNextJournalEntryNumberController
{
    public function __invoke(Request $request): JsonResponse
    {
        $dateStr = (string) $request->query('date', CarbonImmutable::now()->toDateString());
        $date = CarbonImmutable::parse($dateStr);
        $month = $date->month;
        $year = $date->year;

        $period = FiscalPeriod::query()
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        $count = 0;
        if ($period !== null) {
            $count = JournalEntry::query()
                ->where('fiscal_period_id', $period->id)
                ->count();
        }

        $nextNumber = sprintf('%02d-%05d', $month, $count + 1);

        return response()->json([
            'data' => [
                'next_number' => $nextNumber,
                'period_year' => $year,
                'period_month' => $month,
                'is_period_open' => $period ? $period->isOpen() : true,
            ],
        ]);
    }
}
