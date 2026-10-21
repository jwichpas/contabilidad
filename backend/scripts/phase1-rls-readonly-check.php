<?php

declare(strict_types=1);

/**
 * F1.2: smoke RLS en Supabase Dev, SOLO LECTURA y rollback.
 * php scripts/phase1-rls-readonly-check.php
 *
 * Falla cerrado si el entorno, identidad, tenant o los permisos no coinciden.
 * No ejecuta migraciones, no crea datos y no imprime datos personales.
 */
require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$console = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$console->bootstrap();

use Illuminate\Support\Facades\DB;

$cfg = (array) config('database.connections.pgsql');
if (! $app->environment('local')
    || trim((string) ($cfg['url'] ?? '')) !== ''
    || (string) ($cfg['host'] ?? '') !== 'aws-1-ca-central-1.pooler.supabase.com'
    || (string) ($cfg['port'] ?? '') !== '6543'
    || (string) ($cfg['username'] ?? '') !== 'app_backend.bnjwxphwtkhblvhsnylj') {
    fwrite(STDERR, "[BLOQUEO] Destino no autorizado. No se ejecutó SQL.\n");
    exit(2);
}

$db = DB::connection('pgsql');
try {
    $identity = $db->selectOne('SELECT current_user AS role_name, current_database() AS db_name');
    if ($identity?->role_name !== 'app_backend' || $identity?->db_name !== 'postgres') {
        throw new RuntimeException('Conexión inesperada');
    }
    $tables = $db->select(<<<'SQL'
SELECT c.relname AS table_name, c.relrowsecurity AS rls_on, c.relforcerowsecurity AS rls_forced
FROM pg_catalog.pg_class c
JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid AND a.attname='company_id' AND NOT a.attisdropped
WHERE n.nspname='public' AND c.relkind IN ('r','p')
ORDER BY c.relname
SQL);
    if (count($tables) < 14) {
        throw new RuntimeException('Cantidad insuficiente de tablas tenant');
    }
    $db->beginTransaction();
    try {
        $db->statement('SET TRANSACTION READ ONLY');
        $db->select("SELECT set_config('app.current_company_id', '', true)");
        if ($db->selectOne('SELECT public.app_current_company_id() AS company_id')->company_id !== null) {
            throw new RuntimeException('La función tenant no falla cerrado');
        }
        foreach ($tables as $table) {
            if (! $table->rls_on || ! $table->rls_forced) {
                throw new RuntimeException('Falta RLS/FORCE');
            }
            $quoted = '"'.str_replace('"', '""', (string) $table->table_name).'"';
            // Los eventos globales audit_logs (company_id NULL) tienen una
            // política SELECT deliberadamente distinta. Detectar fugas de
            // registros ligados a empresas por separado y advertir sobre los
            // eventos globales, sin imprimir contenido de auditoría.
            $companyRows = $db->selectOne(
                'SELECT count(*) AS total FROM public.'.$quoted.' WHERE company_id IS NOT NULL'
            );
            if ((int) $companyRows->total !== 0) {
                throw new RuntimeException(
                    'RLS reveló '.$companyRows->total.' filas asociadas a empresas sin contexto en '.$table->table_name
                );
            }
            if ($table->table_name === 'audit_logs') {
                $globalRows = $db->selectOne(
                    'SELECT count(*) AS total FROM public.audit_logs WHERE company_id IS NULL'
                );
                if ((int) $globalRows->total > 0) {
                    echo '[ADVERTENCIA] audit_logs: '.$globalRows->total
                        ." eventos globales visibles sin contexto. Revisar política SELECT.\n";
                }
            }
        }
        echo '[PASS] Sin contexto de empresa: '.count($tables)." tablas tenant sin filas de empresa visibles.\n";
    } finally {
        $db->rollBack();
    }
    echo "[PASS] Rollback completado; sin escrituras ni migraciones.\n";
} catch (Throwable $e) {
    fwrite(STDERR, '[FAIL] RLS de lectura no verificado: '.$e->getMessage()."\n");
    exit(1);
}
