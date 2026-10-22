<?php

declare(strict_types=1);

/**
 * F1.10 repair: Neon contabilidad-dev ONLY. Adds member-only SELECT RLS policy
 * for ListUserCompaniesService. Does not modify existing tenant DML policies.
 *
 * --plan                      NO Laravel, NO SQL
 * --verify                    READ ONLY catalog queries as admin
 * --apply=contabilidad-dev    Explicit targeted Laravel migration
 *
 * Never use php artisan migrate or migrate:fresh as a substitute.
 */
$backend = dirname(__DIR__);
$expectedHost = 'ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$migration = '2026_10_21_030000_allow_authenticated_company_membership_listing';
$migrationPath = 'database/neon_migrations/'.$migration.'.php';
$policy = 'company_user_authenticated_member_listing_policy';
$mode = $argv[1] ?? '--plan';
if ($argc > 2 || ! in_array($mode, ['--plan','--verify','--apply=contabilidad-dev'], true)) {
    fwrite(STDERR, "[FAIL] Uso: php scripts/phase1-neon-membership-policy.php --plan|--verify|--apply=contabilidad-dev\n");
    exit(2);
}
if (! is_file($backend.'/'.$migrationPath)) {
    fwrite(STDERR, "[FAIL] Migracion F1.10 no encontrada.\n");
    exit(1);
}
if ($mode === '--plan') {
    echo '[PLAN] F1.10: agregar SOLO SELECT por app.current_user_id a public.company_user.'.PHP_EOL;
    echo '[PLAN] Preservar RLS tenant en INSERT/UPDATE; no tocar Supabase ni Neon production.'.PHP_EOL;
    echo '[PLAN] --verify = catalogos SELECT; --apply=contabilidad-dev = DDL explicito.'.PHP_EOL;
    echo '[PLAN] Exige Neon PG18 neondb vacia de datos de negocio, 16 migraciones base.'.PHP_EOL;
    echo '[DRY-RUN] Sin cargar Laravel ni conectar a SQL.'.PHP_EOL;
    exit(0);
}

if (! is_file($backend.'/.env') || is_file($backend.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "[FAIL] Falta .env local o configuracion Laravel cacheada.\n");
    exit(1);
}
$seen = [];
$duplicates = [];
$lines = file($backend.'/.env', FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "[FAIL] No fue posible leer .env.\n");
    exit(1);
}
foreach ($lines as $line) {
    if (! preg_match('/^[ \t]*(?:export[ \t]+)?([A-Z][A-Z0-9_]*)[ \t]*=/', $line, $m)) {
        continue;
    }
    $key = $m[1];
    if (! str_starts_with($key, 'DB_') && ! str_starts_with($key, 'NEON_')
        && ! in_array($key, ['APP_ENV','APP_KEY','DATABASE_URL'], true)) {
        continue;
    }
    if (isset($seen[$key])) {
        $duplicates[$key] = true;
    }
    $seen[$key] = true;
}
unset($lines);
if ($duplicates !== []) {
    fwrite(STDERR, '[FAIL] Variables duplicadas: '.implode(', ',array_keys($duplicates)).PHP_EOL);
    exit(1);
}

require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$cfg = (array) config('database.connections.pgsql_migrations', []);
$traffic = (array) config('database.connections.pgsql', []);
$adminPassword = (string) ($cfg['password'] ?? '');
$appPassword = (string) ($traffic['password'] ?? '');
$rolePassword = (string) env('DB_APP_BACKEND_ROLE_PASSWORD','');
if (! $app->environment('local')
    || (string) config('database.default') !== 'pgsql'
    || (string) env('NEON_TEST_BRANCH_NAME','') !== 'contabilidad-dev'
    || (string) env('NEON_TEST_BRANCH_HOST','') !== $expectedHost
    || (string) ($cfg['host'] ?? '') !== $expectedHost
    || (string) ($traffic['host'] ?? '') !== $expectedHost
    || (string) ($cfg['database'] ?? '') !== 'neondb'
    || (string) ($traffic['database'] ?? '') !== 'neondb'
    || (string) ($cfg['username'] ?? '') !== 'neondb_owner'
    || (string) ($traffic['username'] ?? '') !== 'app_backend'
    || (string) ($cfg['port'] ?? '') !== '5432'
    || (string) ($traffic['port'] ?? '') !== '5432'
    || trim((string) ($cfg['url'] ?? '')) !== ''
    || trim((string) ($traffic['url'] ?? '')) !== ''
    || ! in_array((string) ($cfg['sslmode'] ?? ''), ['require','verify-ca','verify-full'], true)
    || ! in_array((string) ($traffic['sslmode'] ?? ''), ['require','verify-ca','verify-full'], true)
    || filter_var(env('NEON_USE_POOLING',false),FILTER_VALIDATE_BOOLEAN)
    || $adminPassword === '' || strlen($rolePassword) < 20
    || $appPassword === '' || ! hash_equals($appPassword,$rolePassword)) {
    fwrite(STDERR, "[FAIL] Destino no autorizado o credenciales incoherentes; no se hace SQL.\n");
    exit(1);
}
unset($adminPassword,$rolePassword,$appPassword);

$query = <<<'SQL'
    SELECT current_user AS db_user, current_database() AS db_name,
           current_setting('server_version_num')::integer AS pg_version,
           (SELECT count(*) FROM public.migrations)::integer AS applied_migrations,
           (SELECT count(*) FROM public.migrations WHERE migration=?)::integer AS migration_record,
           (SELECT count(*) FROM public.companies)::integer AS companies,
           (SELECT count(*) FROM public.users)::integer AS users,
           (SELECT count(*) FROM public.personal_access_tokens)::integer AS tokens,
           (SELECT count(*) FROM pg_catalog.pg_class c
            JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname='public' AND c.relname='company_user'
              AND c.relrowsecurity AND c.relforcerowsecurity)::integer AS force_rls,
           (SELECT count(*) FROM pg_catalog.pg_policy p
            JOIN pg_catalog.pg_class c ON c.oid=p.polrelid
            JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname='public' AND c.relname='company_user'
              AND p.polname='company_user_tenant_isolation_policy'
              AND p.polcmd='*')::integer AS original_policy,
           (SELECT count(*) FROM pg_catalog.pg_policy p
            JOIN pg_catalog.pg_class c ON c.oid=p.polrelid
            JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname='public' AND c.relname='company_user'
              AND p.polname='company_user_authenticated_member_listing_policy'
              AND p.polcmd='r' AND p.polpermissive
              AND p.polroles @> ARRAY[(SELECT oid FROM pg_catalog.pg_roles WHERE rolname='app_backend')]
              AND p.polwithcheck IS NULL
              AND pg_catalog.pg_get_expr(p.polqual,p.polrelid) LIKE '%app.current_user_id%'
              AND pg_catalog.pg_get_expr(p.polqual,p.polrelid) LIKE '%user_id%'
           )::integer AS expected_policy,
           (SELECT count(*) FROM pg_catalog.pg_policy p
            JOIN pg_catalog.pg_class c ON c.oid=p.polrelid
            JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname='public' AND c.relname='company_user'
              AND p.polname='company_user_authenticated_member_listing_policy')::integer AS any_new_policy
    SQL;

try {
    $db = \Illuminate\Support\Facades\DB::connection('pgsql_migrations');
    $before = $db->selectOne($query,[$migration]);
    if ($before === null || ($before->db_user ?? '') !== 'neondb_owner'
        || ($before->db_name ?? '') !== 'neondb'
        || intdiv((int) ($before->pg_version ?? 0),10000) !== 18
        || (int) ($before->force_rls ?? 0) !== 1
        || (int) ($before->original_policy ?? 0) !== 1) {
        throw new RuntimeException('La identidad y la politica base no estan verificadas.');
    }
    echo '[PASS] Destino certificado: Neon contabilidad-dev, PG18 y FORCE RLS.'.PHP_EOL;
    if ($mode === '--verify') {
        echo '[INFO] Migraciones registradas: '.(int) $before->applied_migrations.PHP_EOL;
        echo '[INFO] Politica lectura membresia propia: '.((int) $before->expected_policy === 1 ? 'INSTALADA' : 'NO INSTALADA').PHP_EOL;
        echo '[INFO] Registro migracion F1.10: '.((int) $before->migration_record === 1 ? 'SI' : 'NO').PHP_EOL;
        echo '[PASS] Verificacion de solo lectura completada.'.PHP_EOL;
        exit(0);
    }
    if ((int) ($before->applied_migrations ?? -1) !== 16
        || (int) ($before->migration_record ?? -1) !== 0
        || (int) ($before->any_new_policy ?? -1) !== 0
        || (int) ($before->companies ?? -1) !== 0
        || (int) ($before->users ?? -1) !== 0
        || (int) ($before->tokens ?? -1) !== 0) {
        throw new RuntimeException('No se autoriza DDL: falta estado inicial limpio con 16 migraciones.');
    }
    echo '[PASS] Estado anterior: 16 migraciones, sin empresas/usuarios/tokens ni nueva politica.'.PHP_EOL;
    $exit = \Illuminate\Support\Facades\Artisan::call('migrate',[
        '--database'=>'pgsql_migrations',
        '--path'=>$migrationPath,
        '--force'=>true,
        '--no-interaction'=>true,
    ]);
    if ($exit !== 0) {
        throw new RuntimeException('Migracion F1.10 fallo.');
    }
    // No imprimir salida de Artisan: podria mostrar errores con datos sensibles.
    $after = $db->selectOne($query,[$migration]);
    if ($after === null
        || (int) ($after->applied_migrations ?? -1) !== 17
        || (int) ($after->migration_record ?? -1) !== 1
        || (int) ($after->expected_policy ?? -1) !== 1
        || (int) ($after->any_new_policy ?? -1) !== 1
        || (int) ($after->force_rls ?? 0) !== 1
        || (int) ($after->original_policy ?? 0) !== 1) {
        throw new RuntimeException('DDL puede haberse aplicado, pero la comprobacion final no paso.');
    }
    echo '[PASS] F1.10 aplicada: 17 migraciones, politica SELECT propia, base tenant sin cambios.'.PHP_EOL;
} catch (\Throwable $error) {
    fwrite(STDERR, '[FAIL] F1.10 politica: detencion segura. No reintentar migrate general ni borrar tablas.'.PHP_EOL);
    exit(1);
}
