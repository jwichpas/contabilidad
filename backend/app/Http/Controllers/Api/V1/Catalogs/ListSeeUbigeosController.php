<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalogs;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class ListSeeUbigeosController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'department' => ['sometimes', 'string', 'max:160'],
            'province' => ['sometimes', 'string', 'max:160'],
            'version' => ['sometimes', 'string', 'max:40', 'alpha_dash'],
            // La edición INEI 2026 cabe en una carga de solo lectura.
            'per_page' => ['sometimes', 'integer', 'between:1,2000'],
        ]);
        $versions = DB::table('see_ubigeo_versions')->orderByDesc('version')->get();
        $version = $validated['version'] ?? 'latest';
        if ($version === 'latest') {
            $version = $versions->first()->version ?? 'historical';
        }
        $reference = $version === 'historical' ? null : $versions->firstWhere('version', $version);
        abort_if($version !== 'historical' && $reference === null, 404, 'Versión INEI desconocida.');
        $query = DB::table($version === 'historical' ? 'see_ubigeos' : 'see_ubigeo_version_items');
        if ($version !== 'historical') {
            $query->where('version', $version);
        }
        if (! empty($validated['department'])) {
            $query->where('department', $validated['department']);
        }
        if (! empty($validated['province'])) {
            $query->where('province', $validated['province']);
        }
        if (! empty($validated['q'])) {
            $q = mb_strtolower($validated['q']);
            $query->where(function ($sub) use ($q): void {
                $sub->where('code', 'like', '%'.$q.'%')
                    ->orWhereRaw('lower(district) LIKE ?', ['%'.$q.'%']);
            });
        }
        $page = $query->orderBy('code')->paginate((int) ($validated['per_page'] ?? 50));

        return response()->json([
            'data' => $page->getCollection()->map(static fn ($item): array => [
                'code' => $item->code, 'district' => $item->district,
                'province' => $item->province, 'department' => $item->department,
                'population' => $item->population ?? null,
                'area_km2' => $item->area_km2 ?? null,
                'latitude' => $item->latitude ?? null,
                'longitude' => $item->longitude ?? null,
            ]),
            'meta' => [
                'classification' => $reference->classification ?? 'SUPPLIED_INEI_SNAPSHOT_UNVERIFIED',
                'version' => $version,
                'source_url' => $reference->source_url ?? null,
                'source_sha256' => $reference->source_sha256 ?? null,
                'item_count' => $reference->item_count ?? 1874,
                'historical_item_count' => 1874,
                'available_versions' => $versions->map(static fn ($edition): array => [
                    'version' => $edition->version,
                    'item_count' => $edition->item_count,
                    'source_url' => $edition->source_url,
                ])->values(),
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }
}
