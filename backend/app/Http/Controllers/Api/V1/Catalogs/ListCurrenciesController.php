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
            ->whereRaw('currencies.is_active IS TRUE')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $currencies,
        ]);
    }
}
