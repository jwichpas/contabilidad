<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive, tenant-scoped master; preserve journal history and original codes.
        Schema::create('company_subdiaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 24);
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->unique(['company_id', 'code'], 'uq_company_subdiaries_code');
            $table->unique(['company_id', 'id'], 'uq_company_subdiaries_tenant_id');
        });

        // Every existing company receives the original eight ERP codes. This
        // does not insert or modify journal entries, fiscal periods or FX data.
        DB::unprepared(<<<'SQL'
            INSERT INTO public.company_subdiaries
                (company_id, code, name, is_active, created_at, updated_at)
            SELECT companies.id, options.code, options.name, TRUE, NOW(), NOW()
            FROM public.companies AS companies
            CROSS JOIN (VALUES
                ('GENERAL', 'Diario general'),
                ('COMPRAS', 'Compras'),
                ('VENTAS', 'Ventas'),
                ('CAJA', 'Caja'),
                ('BANCOS', 'Bancos'),
                ('PLANILLAS', 'Planillas'),
                ('AJUSTES', 'Ajustes'),
                ('APERTURA_CIERRE', 'Apertura y cierre')
            ) AS options(code, name)
            ON CONFLICT (company_id, code) DO NOTHING;

            -- Existing eight-code CHECK is replaced by a tenant-safe composite FK.
            ALTER TABLE public.journal_entries
                DROP CONSTRAINT chk_journal_entries_subdiary;
            ALTER TABLE public.journal_entries
                ADD CONSTRAINT fk_journal_subdiary_tenant_code
                FOREIGN KEY (company_id, subdiary_code)
                REFERENCES public.company_subdiaries (company_id, code)
                ON UPDATE RESTRICT ON DELETE RESTRICT;

            ALTER TABLE public.company_subdiaries ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.company_subdiaries FORCE ROW LEVEL SECURITY;
            CREATE POLICY company_subdiaries_tenant_policy
                ON public.company_subdiaries AS PERMISSIVE FOR ALL TO app_backend
                USING (company_id = public.app_current_company_id())
                WITH CHECK (company_id = public.app_current_company_id());
            REVOKE ALL ON TABLE public.company_subdiaries FROM app_backend;
            GRANT SELECT, INSERT, UPDATE ON TABLE public.company_subdiaries TO app_backend;
            GRANT USAGE, SELECT ON SEQUENCE public.company_subdiaries_id_seq TO app_backend;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('El maestro de subdiarios puede estar referenciado por asientos inmutables; no se elimina automáticamente.');
    }
};
