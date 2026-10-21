<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F1.4 - Restringe las lecturas de audit_logs a la empresa activa.
 *
 * Anteriormente la politica SELECT incluia 'company_id IS NULL' y exponia
 * eventos globales de sistema a cualquier sesion app_backend, incluso
 * sin contexto de empresa. Los registros permanecen intactos: NO hay DML.
 *
 * Los auditores globales deberan contar con una ruta administrativa separada
 * y controles de autorizacion explicitos; no se habilita acceso general.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection('pgsql_migrations');
        $config = $db->getConfig();
        $host = (string) ($config['host'] ?? '');
        $dbName = (string) ($config['database'] ?? '');
        $locator = implode(' ', [
            $host,
            (string) ($config['url'] ?? ''),
            (string) ($config['username'] ?? ''),
        ]);

        $isDisposableCI = app()->environment('testing')
            && $host === '127.0.0.1'
            && $dbName === 'contabilidad_ci';
        $isApprovedDev = app()->environment('local')
            && $dbName === 'postgres'
            && str_contains($locator, 'bnjwxphwtkhblvhsnylj');

        // Neon: solo la rama de pruebas; nunca el endpoint raiz production.
        // El nombre de rama es declarativo y el host debe coincidir con
        // el endpoint DIRECTO configurado explicitamente para pruebas.
        $branchName = strtolower(trim((string) env('NEON_TEST_BRANCH_NAME', '')));
        $expectedNeonHost = trim((string) env('NEON_TEST_BRANCH_HOST', ''));
        $isApprovedNeon = app()->environment('local')
            && $dbName === 'neondb'
            && $host !== ''
            && $host === $expectedNeonHost
            && $host === 'ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech'
            && ! str_contains($host, '-pooler.')
            && (string) ($config['username'] ?? '') === 'neondb_owner'
            && trim((string) ($config['url'] ?? '')) === ''
            && ! in_array($branchName, ['', 'production', 'prod', 'main', 'master'], true)
            && in_array((string) ($config['sslmode'] ?? ''), ['require', 'verify-ca', 'verify-full'], true);

        if (! $isDisposableCI && ! $isApprovedDev && ! $isApprovedNeon) {
            throw new RuntimeException('Bloqueo: politica de auditoria solo en Supabase Dev aprobado, rama Neon de pruebas o PostgreSQL CI local.');
        }

        $identity = $db->selectOne('SELECT current_user AS db_role, current_database() AS db_name');
        $expectedAdminRole = $isApprovedNeon ? 'neondb_owner' : 'postgres';
        if ($identity?->db_role !== $expectedAdminRole || $identity?->db_name !== $dbName) {
            throw new RuntimeException('Se requiere conexion administrativa postgres.');
        }

        $db->transaction(function () use ($db): void {
            $state = $db->selectOne(<<<'SQL'
                SELECT CASE WHEN c.relrowsecurity THEN 1 ELSE 0 END AS enabled,
                       CASE WHEN c.relforcerowsecurity THEN 1 ELSE 0 END AS forced
                FROM pg_catalog.pg_class c
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname='public'
                  AND c.relname='audit_logs'
                  AND c.relkind='r'
                SQL);
            if ($state === null || (int) $state->enabled !== 1 || (int) $state->forced !== 1) {
                throw new RuntimeException('La tabla audit_logs debe tener ENABLE y FORCE RLS.');
            }

            $db->unprepared(<<<'SQL'
                DROP POLICY IF EXISTS audit_logs_select_policy ON public.audit_logs;
                CREATE POLICY audit_logs_select_policy
                    ON public.audit_logs
                    AS PERMISSIVE
                    FOR SELECT
                    TO app_backend
                    USING (
                        company_id IS NOT NULL
                        AND company_id = (SELECT public.app_current_company_id())
                    );
                SQL);

            $rule = $db->selectOne(<<<'SQL'
                SELECT pg_catalog.pg_get_expr(p.polqual, p.polrelid) AS expression
                FROM pg_catalog.pg_policy p
                JOIN pg_catalog.pg_class c ON c.oid = p.polrelid
                JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname='public'
                  AND c.relname='audit_logs'
                  AND p.polname='audit_logs_select_policy'
                  AND p.polcmd='r'
                SQL);

            $expression = (string) ($rule?->expression ?? '');
            if ($expression === ''
                || ! str_contains($expression, 'company_id IS NOT NULL')
                || str_contains($expression, 'company_id IS NULL')) {
                throw new RuntimeException('La politica de lectura de auditoria no quedo restringida.');
            }
        });
    }

    public function down(): void
    {
        // Prohibido volver a exponer eventos globales a todos los tenants.
        throw new RuntimeException('Restaurar la exposicion global de audit_logs no es un rollback seguro.');
    }
};
