<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Models\Entity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint para listar y filtrar el Directorio Maestro de Terceros / Anexos.
 */
final readonly class ListEntitiesController
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = Entity::query()->where('is_active', true);

        if ($request->boolean('is_client')) {
            $query->where('is_client', true);
        }

        if ($request->boolean('is_supplier')) {
            $query->where('is_supplier', true);
        }

        if ($request->boolean('is_employee')) {
            $query->where('is_employee', true);
        }

        if ($request->boolean('is_financial_institution')) {
            $query->where('is_financial_institution', true);
        }

        if ($request->boolean('is_tax_authority')) {
            $query->where('is_tax_authority', true);
        }

        if ($search = $request->query('search')) {
            $term = trim((string) $search);
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
