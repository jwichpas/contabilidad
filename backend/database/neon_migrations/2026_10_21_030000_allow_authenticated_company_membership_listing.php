<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F1.10: permitir SOLO SELECT de membresias propias durante login y
 * /tenant/companies en Neon contabilidad-dev. Preservar politica tenant
 * para DML; no copiar/ejecutar sobre Supabase ni Neon production.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection('pgsql_migrations');
        $cfg = (array) $db->getConfig();
        $devHost = 'ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
        if (app()->environment() !== 'local'
            || env('NEON_TEST_BRANCH_NAME') !== 'contabilidad-dev'
            || env('NEON_TEST_BRANCH_HOST') !== $devHost
            || (string) ($cfg['host'] ?? '') !== $devHost
            || (string) ($cfg['database'] ?? '') !== 'neondb'
            || (string) ($cfg['username'] ?? '') !== 'neondb_owner'
            || (string) ($cfg['port'] ?? '') !== '5432'
            || trim((string) ($cfg['url'] ?? '')) !== ''
            || ! in_array((string) ($cfg['sslmode'] ?? ''), ['require','verify-ca','verify-full'], true)) {
            throw new RuntimeException('F1.10 migration: solo admin directo de Neon contabilidad-dev.');
        }
        $state = $db->selectOne(<<<'SQL'
            SELECT current_user AS login, current_database() AS db,
                   current_setting('server_version_num')::int AS pg_version,
                   (SELECT count(*) FROM pg_catalog.pg_class c
                    JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                    WHERE n.nspname='public' AND c.relname='company_user'
                    AND c.relrowsecurity AND c.relforcerowsecurity)::int AS force_rls,
                   (SELECT count(*) FROM pg_catalog.pg_policy p
                    JOIN pg_catalog.pg_class c ON c.oid=p.polrelid
                    JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                    WHERE n.nspname='public' AND c.relname='company_user'
                    AND p.polname='company_user_tenant_isolation_policy')::int AS original_policy,
                   (SELECT count(*) FROM pg_catalog.pg_policy p
                    JOIN pg_catalog.pg_class c ON c.oid=p.polrelid
                    JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                    WHERE n.nspname='public' AND c.relname='company_user'
                    AND p.polname='company_user_authenticated_member_listing_policy')::int AS new_policy
            SQL);
        if ($state === null || $state->login !== 'neondb_owner'
            || $state->db !== 'neondb'
            || intdiv((int) $state->pg_version, 10000) !== 18
            || (int) $state->force_rls !== 1
            || (int) $state->original_policy !== 1
            || (int) $state->new_policy !== 0) {
            throw new RuntimeException('F1.10 migration: estado de seguridad inesperado.');
        }

        // app.current_user_id se configura LOCAL a una transaccion Laravel,
        // a partir del usuario autentificado, nunca de un parametro del cliente.
        // El SELECT adicional no altera WITH CHECK ni permisos de escritura.
        $db->unprepared(<<<'SQL'
            CREATE POLICY company_user_authenticated_member_listing_policy
            ON public.company_user
            AS PERMISSIVE
            FOR SELECT
            TO app_backend
            USING (
                user_id = (
                    SELECT nullif(current_setting('app.current_user_id', true), '')::bigint
                )
            )
            SQL);
    }

    public function down(): void
    {
        // No eliminar ninguna política de seguridad sin una migración
        // compensatoria y una verificación expresa de dependencias.
        throw new RuntimeException('F1.10 policy: revertir solo con migracion compensatoria revisada.');
    }
};
