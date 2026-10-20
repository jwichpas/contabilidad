<?php

declare(strict_types=1);

/**
 * F1.1: auditoría read-only de privilegios/RLS de PostgreSQL.
 *
 * Uso: php scripts/phase1-permissions-audit.php
 * No imprime credenciales, filas de negocio, correos ni tokens.
 * Solo admite el entorno local y la conexión app_backend del proyecto de desarrollo.
 */
require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
/** @var \Illuminate\Contracts\Console\Kernel $console */
$console = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$console->bootstrap();

use Illuminate\Support\Facades\DB;

$cfg = (array) config('database.connections.pgsql');
$host = (string) ($cfg['host'] ?? '');
if (! $app->environment('local')
    || trim((string) ($cfg['url'] ?? '')) !== ''
    || $host !== 'aws-1-ca-central-1.pooler.supabase.com'
    || (string) ($cfg['port'] ?? '') !== '6543'
    || (string) ($cfg['username'] ?? '') !== 'app_backend.bnjwxphwtkhblvhsnylj') {
    fwrite(STDERR, '[BLOQUEO] Se admite solamente el entorno local aprobado; no se ejecutó SQL.'.PHP_EOL);
    exit(2);
}

try {
    $db = DB::connection('pgsql');
    $identity = $db->selectOne('SELECT current_user AS role_name, current_database() AS database_name');
    if ($identity?->role_name !== 'app_backend' || $identity?->database_name !== 'postgres') {
        throw new RuntimeException('Identidad de PostgreSQL inesperada');
    }

    $sql = <<<'SQL'
SELECT c.relname AS object_name,
       c.relkind AS object_type,
       c.relrowsecurity AS rls_enabled,
       c.relforcerowsecurity AS rls_forced,
       has_table_privilege('anon', c.oid, 'SELECT') AS anon_select,
       has_table_privilege('anon', c.oid, 'INSERT') AS anon_insert,
       has_table_privilege('anon', c.oid, 'UPDATE') AS anon_update,
       has_table_privilege('anon', c.oid, 'DELETE') AS anon_delete,
       has_table_privilege('authenticated', c.oid, 'SELECT') AS authenticated_select,
       has_table_privilege('authenticated', c.oid, 'INSERT') AS authenticated_insert,
       has_table_privilege('authenticated', c.oid, 'UPDATE') AS authenticated_update,
       has_table_privilege('authenticated', c.oid, 'DELETE') AS authenticated_delete,
       has_table_privilege('app_backend', c.oid, 'SELECT') AS app_select,
       has_table_privilege('app_backend', c.oid, 'INSERT') AS app_insert,
       has_table_privilege('app_backend', c.oid, 'UPDATE') AS app_update,
       has_table_privilege('app_backend', c.oid, 'DELETE') AS app_delete
FROM pg_catalog.pg_class c
JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public' AND c.relkind IN ('r','p','v','m')
ORDER BY c.relname
SQL;
    $rows = $db->select($sql);
    $true = static fn ($value): bool => in_array($value, [true, 1, '1', 't'], true);
    $publicExposures = 0;
    $tenantUnprotected = 0;
    echo '[F1.1] Catálogo PostgreSQL public; consulta SELECT sin datos de negocio.'.PHP_EOL;
    echo 'objeto | RLS | FORCE | anon(S/I/U/D) | authenticated(S/I/U/D) | app_backend(S/I/U/D)'.PHP_EOL;
    foreach ($rows as $row) {
        $flags = static function ($item, string $role) use ($true): string {
            return implode('', array_map(
                static fn (string $action): string => $true($item->{$role.'_'.$action}) ? strtoupper($action[0]) : '-',
                ['select','insert','update','delete']
            ));
        };
        $anon = $flags($row, 'anon');
        $auth = $flags($row, 'authenticated');
        $appFlags = $flags($row, 'app');
        if ($anon !== '----' || $auth !== '----') { $publicExposures++; }
        if (! $true($row->rls_enabled) && ! $true($row->rls_forced)) { $tenantUnprotected++; }
        echo sprintf('%s | %s | %s | %s | %s | %s', $row->object_name,
            $true($row->rls_enabled) ? 'Y':'N', $true($row->rls_forced) ? 'Y':'N',
            $anon, $auth, $appFlags).PHP_EOL;
    }

    $funcs = $db->select(<<<'SQL'
SELECT p.proname, pg_catalog.pg_get_function_identity_arguments(p.oid) AS args,
       p.prosecdef, p.proconfig,
       has_function_privilege('anon',p.oid,'EXECUTE') AS anon_execute,
       has_function_privilege('authenticated',p.oid,'EXECUTE') AS authenticated_execute,
       has_function_privilege('app_backend',p.oid,'EXECUTE') AS app_execute
FROM pg_catalog.pg_proc p
JOIN pg_catalog.pg_namespace n ON n.oid=p.pronamespace
WHERE n.nspname='public'
ORDER BY p.proname, args
SQL);
    echo '[FUNCIONES] nombre(argumentos) | definer | search_path_configurado | anon/auth/app EXECUTE'.PHP_EOL;
    foreach ($funcs as $f) {
        $configuration = $f->proconfig;
        // Evitar imprimir configuraciones arbitrarias, que pueden contener secretos.
        $configured = $configuration !== null;
        echo $f->proname.'('.$f->args.') | '.($true($f->prosecdef) ? 'Y':'N')
            .' | '.($configured ? 'Y':'N').' | '
            .($true($f->anon_execute) ? 'Y':'N').'/'
            .($true($f->authenticated_execute) ? 'Y':'N').'/'
            .($true($f->app_execute) ? 'Y':'N').PHP_EOL;
    }
    echo sprintf('[RESUMEN] objetos=%d, permisos anon/auth a revisar=%d, objetos sin RLS=%d, funciones=%d',
        count($rows),$publicExposures,$tenantUnprotected,count($funcs)).PHP_EOL;
    echo '[OK] Auditoría de catálogo completada; sin DDL ni escrituras.'.PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[ERROR] Auditoría incompleta. Revise la conexión local y los permisos de lectura de catálogo.'.PHP_EOL);
    exit(1);
}
