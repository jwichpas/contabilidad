<?php

declare(strict_types=1);

/** Read-only preflight/postflight for tenant subdiary master. No writes. */
if ($argc !== 2 || ($argv[1] ?? null) !== '--check') {
    fwrite(STDERR, "[USO] php scripts/check-company-subdiaries-schema.php --check\n");
    exit(2);
}
$root = dirname(__DIR__);
if (! is_file($root.'/vendor/autoload.php') || ! is_file($root.'/bootstrap/app.php')) {
    fwrite(STDERR, "[FAIL] Laravel no está instalado.\n");
    exit(1);
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$admin = (array) config('database.connections.pgsql_migrations', []);
$host = (string) env('NEON_TEST_BRANCH_HOST', '');
if (! $app->environment('local')
    || env('NEON_TEST_BRANCH_NAME') !== 'contabilidad-dev'
    || $host !== 'ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech'
    || ($admin['host'] ?? null) !== $host
    || ($admin['username'] ?? null) !== 'neondb_owner'
    || ($admin['database'] ?? null) !== 'neondb'
    || (string)($admin['port'] ?? '') !== '5432'
    || trim((string)($admin['url'] ?? '')) !== '') {
    fwrite(STDERR, "[BLOQUEO] Conexión administrativa distinta de Neon DEV; no se consultó SQL.\n");
    exit(1);
}

try {
    $db = \Illuminate\Support\Facades\DB::connection('pgsql_migrations');
    $hasTable = $db->getSchemaBuilder()->hasTable('company_subdiaries');
    $ran = $db->table('migrations')
        ->where('migration', '2026_10_24_140000_create_company_subdiaries')
        ->exists();
    echo '[INFO] Migración maestro: '.($ran ? 'Ran' : 'Pending').PHP_EOL;
    echo '[INFO] Tabla company_subdiaries: '.($hasTable ? 'true' : 'false').PHP_EOL;

    if (! $ran && ! $hasTable) {
        echo "[PASS] Precondiciones coherentes para migración específica con respaldo DEV.\n";
        exit(0);
    }
    if ($ran && $hasTable) {
        $result = $db->selectOne(<<<'SQL'
            SELECT
                c.relrowsecurity AS rls,
                c.relforcerowsecurity AS force_rls,
                EXISTS (SELECT 1 FROM pg_constraint
                    WHERE conname = 'fk_journal_subdiary_tenant_code') AS tenant_fk
            FROM pg_class c
            WHERE c.oid = 'public.company_subdiaries'::regclass
            SQL);
        if ($result === null || ! $result->rls || ! $result->force_rls || ! $result->tenant_fk) {
            fwrite(STDERR, "[BLOQUEO] Falta FORCE RLS o la FK intraempresa.\n");
            exit(1);
        }
        echo "[PASS] Migración instalada con FORCE RLS y FK intraempresa. No repetir.\n";
        exit(0);
    }
    fwrite(STDERR, "[BLOQUEO] El historial y esquema no coinciden; no migrar.\n");
    exit(1);
} catch (\Throwable $exception) {
    fwrite(STDERR, "[FAIL] No se pudo verificar el esquema. No se realizaron escrituras.\n");
    exit(1);
}
