<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Models\Entity;
use App\Http\Controllers\Api\V1\Company\EntityMasterController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint para listar y filtrar el Directorio Maestro de Terceros / Anexos.
 */
final readonly class ListEntitiesController
{
    public function __invoke(Request $request): JsonResponse
    {
        // Never leak banking or detraction numbers through the directory API.
        $query = Entity::query()
            ->select(EntityMasterController::PUBLIC_FIELDS)
            ->whereRaw('entities.is_active IS TRUE');

        if ($request->boolean('is_client')) {
            $query->whereRaw('entities.is_client IS TRUE');
        }

        if ($request->boolean('is_supplier')) {
            $query->whereRaw('entities.is_supplier IS TRUE');
        }

        if ($request->boolean('is_employee')) {
            $query->whereRaw('entities.is_employee IS TRUE');
        }

        if ($request->boolean('is_financial_institution')) {
            $query->whereRaw('entities.is_financial_institution IS TRUE');
        }

        if ($request->boolean('is_shareholder')) {
            $query->whereRaw('entities.is_shareholder IS TRUE');
        }

        if ($request->boolean('is_tax_authority')) {
            $query->whereRaw('entities.is_tax_authority IS TRUE');
        }

        $request->validate(['search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,100']]);

        if ($search = $request->query('search')) {
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], trim((string) $search));
            $query->where(function ($q) use ($term): void {
                $q->where('tax_id', 'ilike', "%{$term}%")
                  ->orWhere('legal_name', 'ilike', "%{$term}%")
                  ->orWhere('commercial_name', 'ilike', "%{$term}%");
            });
        }

        $entities = $query
            ->orderBy('legal_name')
            ->paginate($request->integer('per_page', 50));

        return response()->json($entities);
    }
}
