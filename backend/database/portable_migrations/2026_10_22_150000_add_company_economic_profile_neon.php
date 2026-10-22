<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F3.1 — Migración PORTABLE para Neon PostgreSQL 18 DEV.
 * Mantiene empresas/usuarios existentes; no mezcla el path antiguo exclusivo de Supabase.
 * Requiere ejecucion aislada mediante Artisan pgsql_migrations y verificaciones del script F3.1.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection('pgsql_migrations');
        $config = $db->getConfig();
        $host = 'ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
        $who = $db->selectOne(
            'SELECT current_user AS actor, current_database() AS db, current_setting(\'server_version_num\')::integer AS version'
        );
        if (! app()->environment('local')
            || env('NEON_TEST_BRANCH_NAME') !== 'contabilidad-dev'
            || env('NEON_TEST_BRANCH_HOST') !== $host
            || ($config['host'] ?? '') !== $host
            || (string) ($config['port'] ?? '') !== '5432'
            || ($config['database'] ?? '') !== 'neondb'
            || ($config['username'] ?? '') !== 'neondb_owner'
            || trim((string) ($config['url'] ?? '')) !== ''
            || ! in_array((string) ($config['sslmode'] ?? ''), ['require', 'verify-ca', 'verify-full'], true)
            || $who?->actor !== 'neondb_owner'
            || $who?->db !== 'neondb'
            || intdiv((int) ($who?->version ?? 0), 10000) !== 18) {
            throw new \RuntimeException('F3.1: solo rol migrador, PostgreSQL 18 Neon DEV y SSL directo.');
        }
        // Guardas de datos: solo instalar sobre la rama de desarrollo acordada.
        // El instalador migratorio registra la fila #23 DESPUES de up().
        $baseline = $db->selectOne(<<<'SQL'
            SELECT
                (SELECT count(*) FROM public.migrations)::integer AS migrations,
                (SELECT count(*) FROM public.companies)::integer AS companies,
                (SELECT count(*) FROM public.users)::integer AS users,
                (SELECT count(*) FROM public.journal_entries)::integer AS entries,
                (SELECT count(*) FROM public.fiscal_periods)::integer AS periods,
                (SELECT count(*) FROM public.fiscal_periods
                    WHERE year=2026 AND month=1 AND status='OPEN')::integer AS january_open,
                (SELECT count(*) FROM public.accounting_account_templates)::integer AS templates
            SQL);
        if (! $baseline || (int) $baseline->migrations !== 22
            || (int) $baseline->companies !== 3
            || (int) $baseline->users !== 3
            || (int) $baseline->entries !== 0
            || (int) $baseline->periods !== 1
            || (int) $baseline->january_open !== 1
            || (int) $baseline->templates !== 3401) {
            throw new \RuntimeException('F3.1: baseline de tres empresas y enero abierto no coincide.');
        }

        if (Schema::connection('pgsql_migrations')->hasTable('company_economic_activities')
            || Schema::connection('pgsql_migrations')->hasColumn('company_settings', 'legal_form')) {
            throw new \RuntimeException('F3.1: esquema ya modificado o migracion previa parcial; revisar antes de continuar.');
        }

        Schema::connection('pgsql_migrations')->table('company_settings', function (Blueprint $table): void {
            // Nulo hasta que el contador identifique/valide la naturaleza jurídica.
            $table->string('legal_form', 32)->nullable();
            // Datos de apertura. Nulos para empresas existentes; se completan al
            // usar el asistente, sin alterar ejercicios o saldos anteriores.
            $table->unsignedSmallInteger('initial_fiscal_year')->nullable();
            $table->unsignedSmallInteger('income_reference_year')->nullable();
            $table->decimal('annual_income', 16, 2)->nullable();
            $table->string('initial_pcge_version', 16)->nullable();
        });

        Schema::connection('pgsql_migrations')->create('company_economic_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('sector_code', 32);
            $table->string('ciiu_code', 4);
            $table->string('description', 200);
            $table->boolean('is_primary')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['company_id', 'id'], 'uq_company_economic_activities_tenant_id');
            $table->unique(['company_id', 'ciiu_code'], 'uq_company_economic_activities_company_ciiu');
            $table->index(['company_id', 'sector_code'], 'idx_company_economic_activities_sector');
        });

        $db->unprepared(<<<'SQL'
            ALTER TABLE public.company_settings
                ADD CONSTRAINT chk_company_settings_legal_form
                CHECK (legal_form IS NULL OR legal_form IN (
                    'PERSONA_NATURAL', 'EIRL', 'SAC', 'SRL', 'SA', 'SAA', 'SACS', 'OTRA'
                ));

            ALTER TABLE public.company_settings
                ADD CONSTRAINT chk_company_settings_initial_pcge
                CHECK (initial_pcge_version IS NULL OR initial_pcge_version IN ('PCGE_2019', 'PCGE_2026'));
            ALTER TABLE public.company_settings
                ADD CONSTRAINT chk_company_settings_years
                CHECK (
                    (initial_fiscal_year IS NULL OR initial_fiscal_year BETWEEN 2000 AND 2100)
                    AND (income_reference_year IS NULL OR income_reference_year BETWEEN 2000 AND 2100)
                    AND (income_reference_year IS NULL OR initial_fiscal_year IS NULL
                         OR income_reference_year <= initial_fiscal_year)
                );
            ALTER TABLE public.company_settings
                ADD CONSTRAINT chk_company_settings_annual_income
                CHECK (annual_income IS NULL OR annual_income >= 0);

            ALTER TABLE public.company_economic_activities
                ADD CONSTRAINT chk_company_economic_activities_sector
                CHECK (sector_code IN (
                    'COMERCIAL', 'SERVICIOS', 'INDUSTRIAL', 'AGRICOLA',
                    'GANADERIA', 'PESCA', 'CONSTRUCCION', 'EXTRACTIVA',
                    'TRANSPORTE_LOGISTICA', 'OTROS'
                ));
            ALTER TABLE public.company_economic_activities
                ADD CONSTRAINT chk_company_economic_activities_ciiu
                CHECK (ciiu_code ~ '^[0-9]{4}$');
            ALTER TABLE public.company_economic_activities
                ADD CONSTRAINT chk_company_economic_activities_description
                CHECK (length(btrim(description)) > 0);

            CREATE UNIQUE INDEX uq_company_economic_activities_one_primary
                ON public.company_economic_activities (company_id)
                WHERE is_primary = true;

            ALTER TABLE public.company_economic_activities ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.company_economic_activities FORCE ROW LEVEL SECURITY;
            CREATE POLICY company_economic_activities_tenant_policy
                ON public.company_economic_activities
                FOR ALL TO app_backend
                USING (company_id = public.app_current_company_id())
                WITH CHECK (company_id = public.app_current_company_id());

            GRANT SELECT, INSERT, UPDATE, DELETE
                ON public.company_economic_activities TO app_backend;
            GRANT USAGE, SELECT ON SEQUENCE
                public.company_economic_activities_id_seq TO app_backend;
            SQL);
    }

    public function down(): void
    {
        // Esta tabla almacena clasificación histórica. Nunca borrar datos
        // inadvertidamente con rollback automático.
        throw new \RuntimeException(
            'Migración irreversible automáticamente. Conservar el perfil económico y crear una migración compensatoria revisada.'
        );
    }
};
