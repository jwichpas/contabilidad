<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Models\FiscalPeriod;
use App\Services\Accounting\JournalEntryNumberService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Solo PREVISUALIZA el siguiente correlativo.
 * El POST lo calcula bajo FOR UPDATE del periodo, dentro de su transaccion.
 */
final readonly class GetNextJournalEntryNumberController
{
    public function __construct(
        private JournalEntryNumberService $entryNumbers,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $date = isset($validated['date'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['date'])
            : CarbonImmutable::now();
        if ($date === false) {
            throw ValidationException::withMessages([
                'date' => ['Fecha contable invalida.'],
            ]);
        }

        $month = $date->month;
        $year = $date->year;

        $period = FiscalPeriod::query()
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        $nextNumber = $period !== null
            ? $this->entryNumbers->nextForPeriod(
                (int) $period->company_id, (int) $period->id, $month
            )
            : $this->entryNumbers->firstForMonth($month);

        return response()->json([
            'data' => [
                'next_number' => $nextNumber,
                'period_year' => $year,
                'period_month' => $month,
                'is_period_open' => $period?->isOpen() ?? true,
                'is_reserved' => false,
            ],
        ]);
    }
}
