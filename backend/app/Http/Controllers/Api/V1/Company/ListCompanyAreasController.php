<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Models\CompanyArea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint para listar las áreas organizacionales de la empresa activa.
 */
final readonly class ListCompanyAreasController
{
    public function __invoke(Request $request): JsonResponse
    {
        $areas = CompanyArea::query()
            ->whereRaw('company_areas.is_active IS TRUE')
            ->orderBy('code')
            ->get();

        return response()->json([
            'data' => $areas,
        ]);
    }
}
