<?php

declare(strict_types=1);

/**
 * F1.2: comprueba aislamiento entre empresas usando el rol real app_backend.
 * No crea ni modifica filas. Muestra solamente conteos, nunca datos de negocio.
 * Uso: php scripts/phase1-rls-cross-company-readonly.php
 */
require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$cfg = (array) config('database.connections.pgsql');
if (! $app->environment('local')
    || trim((string) ($cfg['url'] ?? '')) !== ''
    || (string) ($cfg['host'] ?? '') !== 'aws-1-ca-central-1.pooler.supabase.com'
    || (string) ($cfg['port'] ?? '') !== '6543'
    || (string) ($cfg['username'] ?? '') !== 'app_backend.bnjwxphwtkhblvhsnylj') {
    fwrite(STDERR, "[BLOQUEO] Entorno o conexión no autorizada.\n");
    exit(2);
}

$db = DB::connection('pgsql');
try {
    $identity = $db->selectOne('SELECT current_user AS role_name, current_database() AS db_name');
    if ($identity?->role_name !== 'app_backend' || $identity?->db_name !== 'postgres') {
        throw new RuntimeException('Identidad PostgreSQL inesperada');
    }

    // IDs numéricos solamente; nunca imprimimos nombres, RUC ni emails.
    $companyIds = array_map(static fn ($row): int => (int) $row->id,
        $db->select('SELECT id FROM public.companies ORDER BY id LIMIT 2'));
    if (count($companyIds) !== 2 || $companyIds[0] === $companyIds[1]) {
        throw new RuntimeException('Se requieren dos empresas existentes para este test');
    }

    $tables = $db->select(<<<'SQL'
SELECT c.relname AS table_name
FROM pg_catalog.pg_class c
JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid AND a.attname = 'company_id' AND NOT a.attisdropped
WHERE n.nspname='public'
  AND c.relkind IN ('r','p')
  AND c.relrowsecurity AND c.relforcerowsecurity
  AND has_table_privilege(current_user,c.oid,'SELECT')
ORDER BY c.relname
SQL);
    if (count($tables) < 14) {
        throw new RuntimeException('No están disponibles las 14 tablas protegidas');
    }

    $hasVisibleData = false;
    foreach ($companyIds as $companyId) {
        $db->beginTransaction();
        try {
            $db->statement('SET TRANSACTION READ ONLY');
            $db->select("SELECT set_config('app.current_user_id', '', true)");
            $db->select("SELECT set_config('app.current_company_id', ?, true)", [(string) $companyId]);
            if ((int) $db->selectOne('SELECT public.app_current_company_id() AS id')->id !== $companyId) {
                throw new RuntimeException('El contexto activo no coincide');
            }
            $visible = 0;
            foreach ($tables as $table) {
                $quoted = '"'.str_replace('"', '""', (string) $table->table_name).'"';
                $counts = $db->selectOne(
                    'SELECT count(*) AS visible_rows, '.
                    'count(*) FILTER (WHERE company_id IS DISTINCT FROM ?::bigint) AS foreign_rows '.
                    'FROM public.'.$quoted, [$companyId]
                );
                if ((int) $counts->foreign_rows !== 0) {
                    throw new RuntimeException('Fuga interempresa en '.$table->table_name);
                }
                $visible += (int) $counts->visible_rows;
            }
            $hasVisibleData = $hasVisibleData || $visible > 0;
            echo '[PASS] Empresa '.$companyId.': 0 filas ajenas en '.count($tables)
                .' tablas; visibles='.$visible."\n";
        } finally {
            $db->rollBack();
        }

        // Una transacción nueva debe empezar sin contexto de empresa.
        $db->beginTransaction();
        try {
            $db->statement('SET TRANSACTION READ ONLY');
            if ($db->selectOne('SELECT public.app_current_company_id() AS id')->id !== null) {
                throw new RuntimeException('El contexto de empresa persistió tras ROLLBACK');
            }
        } finally {
            $db->rollBack();
        }
    }
    if (! $hasVisibleData) {
        fwrite(STDERR, "[ADVERTENCIA] No se observaron filas visibles; el aislamiento se comprobó, no la existencia de datos.\n");
    }
    echo "[PASS] No hubo escrituras; se usó rollback en todas las transacciones.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] Aislamiento interempresa: '.$exception->getMessage()."\n");
    exit(1);
}
