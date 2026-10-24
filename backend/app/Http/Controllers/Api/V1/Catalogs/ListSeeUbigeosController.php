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
            'q' => ['sometimes', 'string', 'max:100'],
            'department' => ['sometimes', 'string', 'max:160'],
            'province' => ['sometimes', 'string', 'max:160'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $query = DB::table('see_ubigeos');
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
            'data' => $page->items(),
            'meta' => [
                'classification' => 'SUPPLIED_INEI_SNAPSHOT_UNVERIFIED',
                'total' => $page->total(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }
}
