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
            // La consulta global se descarga una vez y se filtra localmente en el navegador.
            'per_page' => ['sometimes', 'integer', 'between:1,2000'],
        ]);
        $query = DB::table('see_catalog_items')->where('catalog_number', $number);
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
                'details' => json_decode($item->details, true, 512, JSON_THROW_ON_ERROR),
                'source_row' => $item->source_row,
                'is_ambiguous' => in_array($item->is_ambiguous, [true, 1, '1', 't'], true),
            ]),
            'meta' => [
                'catalog_number' => $catalog->number,
                'name' => $catalog->name,
                'classification' => $catalog->classification,
                'source_sha256' => $catalog->source_sha256,
                'item_count' => $catalog->item_count,
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }
}
