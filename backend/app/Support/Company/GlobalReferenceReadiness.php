<?php

declare(strict_types=1);

namespace App\Support\Company;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Puerta de lectura (rol app_backend) para la instalación global F2.
 *
 * La tabla reference_data_versions es deliberadamente privada del rol DDL;
 * NUNCA consultar ese ledger con las credenciales de la API. La validación
 * criptográfica de fuentes y hashes queda a cargo del instalador --verify.
 * Esta puerta comprueba que los 13 catálogos versionados estén completos
 * antes de iniciar el onboarding de empresas.
 */
final class GlobalReferenceReadiness
{
    private const DATASET = 'nexus-global-reference-2026-10-21-v1';

    /** @return array<string, int>|null */
    public static function parseExpectedCounts(string $manifestJson): ?array
    {
        $manifest = json_decode($manifestJson, true);

        if (! is_array($manifest)
            || ($manifest['schema_version'] ?? null) !== 1
            || ($manifest['dataset'] ?? null) !== self::DATASET
            || ! is_array($manifest['counts'] ?? null)
            || count($manifest['counts']) !== 13
            || ($manifest['plan_versions']['PCGE_2019'] ?? null) !== 1764
            || ($manifest['plan_versions']['PCGE_2026'] ?? null) !== 1637
            || ($manifest['counts']['accounting_account_templates'] ?? null) !== 3401) {
            return null;
        }

        foreach ($manifest['counts'] as $table => $count) {
            if (! is_string($table)
                || ! preg_match('/^[a-z][a-z0-9_]*$/D', $table)
                || ! is_int($count) || $count < 1) {
                return null;
            }
        }

        return $manifest['counts'];
    }

    /** Retorna null si está listo; de lo contrario, causa de bloqueo. */
    public function issue(): ?string
    {
        $missing = 'Instale primero los datos globales versionados F2 (monedas, SUNAT, normas, rubros y PCGE).';
        $path = database_path('reference_data/manifest.json');

        if (! is_file($path)) {
            return $missing;
        }

        $expected = self::parseExpectedCounts((string) file_get_contents($path));
        if ($expected === null) {
            return 'Manifiesto de datos globales inválido o sin versión aprobada.';
        }

        try {
            // The manifest is a committed, validated allowlist (13 exact tables).
            // One DB round-trip instead of 13 Schema::hasTable, 13 COUNT, and
            // three PCGE/currency COUNT queries over high-latency Neon links.
            // If ANY table is missing or a privilege was revoked, SQL fails
            // closed and this method returns the same blocking reason.
            $expressions = [];
            $index = 0;
            foreach ($expected as $table => $count) {
                $expressions[] = sprintf(
                    '(SELECT COUNT(*)::integer FROM public.%s) AS ref_%d',
                    $table,
                    $index++
                );
            }
            $expressions[] = <<<'SQL'
                (SELECT COUNT(*)::integer FROM public.accounting_account_templates
                 WHERE plan_version = 'PCGE_2019') AS pcge_2019
                SQL;
            $expressions[] = <<<'SQL'
                (SELECT COUNT(*)::integer FROM public.accounting_account_templates
                 WHERE plan_version = 'PCGE_2026') AS pcge_2026
                SQL;
            $expressions[] = <<<'SQL'
                (SELECT COUNT(*)::integer FROM public.currencies
                 WHERE code = 'PEN') AS currency_pen
                SQL;

            $actual = DB::selectOne('SELECT '.implode(', ', $expressions));
            if ($actual === null) return $missing;
            $index = 0;
            foreach ($expected as $count) {
                if ((int) ($actual->{'ref_'.$index} ?? -1) !== $count) {
                    return $missing;
                }
                ++$index;
            }
            if ((int) ($actual->pcge_2019 ?? 0) !== 1764
                || (int) ($actual->pcge_2026 ?? 0) !== 1637
                || (int) ($actual->currency_pen ?? 0) !== 1) {
                return $missing;
            }
        } catch (Throwable) {
            // Fail-closed si faltan grants SELECT, tablas o conectividad.
            return 'No se pudo comprobar la instalación de los catálogos globales F2.';
        }

        return null;
    }
}
