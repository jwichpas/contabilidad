<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Tenant preferences are append-only; the published SEE editions remain global and immutable. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_migrations')->create('company_see_visibility_revisions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('catalog_number', 2);
            $table->string('source_version', 40);
            $table->unsignedBigInteger('revision');
            $table->jsonb('visible_codes');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'catalog_number', 'revision'], 'uq_company_see_visibility_revision');
        });

        DB::connection('pgsql_migrations')->unprepared(<<<'SQL'
            ALTER TABLE public.company_see_visibility_revisions
                ADD CONSTRAINT chk_company_see_visibility_catalog CHECK (catalog_number IN ('03', '04'));
            ALTER TABLE public.company_see_visibility_revisions
                ADD CONSTRAINT chk_company_see_visibility_codes CHECK (jsonb_typeof(visible_codes) = 'array');
            ALTER TABLE public.company_see_visibility_revisions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.company_see_visibility_revisions FORCE ROW LEVEL SECURITY;
            CREATE POLICY company_see_visibility_tenant_policy
                ON public.company_see_visibility_revisions FOR ALL TO app_backend
                USING (company_id = public.app_current_company_id())
                WITH CHECK (company_id = public.app_current_company_id());
            REVOKE ALL ON public.company_see_visibility_revisions FROM PUBLIC;
            REVOKE ALL ON SEQUENCE public.company_see_visibility_revisions_id_seq FROM PUBLIC;
            GRANT SELECT, INSERT ON public.company_see_visibility_revisions TO app_backend;
            GRANT USAGE, SELECT ON SEQUENCE public.company_see_visibility_revisions_id_seq TO app_backend;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Las revisiones de visibilidad son históricas; requieren una migración compensatoria revisada.');
    }
};
