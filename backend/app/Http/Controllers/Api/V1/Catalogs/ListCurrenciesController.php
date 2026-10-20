<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalogs;

use App\Models\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint de catálogo de monedas disponibles (PEN, USD, EUR).
 */
final readonly class ListCurrenciesController
{
    public function __invoke(Request $request): JsonResponse
    {
        $currencies = Currency::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $currencies,
        ]);
    }
}
