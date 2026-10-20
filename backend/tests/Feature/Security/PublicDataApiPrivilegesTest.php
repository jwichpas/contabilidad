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
          (SELECT count(*)
           FROM information_schema.role_table_grants
           WHERE table_schema = 'public' AND grantee = 'anon') AS anon_grants,
          (SELECT count(*)
           FROM information_schema.role_table_grants
           WHERE table_schema = 'public' AND grantee = 'authenticated') AS authenticated_grants,
          (SELECT count(*)
           FROM information_schema.role_table_grants
           WHERE table_schema = 'public' AND grantee = 'app_backend'
             AND privilege_type = 'SELECT') AS backend_read_grants
        SQL);

    expect((int) $result->anon_grants)->toBe(0)
        ->and((int) $result->authenticated_grants)->toBe(0)
        ->and((int) $result->backend_read_grants)->toBeGreaterThan(0);
});
