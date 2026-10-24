<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalogs;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final readonly class ListSeeCatalogsController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => DB::table('see_catalogs')
                ->select('number', 'name', 'item_count', 'classification')
                ->orderBy('number')
                ->get(),
        ]);
    }
}
