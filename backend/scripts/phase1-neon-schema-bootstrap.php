<?php

declare(strict_types=1);

/**
 * F1.7 — Bootstrap conservador de Neon contabilidad-dev (PostgreSQL 18).
 *
 * Por defecto: --plan SIN consultar ni modificar la base de datos.
 * --apply=contabilidad-dev es una accion EXPLICITA, con guardas estrictas.
 * No mueve/borra migraciones existentes y preserva Supabase y Neon production.
 *
 * php scripts/phase1-neon-schema-bootstrap.php --plan
 * php scripts/phase1-neon-schema-bootstrap.php --apply=contabilidad-dev
 */
$backend = dirname(__DIR__);
$expectedHost = 'ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$expectedBranch = 'contabilidad-dev';
$expectedDb = 'neondb';
$allSchema = [
    '0001_01_00_000000_setup_extensions_and_app_backend_role',
    '0001_01_01_000000_create_companies_table',
    '0001_01_01_000001_create_users_and_company_user_tables',
    '0001_01_02_000000_create_accounting_accounts_table',
    '0001_01_02_000001_create_cost_centers_and_cash_flow_areas_tables',
    '0001_01_02_000002_create_currencies_and_sunat_tables',
    '0001_01_02_000003_create_accounting_standards_and_rubrics_tables',
    '0001_01_02_000004_create_company_settings_areas_and_entities_tables',
    '0001_01_03_000000_create_fiscal_periods_and_exchange_rates_tables',
    '0001_01_04_000000_create_journal_entries_tables',
    '0001_01_05_000000_create_accounting_account_templates_table',
    '0001_01_06_000000_create_audit_logs_table',
    '0001_01_07_000000_create_company_currencies_table',
    '0001_01_08_000000_optimize_rls_policies_and_tenant_function',
    '2026_10_21_010000_harden_postgres_function_search_path',
    '2026_10_21_020000_close_global_audit_log_read_leak',
];
$supabaseOnly = [
    '2026_10_19_000000_restrict_supabase_data_api_grants',
    '2026_10_19_010000_add_company_economic_profile',
    '2026_10_20_000000_rotate_app_backend_credential',
    '2026_10_20_010000_scope_company_listing_to_authenticated_user',
    '2026_10_20_235900_create_dev_company_b_via_laravel',
];

$mode = $argv[1] ?? '--plan';
if ($argc > 2 || ! in_array($mode, ['--plan', '--apply='.$expectedBranch], true)) {
    fwrite(STDERR, "Uso: php scripts/phase1-neon-schema-bootstrap.php --plan | --apply=contabilidad-dev\n");
    exit(2);
}
$isApply = $mode === '--apply='.$expectedBranch;
echo '[MODO] '.($isApply ? 'APLICACION EXPLICITA: Neon contabilidad-dev' : 'PLAN SIN SQL').PHP_EOL;

$folder = $backend.'/database/migrations';
$expected = array_merge($allSchema, $supabaseOnly);
$found = glob($folder.'/*.php');
if ($found === false) {
    fwrite(STDERR, "[FAIL] No se pudo inspeccionar el directorio de migraciones.\n");
    exit(1);
}
$foundNames = array_map(static fn (string $file): string => pathinfo($file, PATHINFO_FILENAME), $found);
$unexpected = array_diff($foundNames, $expected);
$missing = array_diff($expected, $foundNames);
if (count($allSchema) !== 16 || count($supabaseOnly) !== 5
    || count($foundNames) !== 21 || $unexpected !== [] || $missing !== []) {
    fwrite(STDERR, "[FAIL] El inventario de migraciones cambió; no ejecutar SQL sin actualizar la lista revisada.\n");
    exit(1);
}
echo '[PASS] Inventario: 16 migraciones PostgreSQL portables y 5 Supabase-only excluidas.'.PHP_EOL;

if (! $isApply) {
    foreach ($allSchema as $i => $name) {
        echo sprintf('  %02d %s', $i + 1, $name).PHP_EOL;
    }
    echo '[DRY-RUN] Ninguna conexion SQL ni migracion se ejecuto.'.PHP_EOL;
    exit(0);
}

$envFile = $backend.'/.env';
if (! is_file($envFile) || is_file($backend.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "[FAIL] Falta .env privado o existe configuracion Laravel cacheada.\n");
    exit(1);
}
// No se permite ninguna clave sensible ambigua antes de conectarse.
$seen = [];
$duplicates = [];
$lines = file($envFile, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "[FAIL] No se pudo leer .env para comprobar duplicados.\n");
    exit(1);
}
foreach ($lines as $line) {
    if (! preg_match('/^[ \t]*(?:export[ \t]+)?([A-Z][A-Z0-9_]*)[ \t]*=/', $line, $match)) {
        continue;
    }
    $key = $match[1];
    if (! (str_starts_with($key, 'DB_') || str_starts_with($key, 'NEON_')
        || in_array($key, ['APP_ENV','APP_KEY','DATABASE_URL'], true))) {
        continue;
    }
    if (isset($seen[$key])) {
        $duplicates[] = $key;
    }
    $seen[$key] = true;
}
unset($lines);
if ($duplicates !== []) {
    fwrite(STDERR, '[FAIL] Claves duplicadas en .env: '.implode(', ', array_unique($duplicates)).PHP_EOL);
    exit(1);
}

require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$admin = (array) config('database.connections.pgsql_migrations', []);
$traffic = (array) config('database.connections.pgsql', []);
if (! $app->environment('local')
    || (string) env('NEON_TEST_BRANCH_NAME', '') !== $expectedBranch
    || (string) env('NEON_TEST_BRANCH_HOST', '') !== $expectedHost
    || (string) ($admin['host'] ?? '') !== $expectedHost
    || (string) ($traffic['host'] ?? '') !== $expectedHost
    || (string) ($admin['port'] ?? '') !== '5432'
    || (string) ($traffic['port'] ?? '') !== '5432'
    || (string) ($admin['database'] ?? '') !== $expectedDb
    || (string) ($traffic['database'] ?? '') !== $expectedDb
    || (string) ($admin['username'] ?? '') !== 'neondb_owner'
    || (string) ($traffic['username'] ?? '') !== 'app_backend'
    || (string) config('database.default') !== 'pgsql'
    || trim((string) ($admin['url'] ?? '')) !== ''
    || trim((string) ($traffic['url'] ?? '')) !== ''
    || ! in_array((string) ($admin['sslmode'] ?? ''), ['require','verify-ca','verify-full'], true)
    || ! in_array((string) ($traffic['sslmode'] ?? ''), ['require','verify-ca','verify-full'], true)
    || filter_var(env('NEON_USE_POOLING', false), FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, "[FAIL] Entorno inesperado. Solo Neon contabilidad-dev directo, TLS y PostgreSQL 18.\n");
    exit(1);
}
$appPassword = (string) ($traffic['password'] ?? '');
$rolePassword = (string) env('DB_APP_BACKEND_ROLE_PASSWORD', '');
$adminPassword = (string) ($admin['password'] ?? '');
$appKey = (string) env('APP_KEY', '');
$keyOk = str_starts_with($appKey, 'base64:')
    ? (($decoded = base64_decode(substr($appKey, 7), true)) !== false && strlen($decoded) === 32)
    : strlen($appKey) === 32;
if (! $keyOk || $adminPassword === '' || strlen($rolePassword) < 20
    || $appPassword === '' || ! hash_equals($rolePassword, $appPassword)) {
    fwrite(STDERR, "[FAIL] APP_KEY o credenciales privadas invalidas (valores ocultos).\n");
    exit(1);
}
unset($appPassword, $rolePassword, $adminPassword, $appKey, $decoded);

try {
    $db = \Illuminate\Support\Facades\DB::connection('pgsql_migrations');
    $identity = $db->selectOne(<<<'SQL'
        SELECT current_database() AS db,
               current_user AS username,
               current_setting('server_version_num')::integer AS version_num,
               (SELECT count(*) FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                WHERE n.nspname='public' AND c.relkind IN ('r','p'))::integer AS tables,
               (SELECT count(*) FROM pg_catalog.pg_roles
                WHERE rolname='app_backend')::integer AS existing_roles,
               (SELECT count(*) FROM pg_catalog.pg_roles
                WHERE rolname=current_user AND rolcreaterole
                  AND rolcanlogin AND NOT rolsuper)::integer AS admin_can_create_roles,
               (SELECT count(*) FROM pg_catalog.pg_available_extensions
                WHERE name='pgcrypto')::integer AS pgcrypto_available
        SQL);
    if (($identity->db ?? '') !== $expectedDb
        || ($identity->username ?? '') !== 'neondb_owner'
        || intdiv((int) ($identity->version_num ?? 0), 10000) !== 18
        || (int) ($identity->tables ?? -1) !== 0
        || (int) ($identity->existing_roles ?? -1) !== 0
        || (int) ($identity->admin_can_create_roles ?? 0) !== 1
        || (int) ($identity->pgcrypto_available ?? 0) !== 1) {
        throw new RuntimeException('La rama no esta vacia o no cumple las precondiciones.');
    }
    echo '[PASS] Conexion admin real: PG18, neondb vacia, app_backend ausente, CREATEROLE y pgcrypto disponibles.'.PHP_EOL;
} catch (\Throwable $failure) {
    // Nunca mostrar excepciones que podrían contener URI/SQL con contraseñas.
    fwrite(STDERR, "[FAIL] Conexion/identidad previa no certificada; no se ejecuto ninguna migracion.\n");
    exit(1);
}

foreach ($allSchema as $index => $migration) {
    echo sprintf('[MIGRACION %02d/%02d] %s', $index + 1, count($allSchema), $migration).PHP_EOL;
    try {
        $status = \Illuminate\Support\Facades\Artisan::call('migrate', [
            '--database' => 'pgsql_migrations',
            '--path' => 'database/migrations/'.$migration.'.php',
            '--force' => true,
            '--no-interaction' => true,
        ]);
        $output = \Illuminate\Support\Facades\Artisan::output();
        // No imprimir output bruto de Laravel: podria incluir SQL con password.
        if ($status !== 0 || preg_match('/\bFAIL\b/i', $output)) {
            throw new RuntimeException('Artisan no completo la migracion.');
        }
        $record = $db->selectOne(
            'SELECT count(*)::integer AS n FROM public.migrations WHERE migration = ?',
            [$migration]
        );
        if ((int) ($record->n ?? 0) !== 1) {
            throw new RuntimeException('No hay exactamente un registro de migracion.');
        }
        echo '[PASS] Migracion registrada.'.PHP_EOL;
    } catch (\Throwable $failure) {
        fwrite(STDERR, '[FAIL] Migracion detenida: '.$migration.PHP_EOL);
        fwrite(STDERR, "[BLOQUEO] No continuar ni reintentar migrate general. Inspeccionar estado sin mostrar secretos.\n");
        exit(1);
    }
}

try {
    $hardened = $db->selectOne(<<<'SQL'
        SELECT count(*)::integer AS n
        FROM pg_catalog.pg_proc p
        JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
        WHERE n.nspname='public'
          AND p.proname IN ('app_current_company_id',
              'trg_fn_enforce_accounting_account_hierarchy',
              'trg_fn_prevent_journal_entry_mutation',
              'trg_fn_validate_journal_line_context',
              'trg_fn_enforce_double_entry_balance')
          AND p.pronargs=0
          AND 'search_path=pg_catalog'=ANY(p.proconfig)
        SQL);
    $policy = $db->selectOne(<<<'SQL'
        SELECT count(*)::integer AS total,
               count(*) FILTER (
                 WHERE pg_catalog.pg_get_expr(p.polqual,p.polrelid)
                 LIKE '%company_id IS NULL%')::integer AS leaked
        FROM pg_catalog.pg_policy p
        JOIN pg_catalog.pg_class c ON c.oid=p.polrelid
        JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname='public' AND c.relname='audit_logs'
          AND p.polname='audit_logs_select_policy'
        SQL);
    $role = $db->selectOne(<<<'SQL'
        SELECT count(*)::integer AS n FROM pg_catalog.pg_roles
        WHERE rolname='app_backend' AND NOT rolsuper AND NOT rolbypassrls
          AND rolcanlogin AND NOT rolcreaterole AND NOT rolcreatedb
        SQL);
    if ((int) ($hardened->n ?? 0) !== 5 || (int) ($policy->total ?? 0) !== 1
        || (int) ($policy->leaked ?? 1) !== 0 || (int) ($role->n ?? 0) !== 1) {
        throw new RuntimeException('Control final de roles, search_path o auditoria no aprobado.');
    }
    echo '[PASS] 16 migraciones; cinco search_path protegidos; audit_logs tenant-only; app_backend limitado.'.PHP_EOL;
    echo '[PENDIENTE] Pruebas integradas RLS/HTTP desde rol app_backend en rama de pruebas.'.PHP_EOL;
} catch (\Throwable $failure) {
    fwrite(STDERR, "[FAIL] Esquema migrado pero verificaciones finales pendientes: no iniciar aplicaciones.\n");
    exit(1);
}
