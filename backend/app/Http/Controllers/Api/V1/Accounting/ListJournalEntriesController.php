<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Models\JournalEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Retorna el listado cronológico de asientos contables del libro diario
 * de la empresa activa con sus líneas y cuentas contables imputadas.
 */
final readonly class ListJournalEntriesController
{
    public function __invoke(Request $request): JsonResponse
    {
        $limit = min((int) $request->query('limit', 50), 100);

        $query = JournalEntry::query()
            ->with([
                'lines' => static function ($q): void {
                    $q->orderBy('line_number')->with(['account:id,code,name', 'costCenter:id,code,name']);
                },
                'fiscalPeriod:id,year,month,status',
            ])
            ->orderByDesc('entry_date')
            ->orderByDesc('id');

        if ($request->has('year') && $request->has('month')) {
            $year = (int) $request->query('year');
            $month = (int) $request->query('month');
            $query->whereHas('fiscalPeriod', static function ($fpQuery) use ($year, $month): void {
                $fpQuery->where('year', $year)->where('month', $month);
            });
        }

        if ($request->has('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        $entries = $query->take($limit)->get();

        return response()->json([
            'data' => $entries,
        ]);
    }
}
