<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Models\CashFlowArea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint para listar las áreas de flujo de efectivo para clasificación NIC 7.
 */
final readonly class ListCashFlowAreasController
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = CashFlowArea::query()->whereRaw('cash_flow_areas.is_active IS TRUE');

        if ($category = $request->query('activity_category')) {
            $query->where('activity_category', strtoupper((string) $category));
        }

        $areas = $query
            ->orderBy('code')
            ->get();

        return response()->json([
            'data' => $areas,
        ]);
    }
}
