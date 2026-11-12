<?php

declare(strict_types=1);

// A dedicated, read-only, fail-closed guard for the PostgreSQL 18 Docker
// upgrade. Invoke ONLY from actualizar-esquema-pg18-local.ps1 after injecting
// the local process environment. Never reads, touches, or prints Neon secrets.
if (PHP_SAPI !== 'cli' || $argc !== 1) {
    exit(73);
}

require dirname(__DIR__).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__).'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $check = static function (array $config, string $username): bool {
        return ($config['driver'] ?? null) === 'pgsql'
            && ($config['host'] ?? null) === '127.0.0.1'
            && (string) ($config['port'] ?? '') === '55433'
            && ($config['database'] ?? null) === 'contabilidad_local'
            && ($config['username'] ?? null) === $username
            && ($config['sslmode'] ?? null) === 'disable'
            && trim((string) ($config['url'] ?? '')) === ''
            && is_string($config['password'] ?? null)
            && $config['password'] !== '';
    };

    if (! app()->environment('testing')
        || config('database.default') !== 'pgsql'
        || ! $check((array) config('database.connections.pgsql', []), 'postgres')
        || ! $check((array) config('database.connections.pgsql_migrations', []), 'postgres')
        || is_file(dirname(__DIR__).'/bootstrap/cache/config.php')) {
        throw new RuntimeException('LOCAL_CONFIG');
    }

    $row = \Illuminate\Support\Facades\DB::connection('pgsql_migrations')->selectOne(<<<'SQL'
        SELECT current_database() AS db,
               current_user AS actor,
               current_setting('server_version_num')::integer AS pg,
               (SELECT count(*) FROM public.migrations)::integer AS migrations,
               (SELECT count(*) FROM pg_catalog.pg_roles
                 WHERE rolname='app_backend' AND rolcanlogin AND NOT rolsuper
                   AND NOT rolbypassrls)::integer AS restricted_role,
               (SELECT count(*) FROM pg_catalog.pg_class c
                 JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                 WHERE n.nspname='public'
                   AND c.relname IN ('company_user','journal_entries',
                     'journal_entry_lines','company_subdiaries','exchange_rates')
                   AND c.relrowsecurity AND c.relforcerowsecurity)::integer AS force_rls
        SQL);

    if ($row?->db !== 'contabilidad_local'
        || $row?->actor !== 'postgres'
        || intdiv((int) ($row?->pg ?? 0), 10000) !== 18
        || (int) ($row?->migrations ?? -1) !== 25
        || (int) ($row?->restricted_role ?? -1) !== 1
        || (int) ($row?->force_rls ?? -1) !== 5) {
        throw new RuntimeException('LOCAL_BASELINE');
    }

    fwrite(STDOUT, "[PASS] Laravel solo apunta al Docker PG18 local con 25 migraciones y RLS\n");
} catch (\Throwable) {
    // Driver exceptions may include connection details. Suppress them.
    fwrite(STDERR, "[BLOQUEO] Identidad Laravel local no verificada; no se migro.\n");
    exit(73);
}
