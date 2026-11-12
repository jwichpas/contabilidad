<?php

declare(strict_types=1);

namespace App\Services\ExchangeRates;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adaptador inmutable para el único endpoint público de SUNAT.
 * Respuesta: DD/MM/YYYY|COMPRA|VENTA| (NO es un histórico).
 * El servidor remoto, no el solicitante, determina la URL.
 */
final class SunatCurrentExchangeRateClient
{
    public const SOURCE_URL = 'https://www.sunat.gob.pe/a/txt/tipoCambio.txt';

    /**
     * @return array{date: string, buy: string, sell: string}|null
     */
    public function fetch(): ?array
    {
        try {
            $response = Http::accept('text/plain')
                ->withoutRedirecting()
                ->connectTimeout(2)
                ->timeout(4)
                ->get(self::SOURCE_URL);

            if (! $response->successful()) {
                Log::warning('SUNAT_FX_HTTP_FAILURE', ['status' => $response->status()]);
                return null;
            }

            // Este servicio solo necesita una linea pequeña; rechazar respuestas HTML o inesperadas.
            $body = $response->body();
            if (strlen($body) > 128) {
                Log::warning('SUNAT_FX_INVALID_PAYLOAD');
                return null;
            }

            return self::parse($body);
        } catch (ConnectionException $exception) {
            Log::warning('SUNAT_FX_CONNECTION_FAILURE', ['type' => $exception::class]);
            return null;
        }
    }

    /**
     * El formato y la fecha se validan estrictamente; no utilizar valores
     * aproximados, HTML, fechas de otro día ni números no finitos.
     *
     * @return array{date: string, buy: string, sell: string}|null
     */
    public static function parse(string $body): ?array
    {
        $body = trim(preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body);
        if (! preg_match(
            '/\A(\d{2})\/(\d{2})\/(\d{4})\|(\d{1,2}(?:\.\d{1,6})?)\|(\d{1,2}(?:\.\d{1,6})?)\|\z/D',
            $body,
            $matches
        )) {
            return null;
        }

        $day = (int) $matches[1];
        $month = (int) $matches[2];
        $year = (int) $matches[3];

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        $buy = (float) $matches[4];
        $sell = (float) $matches[5];

        if (! is_finite($buy) || ! is_finite($sell)
            || $buy <= 0 || $sell <= 0 || $buy > 20 || $sell > 20 || $buy > $sell) {
            return null;
        }

        return [
            'date' => CarbonImmutable::create($year, $month, $day)->toDateString(),
            'buy' => number_format($buy, 6, '.', ''),
            'sell' => number_format($sell, 6, '.', ''),
        ];
    }
}
