<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Ejecutar después de la migración de permisos mediante Laravel:
 * php artisan migrate --database=pgsql_migrations
 *
 * Son SELECT del catálogo PostgreSQL: no inserta datos ni modifica RLS.
 */
it('blocks anon and authenticated from all public accounting tables, sequences and functions', function (): void {
    $table = DB::selectOne(<<<'SQL'
        SELECT
          count(*) FILTER (WHERE has_table_privilege('anon', c.oid,
            'SELECT, INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER')) AS anon_grants,
          count(*) FILTER (WHERE has_table_privilege('authenticated', c.oid,
            'SELECT, INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER')) AS authenticated_grants,
          count(*) FILTER (WHERE has_table_privilege('app_backend', c.oid, 'SELECT')) AS backend_read_grants
        FROM pg_catalog.pg_class c
        JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
        SQL);

    $sequence = DB::selectOne(<<<'SQL'
        SELECT
          count(*) FILTER (WHERE has_sequence_privilege('anon', c.oid,
            'USAGE, SELECT, UPDATE')) AS anon_grants,
          count(*) FILTER (WHERE has_sequence_privilege('authenticated', c.oid,
            'USAGE, SELECT, UPDATE')) AS authenticated_grants,
          count(*) FILTER (WHERE has_sequence_privilege('app_backend', c.oid,
            'USAGE')) AS backend_usage_grants
        FROM pg_catalog.pg_class c
        JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = 'public' AND c.relkind = 'S'
        SQL);

    $functions = DB::selectOne(<<<'SQL'
        SELECT
          count(*) FILTER (WHERE has_function_privilege('anon', p.oid, 'EXECUTE')) AS anon_grants,
          count(*) FILTER (WHERE has_function_privilege('authenticated', p.oid, 'EXECUTE')) AS authenticated_grants,
          count(*) FILTER (WHERE has_function_privilege('app_backend', p.oid, 'EXECUTE')) AS backend_execute_grants
        FROM pg_catalog.pg_proc p
        JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
        WHERE n.nspname = 'public'
        SQL);

    expect((int) $table->anon_grants)->toBe(0)
        ->and((int) $table->authenticated_grants)->toBe(0)
        ->and((int) $table->backend_read_grants)->toBeGreaterThan(0)
        ->and((int) $sequence->anon_grants)->toBe(0)
        ->and((int) $sequence->authenticated_grants)->toBe(0)
        ->and((int) $sequence->backend_usage_grants)->toBeGreaterThan(0)
        ->and((int) $functions->anon_grants)->toBe(0)
        ->and((int) $functions->authenticated_grants)->toBe(0)
        ->and((int) $functions->backend_execute_grants)->toBeGreaterThan(0);
});
