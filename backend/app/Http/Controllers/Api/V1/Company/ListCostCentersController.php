<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Models\CostCenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint para listar los centros de costos con su amarre automático a Cuentas 94 / 95 y 79.
 */
final readonly class ListCostCentersController
{
    public function __invoke(Request $request): JsonResponse
    {
        $costCenters = CostCenter::query()
            ->with(['destinationDebitAccount', 'destinationCreditAccount'])
            ->whereRaw('cost_centers.is_active IS TRUE')
            ->orderBy('code')
            ->get();

        return response()->json([
            'data' => $costCenters,
        ]);
    }
}
