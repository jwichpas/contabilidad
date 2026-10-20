<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalogs;

use App\Models\Sunat\SunatCustomsDependency;
use App\Models\Sunat\SunatElectronicBook;
use App\Models\Sunat\SunatElectronicDocumentType;
use App\Models\Sunat\SunatFinancialInstitution;
use App\Models\Sunat\SunatIdentityDocumentType;
use App\Models\Sunat\SunatInventoryType;
use App\Models\Sunat\SunatMeasurementUnit;
use App\Models\Sunat\SunatOperationType;
use App\Models\Sunat\SunatPaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Endpoint para consultar los catálogos oficiales de tablas SUNAT para libros y comprobantes.
 */
final readonly class ListSunatCatalogController
{
    public function __invoke(Request $request, string $tableNumber): JsonResponse
    {
        $normalized = str_pad(ltrim($tableNumber, '0'), 2, '0', STR_PAD_LEFT);

        $modelClass = match ($normalized) {
            '01' => SunatPaymentMethod::class,
            '02' => SunatIdentityDocumentType::class,
            '03' => SunatFinancialInstitution::class,
            '05' => SunatInventoryType::class,
            '06' => SunatMeasurementUnit::class,
            '08' => SunatElectronicBook::class,
            '10' => SunatElectronicDocumentType::class,
            '11' => SunatCustomsDependency::class,
            '12' => SunatOperationType::class,
            default => throw new NotFoundHttpException("La tabla SUNAT '{$tableNumber}' no está registrada en el sistema."),
        };

        $items = $modelClass::query()
            ->whereRaw('is_active IS TRUE')
            ->orderBy('code')
            ->get();

        return response()->json([
            'data' => $items,
            'meta' => [
                'table_number' => $normalized,
                'total' => $items->count(),
            ],
        ]);
    }
}
