<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F3.1 — Perfil legal y actividades económicas de la empresa.
 *
 * Se ejecuta desde Laravel: php artisan migrate --database=pgsql_migrations
 * Se aplica DESPUÉS de la migración de revocación de permisos públicos.
 * No reescribe tablas existentes ni elimina datos de desarrollo.
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
            throw new \RuntimeException('La migración de perfil económico requiere contabilidad-database.');
        }

        Schema::connection('pgsql_migrations')->table('company_settings', function (Blueprint $table): void {
            // Nulo hasta que el contador identifique/valide la naturaleza jurídica.
            $table->string('legal_form', 32)->nullable();
        });

        Schema::connection('pgsql_migrations')->create('company_economic_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('sector_code', 32);
            $table->string('ciiu_code', 4);
            $table->string('description', 200);
            $table->boolean('is_primary')->default(false);
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
