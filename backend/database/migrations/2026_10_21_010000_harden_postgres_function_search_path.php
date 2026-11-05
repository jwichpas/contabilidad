<?php

declare(strict_types=1);

use App\Support\Database\LocalPg18InstallGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F1.4 - Fija search_path = pg_catalog en cinco funciones existentes.
 * Solo aplica en Supabase Dev autorizado o PostgreSQL CI desechable.
 * No ejecuta DML de negocio y no altera firmas ni cuerpos de funciones.
 */
return new class extends Migration
{
    private const FUNCTIONS = [
        'app_current_company_id',
        'trg_fn_enforce_accounting_account_hierarchy',
        'trg_fn_prevent_journal_entry_mutation',
        'trg_fn_validate_journal_line_context',
        'trg_fn_enforce_double_entry_balance',
    ];

    public function up(): void
    {
        $db = DB::connection('pgsql_migrations');
        $config = $db->getConfig();
        $dbName = (string) ($config['database'] ?? '');
        $host = (string) ($config['host'] ?? '');
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

        if (! $isDisposableCI && ! $isApprovedDev && ! $isApprovedNeon && ! LocalPg18InstallGuard::approved()) {
            throw new RuntimeException('Bloqueo: search_path solo en Supabase Dev aprobado, rama Neon de pruebas o PostgreSQL CI local.');
        }

        $identity = $db->selectOne('SELECT current_user AS db_role, current_database() AS db_name');
        $expectedAdminRole = $isApprovedNeon ? 'neondb_owner' : 'postgres';
        if ($identity?->db_role !== $expectedAdminRole || $identity?->db_name !== $dbName) {
            throw new RuntimeException('La migracion necesita el rol administrador postgres en la base esperada.');
        }

        $db->transaction(function () use ($db): void {
            foreach (self::FUNCTIONS as $functionName) {
                // El nombre es constante de codigo; nunca se interpola entrada del usuario.
                $signature = $db->selectOne(<<<'SQL'
                    SELECT p.oid, CASE WHEN p.prosecdef THEN 1 ELSE 0 END AS definer_flag
                    FROM pg_catalog.pg_proc p
                    JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                    WHERE n.nspname = 'public'
                      AND p.proname = ?
                      AND p.pronargs = 0
                    SQL, [$functionName]);

                if ($signature === null || (int) $signature->definer_flag !== 0) {
                    throw new RuntimeException('Funcion inexistente o con SECURITY DEFINER inesperado: '.$functionName);
                }

                $db->unprepared("ALTER FUNCTION public.{$functionName}() SET search_path = pg_catalog");
            }

            $hardenedCount = (int) ($db->selectOne(<<<'SQL'
                SELECT count(*) AS total
                FROM pg_catalog.pg_proc p
                JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace
                WHERE n.nspname = 'public'
                  AND p.pronargs = 0
                  AND p.proname IN (
                    'app_current_company_id',
                    'trg_fn_enforce_accounting_account_hierarchy',
                    'trg_fn_prevent_journal_entry_mutation',
                    'trg_fn_validate_journal_line_context',
                    'trg_fn_enforce_double_entry_balance'
                  )
                  AND p.prosecdef = false
                  AND 'search_path=pg_catalog' = ANY (p.proconfig)
                SQL)?->total ?? 0);

            if ($hardenedCount !== count(self::FUNCTIONS)) {
                throw new RuntimeException('La auditoria search_path=pg_catalog no confirmo las cinco funciones.');
            }

            // Asegura el funcionamiento de la funcion RLS con search_path vacio.
            $db->select("SELECT set_config('app.current_company_id', '987654', true)");
            $id = $db->selectOne('SELECT public.app_current_company_id() AS company_id');
            if ((int) ($id?->company_id ?? 0) !== 987654) {
                throw new RuntimeException('La funcion de aislamiento fallo despues del cambio search_path.');
            }
            $db->select("SELECT set_config('app.current_company_id', '', true)");
        });
    }

    public function down(): void
    {
        // No restaurar un search_path mutable al retroceder una migracion.
        throw new RuntimeException('No se revierte automaticamente un endurecimiento de seguridad.');
    }
};
