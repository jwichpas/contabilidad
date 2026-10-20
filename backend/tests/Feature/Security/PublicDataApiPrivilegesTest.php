<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Ejecutar contra contabilidad-database SOLO después de aplicar la migración
 * de permisos mediante Laravel: php artisan migrate --database=pgsql_migrations.
 * La consulta es de solo lectura, sin fixtures ni modificaciones de datos.
 */
it('does not expose public accounting tables to Supabase anon or authenticated roles', function (): void {
    $result = DB::selectOne(<<<'SQL'
        SELECT
          count(*) FILTER (WHERE has_table_privilege('anon', c.oid, 'SELECT')) AS anon_grants,
          count(*) FILTER (WHERE has_table_privilege('authenticated', c.oid, 'SELECT')) AS authenticated_grants,
          count(*) FILTER (WHERE has_table_privilege('app_backend', c.oid, 'SELECT')) AS backend_read_grants
        FROM pg_catalog.pg_class c
        JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
        SQL);

    expect((int) $result->anon_grants)->toBe(0)
        ->and((int) $result->authenticated_grants)->toBe(0)
        ->and((int) $result->backend_read_grants)->toBeGreaterThan(0);
});
