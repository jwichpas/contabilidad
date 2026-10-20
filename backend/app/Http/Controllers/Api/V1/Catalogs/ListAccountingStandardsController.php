<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalogs;

use App\Models\AccountingStandard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint para listar las Normas Contables (NIIF y NIC vigentes según CNC N.° 003-2026-EF/30).
 */
final readonly class ListAccountingStandardsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = AccountingStandard::query()->whereRaw('accounting_standards.is_active IS TRUE');

        if ($type = $request->query('type')) {
            $query->where('standard_type', strtoupper((string) $type));
        }

        if ($status = $request->query('status')) {
            $query->where('status', strtoupper((string) $status));
        }

        $standards = $query
            ->orderBy('standard_type')
            ->orderBy('standard_number')
            ->get();

        return response()->json([
            'data' => $standards,
            'meta' => [
                'legal_base' => 'Resolución N.° 003-2026-EF/30 CNC',
                'fiscal_year' => 2026,
                'total' => $standards->count(),
            ],
        ]);
    }
}
