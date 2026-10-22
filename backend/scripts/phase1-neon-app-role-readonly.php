<?php

declare(strict_types=1);

/**
 * F1.8 — Verificacion REAL de app_backend en Neon PostgreSQL 18,
 * exclusiva para contabilidad-dev y 100 % SELECT / READ ONLY.
 *
 * php scripts/phase1-neon-app-role-readonly.php
 *
 * No requiere tener empresas de prueba. No prueba HTTP ni escrituras RLS:
 * las pruebas con fixturas y ROLLBACK son una fase posterior separada.
 */
$backend = dirname(__DIR__);
$knownDevHost = 'ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$targetTables = [
    'company_user', 'accounting_accounts', 'cost_centers',
    'cash_flow_areas', 'company_areas', 'company_settings',
    'entities', 'fiscal_periods', 'exchange_rates',
    'journal_entries', 'journal_entry_lines', 'audit_logs',
    'company_currencies',
];

if ($argc !== 1) {
    fwrite(STDERR, "[FAIL] Uso: php scripts/phase1-neon-app-role-readonly.php\n");
    exit(2);
}
if (! is_file($backend.'/.env') || is_file($backend.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "[FAIL] .env privado ausente o configuracion Laravel cacheada.\n");
    exit(1);
}

$duplicates = [];
$seen = [];
$lines = file($backend.'/.env', FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "[FAIL] No fue posible leer .env.\n");
    exit(1);
}
foreach ($lines as $line) {
    if (! preg_match('/^[ \t]*(?:export[ \t]+)?([A-Z][A-Z0-9_]*)[ \t]*=/', $line, $matches)) {
        continue;
    }
    $name = $matches[1];
    if (! (str_starts_with($name, 'DB_') || str_starts_with($name, 'NEON_')
        || in_array($name, ['APP_ENV','APP_KEY','DATABASE_URL'], true))) {
        continue;
    }
    if (isset($seen[$name])) {
        $duplicates[$name] = true;
    }
    $seen[$name] = true;
}
unset($lines);
if ($duplicates !== []) {
    fwrite(STDERR, "[FAIL] .env contiene claves duplicadas: ".implode(', ', array_keys($duplicates)).PHP_EOL);
    exit(1);
}

require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cfg = (array) config('database.connections.pgsql', []);
$admin = (array) config('database.connections.pgsql_migrations', []);
$appPassword = (string) ($cfg['password'] ?? '');
$rolePassword = (string) env('DB_APP_BACKEND_ROLE_PASSWORD', '');
if (! $app->environment('local')
    || config('database.default') !== 'pgsql'
    || env('NEON_TEST_BRANCH_NAME', '') !== 'contabilidad-dev'
    || env('NEON_TEST_BRANCH_HOST', '') !== $knownDevHost
    || (string) ($cfg['host'] ?? '') !== $knownDevHost
    || (string) ($admin['host'] ?? '') !== $knownDevHost
    || (string) ($cfg['database'] ?? '') !== 'neondb'
    || (string) ($admin['database'] ?? '') !== 'neondb'
    || (string) ($cfg['username'] ?? '') !== 'app_backend'
    || (string) ($admin['username'] ?? '') !== 'neondb_owner'
    || (string) ($cfg['port'] ?? '') !== '5432'
    || (string) ($admin['port'] ?? '') !== '5432'
    || (string) ($cfg['url'] ?? '') !== ''
    || (string) ($admin['url'] ?? '') !== ''
    || ! in_array((string) ($cfg['sslmode'] ?? ''), ['require','verify-ca','verify-full'], true)
    || ! in_array((string) ($admin['sslmode'] ?? ''), ['require','verify-ca','verify-full'], true)
    || filter_var(env('NEON_USE_POOLING', false), FILTER_VALIDATE_BOOLEAN)
    || strlen($rolePassword) < 20
    || $appPassword === ''
    || ! hash_equals($rolePassword, $appPassword)) {
    fwrite(STDERR, "[FAIL] Perfil de conexion incorrecto; solo app_backend directo en Neon contabilidad-dev.\n");
    exit(1);
}
unset($rolePassword, $appPassword);

echo '[INFO] Probando app_backend en Neon contabilidad-dev (READ ONLY).'.PHP_EOL;
$db = \Illuminate\Support\Facades\DB::connection('pgsql');
$pdo = null;
$transactionStarted = false;
$passed = false;
try {
    $pdo = $db->getPdo();
    $pdo->exec('BEGIN TRANSACTION READ ONLY');
    $transactionStarted = true;
    $db->selectOne("SELECT set_config('statement_timeout','15000',true) AS value");

    $identity = $db->selectOne(<<<'SQL'
        SELECT current_user AS db_role,
               current_database() AS db_name,
               current_setting('server_version_num')::integer AS pg_version,
               pg_catalog.pg_is_in_recovery() AS standby
        SQL);
    if (($identity->db_role ?? '') !== 'app_backend'
        || ($identity->db_name ?? '') !== 'neondb'
        || intdiv((int) ($identity->pg_version ?? 0), 10000) !== 18) {
        throw new \RuntimeException('Identidad de la conexion operativa inesperada.');
    }
    echo '[PASS] Login real como app_backend en neondb PostgreSQL 18.'.PHP_EOL;

    $role = $db->selectOne(<<<'SQL'
        SELECT count(*)::integer AS safe_roles
        FROM pg_catalog.pg_roles
        WHERE rolname = current_user
          AND rolcanlogin AND NOT rolbypassrls AND NOT rolsuper
          AND NOT rolcreaterole AND NOT rolcreatedb AND NOT rolinherit
        SQL);
    if ((int) ($role->safe_roles ?? 0) !== 1) {
        throw new \RuntimeException('app_backend tiene privilegios administrativos inesperados.');
    }

    $tables = $db->selectOne(<<<'SQL'
        SELECT count(*)::integer AS force_rls_count
        FROM pg_catalog.pg_class c
        JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname='public'
          AND c.relname IN (
            'company_user','accounting_accounts','cost_centers',
            'cash_flow_areas','company_areas','company_settings',
            'entities','fiscal_periods','exchange_rates',
            'journal_entries','journal_entry_lines','audit_logs',
            'company_currencies'
          )
          AND c.relkind='r' AND c.relrowsecurity AND c.relforcerowsecurity
        SQL);
    if (count($targetTables) !== 13 || (int) ($tables->force_rls_count ?? 0) !== 13) {
        throw new \RuntimeException('No se confirmaron las 13 tablas protegidas con FORCE RLS.');
    }

    $access = $db->selectOne(<<<'SQL'
        SELECT CASE WHEN pg_catalog.has_table_privilege(current_user, 'public.company_areas','SELECT') THEN 1 ELSE 0 END AS can_read_areas,
               CASE WHEN pg_catalog.has_table_privilege(current_user, 'public.company_areas','INSERT') THEN 1 ELSE 0 END AS can_insert_areas,
               CASE WHEN pg_catalog.has_table_privilege(current_user, 'public.audit_logs','SELECT') THEN 1 ELSE 0 END AS can_read_audit,
               CASE WHEN pg_catalog.has_table_privilege(current_user, 'public.accounting_standards','SELECT') THEN 1 ELSE 0 END AS can_read_standards
        SQL);
    if ((int) ($access->can_read_areas ?? 0) !== 1
        || (int) ($access->can_insert_areas ?? 0) !== 1
        || (int) ($access->can_read_audit ?? 0) !== 1
        || (int) ($access->can_read_standards ?? 0) !== 1) {
        throw new \RuntimeException('Faltan GRANTs de la aplicacion.');
    }
    echo '[PASS] Rol restringido, GRANTs y 13 tablas FORCE RLS confirmados.'.PHP_EOL;

    $noTenant = $db->selectOne('SELECT public.app_current_company_id() AS tenant');
    if (($noTenant->tenant ?? null) !== null) {
        throw new \RuntimeException('La funcion RLS no fallo cerrada sin contexto.');
    }
    foreach (['101','202'] as $companyId) {
        $db->selectOne("SELECT set_config('app.current_company_id', ?, true) AS value", [$companyId]);
        $active = $db->selectOne('SELECT public.app_current_company_id() AS tenant');
        if ((string) ($active->tenant ?? '') !== $companyId) {
            throw new \RuntimeException('Contexto SQL no coincide.');
        }
        // Estas consultas quedan sujetas a RLS real, no usan el rol propietario.
        $db->selectOne('SELECT count(*)::integer AS n FROM public.company_areas');
        $db->selectOne('SELECT count(*)::integer AS n FROM public.audit_logs');
    }

    $db->selectOne("SELECT set_config('app.current_company_id','',true) AS value");
    if (($db->selectOne('SELECT public.app_current_company_id() AS tenant')->tenant ?? null) !== null) {
        throw new \RuntimeException('El contexto tenant vacio no quedo bloqueado.');
    }
    $db->selectOne('SELECT count(*)::integer AS n FROM public.company_areas');
    $db->selectOne('SELECT count(*)::integer AS n FROM public.audit_logs');
    echo '[PASS] Contexto local 101/202 y ausencia de contexto sin filtraciones SQL.'.PHP_EOL;
    echo '[AVISO] Sin fixturas A/B esta prueba no demuestra denegacion cruzada de escrituras.'.PHP_EOL;
    $passed = true;
} catch (\Throwable $error) {
    // Los errores PDO pueden incluir valores de conexion; ocultarlos.
    fwrite(STDERR, '[FAIL] Prueba app_backend READ ONLY no aprobada. Revisar privilegios, contexto o conectividad.'.PHP_EOL);
} finally {
    if ($transactionStarted && $pdo !== null) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (\Throwable $ignored) {
            fwrite(STDERR, '[WARN] No se pudo confirmar cierre de transaccion de solo lectura.'.PHP_EOL);
            $passed = false;
        }
    }
    $db->disconnect();
}
if (! $passed) {
    exit(1);
}
echo '[PASS] Prueba de rol operativo Neon terminada sin INSERT, UPDATE ni DELETE.'.PHP_EOL;
