<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalogs;

use App\Models\FinancialStatementRubricModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint para listar los Rubros Oficiales de Estados Financieros (SMV / NIIF 18 / PCGE).
 */
final readonly class ListFinancialStatementRubricsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = FinancialStatementRubricModel::query()->where('is_active', true);

        if ($statementType = $request->query('statement_type')) {
            $query->where('statement_type', strtoupper((string) $statementType));
        }

        if ($groupName = $request->query('group_name')) {
            $query->where('group_name', strtoupper((string) $groupName));
        }

        $rubrics = $query
            ->orderBy('presentation_order')
            ->get();

        return response()->json([
            'data' => $rubrics,
            'meta' => [
                'framework' => 'NIIF 18 / SMV / PCGE 2026',
                'total' => $rubrics->count(),
            ],
        ]);
    }
}
