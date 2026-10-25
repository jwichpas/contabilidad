<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalogs;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Global SEE references, independent of /catalogs/sunat/{tableNumber} (PLE/SIRE). */
final readonly class ListSeeCatalogController
{
    public function __invoke(Request $request, string $number): JsonResponse
    {
        abort_unless(preg_match('/^\d{2}$/D', $number), 404);
        $catalog = DB::table('see_catalogs')->where('number', $number)->first();
        abort_if($catalog === null, 404);

        $validated = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'version' => ['sometimes', 'string', 'max:40', 'alpha_dash'],
            // La consulta global se descarga una vez y se filtra localmente en el navegador.
            'per_page' => ['sometimes', 'integer', 'between:1,2000'],
        ]);
        $latest = DB::table('see_catalog_versions')->where('catalog_number', $number)
            ->orderByDesc('version')->first();
        $version = $validated['version'] ?? 'latest';
        if ($version === 'latest') {
            $version = $latest->version ?? 'historical';
        }
        $reference = $version === 'historical' ? $catalog : DB::table('see_catalog_versions')
            ->where('catalog_number', $number)->where('version', $version)->first();
        abort_if($reference === null, 404, 'Versión SEE desconocida para este catálogo.');
        $query = DB::table($version === 'historical' ? 'see_catalog_items' : 'see_catalog_version_items')
            ->where('catalog_number', $number);
        if ($version !== 'historical') {
            $query->where('version', $version);
        }
        if (isset($validated['q']) && $validated['q'] !== '') {
            $q = mb_strtolower($validated['q']);
            $query->where(function ($sub) use ($q): void {
                $sub->whereRaw('lower(code) LIKE ?', ['%'.$q.'%'])
                    ->orWhereRaw('lower(description) LIKE ?', ['%'.$q.'%']);
            });
        }
        $page = $query->orderBy('source_row')->paginate((int) ($validated['per_page'] ?? 50));

        return response()->json([
            'data' => $page->getCollection()->map(static fn ($item): array => [
                'code' => $item->code,
                'description' => $item->description,
                'details' => $version === 'historical'
                    ? json_decode($item->details, true, 512, JSON_THROW_ON_ERROR) : new \stdClass(),
                'source_row' => $item->source_row,
                'is_ambiguous' => $version === 'historical'
                    && in_array($item->is_ambiguous, [true, 1, '1', 't'], true),
            ]),
            'meta' => [
                'catalog_number' => $reference->catalog_number ?? $catalog->number,
                'version' => $version,
                'name' => $reference->name,
                'classification' => $reference->classification,
                'source_sha256' => $reference->source_sha256,
                'source_url' => $reference->source_url ?? null,
                'item_count' => $reference->item_count,
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }
}
