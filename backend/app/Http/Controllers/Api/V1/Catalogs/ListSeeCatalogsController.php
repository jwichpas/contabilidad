<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalogs;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final readonly class ListSeeCatalogsController
{
    public function __invoke(): JsonResponse
    {
        $versions = DB::table('see_catalog_versions')
            ->select('catalog_number', 'version', 'name', 'item_count', 'classification', 'source_url')
            ->orderByDesc('version')->get()->groupBy('catalog_number');
        $catalogs = DB::table('see_catalogs')
            ->select('number', 'name', 'item_count', 'classification')
            ->orderBy('number')->get()->map(static function ($catalog) use ($versions): array {
                $available = $versions->get($catalog->number, collect());
                $latest = $available->first();

                return [
                    'number' => $catalog->number,
                    'name' => $catalog->name,
                    'item_count' => $latest->item_count ?? $catalog->item_count,
                    'classification' => $latest->classification ?? $catalog->classification,
                    'latest_version' => $latest->version ?? 'historical',
                    'historical_item_count' => $catalog->item_count,
                    'available_versions' => $available->values()->all(),
                ];
            });

        return response()->json(['data' => $catalogs]);
    }
}
