<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Models\ExchangeRate;
use App\Services\ExchangeRates\SunatExchangeRateSynchronizer;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Única ruta HTTP de importación; nunca mutar exchange_rates desde un GET.
 * La URL externa es fija, sin parámetros de host ni redirección del usuario.
 * El middleware de compañía exige membresía y rol contable.
 */
final readonly class ImportSunatExchangeRateController
{
    public function __construct(
        private TenantContext $tenant,
        private SunatExchangeRateSynchronizer $synchronizer,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $companyId = $this->tenant->requireCompanyId(ExchangeRate::class);
        $data = $request->validate([
            'rate_date' => ['required', 'date_format:Y-m-d'],
            'company_id' => ['prohibited'],
            'source_url' => ['prohibited'],
            'buy_rate' => ['prohibited'],
            'sell_rate' => ['prohibited'],
        ]);

        $result = $this->synchronizer->sync(
            $companyId, $data['rate_date'], (int) $request->user()->id
        );

        $status = $result['status'];
        if ($status === 'unsupported') {
            return response()->json([
                'message' => 'La importación SUNAT solo está disponible con PEN como base y USD activa.',
                'data' => $result,
            ], 422);
        }
        if ($status === 'date_mismatch') {
            return response()->json([
                'message' => 'SUNAT publica '.$result['published_date'].'; la fecha del comprobante es '.$result['date'].'. '
                    .'El archivo público no permite consultar fechas históricas. No se importó ninguna tasa.',
                'data' => $result,
            ], 409);
        }
        if ($status === 'unavailable') {
            return response()->json([
                'message' => 'No fue posible obtener una tasa válida desde SUNAT. '
                    .'Conserve el comprobante pendiente y registre una tasa verificada cuando esté disponible.',
                'data' => $result,
            ], 503);
        }

        return response()->json([
            'message' => $status === 'imported'
                ? 'Tipo de cambio SUNAT importado para la fecha contable.'
                : 'La empresa ya tiene un tipo de cambio registrado para esta fecha; no se modificó.',
            'data' => $result,
        ], $status === 'imported' ? 201 : 200);
    }
}
