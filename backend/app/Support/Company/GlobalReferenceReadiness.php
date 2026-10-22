<?php

declare(strict_types=1);

namespace App\Support\Company;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            foreach ($expected as $table => $count) {
                if (! Schema::hasTable($table) || (int) DB::table($table)->count() !== $count) {
                    return $missing;
                }
            }

            if ((int) DB::table('accounting_account_templates')->where('plan_version', 'PCGE_2019')->count() !== 1764
                || (int) DB::table('accounting_account_templates')->where('plan_version', 'PCGE_2026')->count() !== 1637
                || (int) DB::table('currencies')->where('code', 'PEN')->count() !== 1) {
                return $missing;
            }
        } catch (Throwable) {
            // Fail-closed si faltan grants SELECT, tablas o conectividad.
            return 'No se pudo comprobar la instalación de los catálogos globales F2.';
        }

        return null;
    }
}
