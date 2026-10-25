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
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Global PLE/SIRE references; SEE catalogs have separate routes and storage. */
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

        if ($normalized === '01') {
            $version = $request->query('version', 'latest');
            if (! is_string($version) || ! in_array($version, ['latest', '2015-06-anexo-3', 'legacy-seed'], true)) {
                throw new NotFoundHttpException('Edición PLE 01 no registrada.');
            }
            $resolved = $version === 'latest' ? '2015-06-anexo-3' : $version;
            $edition = DB::table('ple_payment_method_versions')->where('version', $resolved)->first();
            if ($edition === null) {
                throw new NotFoundHttpException('Edición PLE 01 no instalada.');
            }
            $items = DB::table('ple_payment_method_version_items')->where('version', $resolved)
                ->where('is_active', true)->orderBy('code')->get()
                ->map(static function (object $row): array {
                    unset($row->version);
                    return (array) $row;
                });

            return response()->json([
                'data' => $items,
                'meta' => [
                    'table_number' => '01', 'total' => $items->count(), 'version' => $resolved,
                    'classification' => $edition->classification,
                    'source_url' => $edition->source_url,
                    'available_versions' => ['2015-06-anexo-3', 'legacy-seed'],
                ],
            ]);
        }

        if ($request->has('version')) {
            throw new NotFoundHttpException('Esta tabla aún no dispone de ediciones consultables.');
        }
        $items = $modelClass::query()->whereRaw('is_active IS TRUE')->orderBy('code')->get();

        return response()->json([
            'data' => $items,
            'meta' => ['table_number' => $normalized, 'total' => $items->count()],
        ]);
    }
}
