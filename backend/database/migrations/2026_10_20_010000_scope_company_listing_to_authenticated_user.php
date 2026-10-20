<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Permite al usuario autenticado listar sus membresías activas entre empresas.
 * No se elimina ni amplía la política de tenant vigente para DML; únicamente
 * se concede lectura de filas cuyo user_id coincide con un contexto SQL local.
 *
 * El controlador ListUserCompaniesController fija app.current_user_id con
 * set_config(..., true) DENTRO de una transacción. En pooler de transacciones
 * no debe utilizarse configuración de sesión persistente.
 *
 * Aplicar exclusivamente por Artisan con pgsql_migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection('pgsql_migrations');
        $identity = $db->selectOne('SELECT current_user AS db_role, current_database() AS db_name');

        if ($identity === null || $identity->db_role !== 'postgres' || $identity->db_name !== 'postgres') {
            throw new \RuntimeException(
                'Esta migración requiere la conexión administrativa pgsql_migrations como postgres.'
            );
        }

        $connection = $db->getConfig();
        $locator = implode(' ', [
            (string) ($connection['host'] ?? ''),
            (string) ($connection['url'] ?? ''),
            (string) ($connection['username'] ?? ''),
        ]);

        if (! str_contains($locator, 'bnjwxphwtkhblvhsnylj')) {
            throw new \RuntimeException('La conexión no identifica contabilidad-database.');
        }

        $db->unprepared(<<<'SQL'
            -- Conserva la política company_user_tenant_isolation_policy y
            -- sus restricciones de escritura por company_id.
            -- Esta nueva política es exclusivamente SELECT.
            CREATE POLICY company_user_authenticated_member_listing_policy
                ON public.company_user
                AS PERMISSIVE
                FOR SELECT
                TO app_backend
                USING (
                    user_id = (
                        SELECT nullif(
                            current_setting('app.current_user_id', true),
                            ''
                        )::bigint
                    )
                );
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_migrations')->unprepared(
            'DROP POLICY IF EXISTS company_user_authenticated_member_listing_policy ON public.company_user'
        );
    }
};
