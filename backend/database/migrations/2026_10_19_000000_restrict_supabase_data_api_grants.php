<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * R01: la API pública del sistema es Laravel/Sanctum.
 *
 * El esquema de contabilidad no es una API PostgREST pública. Esta migración
 * se ejecuta solo desde Artisan con la conexión administrativa independiente:
 *
 * php artisan migrate --database=pgsql_migrations
 *
 * Nunca ejecutar este SQL en el editor de Supabase ni usar la conexión app_backend
 * para gestionar permisos. No se toca el esquema de auth/storage u otros proyectos.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection('pgsql_migrations');
        $config = $db->getConfig();
        $locator = implode(' ', [
            (string) ($config['host'] ?? ''),
            (string) ($config['url'] ?? ''),
            (string) ($config['username'] ?? ''),
        ]);

        if (! str_contains($locator, 'bnjwxphwtkhblvhsnylj')) {
            throw new \RuntimeException('Conexión administrativa no corresponde a contabilidad-database.');
        }

        $identity = $db->selectOne('SELECT current_user AS db_role, current_database() AS db_name');
        if ($identity === null || $identity->db_role !== 'postgres' || $identity->db_name !== 'postgres') {
            throw new \RuntimeException('La migración exige rol administrador postgres en la base postgres.');
        }

        // Revocación y comprobación en la MISMA transacción, con rollback si
        // siguen existiendo permisos públicos o si cambia el acceso de Laravel.
        $db->transaction(function () use ($db): void {
            $before = $this->effectiveTablePrivileges($db);
            $beforeSequences = $this->effectiveSequencePrivileges($db);
            $beforeFunctions = $this->effectiveFunctionPrivileges($db);

            $db->unprepared(<<<'SQL'
                REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public
                    FROM anon, authenticated;
                REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public
                    FROM anon, authenticated;

                -- Los privilegios EXECUTE pueden provenir de PUBLIC (no solo
                -- de concesiones directas a anon y authenticated).
                REVOKE ALL PRIVILEGES ON ALL FUNCTIONS IN SCHEMA public
                    FROM PUBLIC, anon, authenticated;
                -- Se mantiene el comportamiento existente del rol Laravel:
                -- ejecutar las cinco funciones actuales (incluidos triggers).
                GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA public TO app_backend;

                ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public
                    REVOKE ALL PRIVILEGES ON TABLES FROM anon, authenticated;
                ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public
                    REVOKE ALL PRIVILEGES ON SEQUENCES FROM anon, authenticated;
                SQL);

            $after = $this->effectiveTablePrivileges($db);
            $afterSequences = $this->effectiveSequencePrivileges($db);
            $afterFunctions = $this->effectiveFunctionPrivileges($db);

            if ((int) $after->anon_tables !== 0 || (int) $after->authenticated_tables !== 0) {
                throw new \RuntimeException(
                    'Persisten permisos públicos efectivos: la migración se revierte automáticamente.'
                );
            }

            if ((int) $afterSequences->anon_sequences !== 0
                || (int) $afterSequences->authenticated_sequences !== 0) {
                throw new \RuntimeException(
                    'Persisten permisos públicos sobre secuencias: migración revertida automáticamente.'
                );
            }

            if ((int) $afterFunctions->anon_functions !== 0
                || (int) $afterFunctions->authenticated_functions !== 0) {
                throw new \RuntimeException(
                    'Persisten permisos RPC públicos: migración revertida automáticamente.'
                );
            }

            if ((int) $beforeFunctions->backend_functions < 1
                || (int) $afterFunctions->backend_functions !== (int) $beforeFunctions->backend_functions) {
                throw new \RuntimeException(
                    'Los permisos de ejecución de funciones del backend cambiaron: rollback automático.'
                );
            }

            if ((int) $beforeSequences->backend_sequences < 1
                || (int) $afterSequences->backend_sequences !== (int) $beforeSequences->backend_sequences) {
                throw new \RuntimeException(
                    'Cambió el acceso del backend a secuencias: migración revertida automáticamente.'
                );
            }

            if ((int) $before->app_backend_tables < 1
                || (int) $after->app_backend_tables !== (int) $before->app_backend_tables) {
                throw new \RuntimeException(
                    'Los permisos de app_backend no coinciden: la migración se revierte automáticamente.'
                );
            }
        });
    }

    /**
     * Cuenta privilegios EFECTIVOS, no solo ACL explícitas, para detectar
     * permisos heredados de roles/grants distintos.
     */
    private function effectiveTablePrivileges(\Illuminate\Database\Connection $db): object
    {
        return $db->selectOne(<<<'SQL'
            SELECT
                count(*) FILTER (
                    WHERE has_table_privilege('anon', c.oid, 'SELECT, INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER')
                ) AS anon_tables,
                count(*) FILTER (
                    WHERE has_table_privilege('authenticated', c.oid, 'SELECT, INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER')
                ) AS authenticated_tables,
                count(*) FILTER (
                    WHERE has_table_privilege('app_backend', c.oid, 'SELECT')
                ) AS app_backend_tables
            FROM pg_catalog.pg_class c
            JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
            SQL);
    }

    /**
     * Las secuencias también pueden exponerse vía Data API y deben conservar
     * el acceso del backend de Laravel.
     */
    private function effectiveSequencePrivileges(\Illuminate\Database\Connection $db): object
    {
        return $db->selectOne(<<<'SQL'
            WITH target_sequences AS MATERIALIZED (
                SELECT c.oid
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = 'public' AND c.relkind = 'S'
            )
            SELECT
                count(*) FILTER (
                    WHERE has_sequence_privilege('anon', c.oid, 'USAGE, SELECT, UPDATE')
                ) AS anon_sequences,
                count(*) FILTER (
                    WHERE has_sequence_privilege('authenticated', c.oid, 'USAGE, SELECT, UPDATE')
                ) AS authenticated_sequences,
                count(*) FILTER (
                    WHERE has_sequence_privilege('app_backend', c.oid, 'USAGE')
                ) AS backend_sequences
            FROM target_sequences c
            SQL);
    }

    /**
     * Incluye privilegios PUBLIC heredados: funciones invocables via RPC.
     */
    private function effectiveFunctionPrivileges(\Illuminate\Database\Connection $db): object
    {
        return $db->selectOne(<<<'SQL'
            SELECT
                count(*) FILTER (
                    WHERE has_function_privilege('anon', p.oid, 'EXECUTE')
                ) AS anon_functions,
                count(*) FILTER (
                    WHERE has_function_privilege('authenticated', p.oid, 'EXECUTE')
                ) AS authenticated_functions,
                count(*) FILTER (
                    WHERE has_function_privilege('app_backend', p.oid, 'EXECUTE')
                ) AS backend_functions
            FROM pg_catalog.pg_proc p
            JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
            WHERE n.nspname = 'public'
            SQL);
    }

    public function down(): void
    {
        // Reabrir las tablas de tokens y datos contables a los roles públicos
        // NO es un rollback seguro. Restaurar ACL históricas requiere revisión.
    }
};
