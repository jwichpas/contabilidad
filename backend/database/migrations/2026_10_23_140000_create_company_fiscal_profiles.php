<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F3.3: append-only fiscal policy by tenant, fiscal year and effective date.
 *
 * No historical company_settings rows are rewritten or guessed; version 1 is
 * explicitly entered/approved through the HTTP API. Requires owner migration
 * connection; app_backend gets SELECT/INSERT only (no UPDATE, DELETE, DDL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_fiscal_profiles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedSmallInteger('revision');
            $table->date('effective_date');
            $table->string('tax_regime', 40);
            $table->string('legal_form', 40)->nullable();
            $table->unsignedSmallInteger('income_reference_year')->nullable();
            $table->decimal('annual_income', 20, 2)->nullable();
            $table->decimal('default_vat_rate', 5, 2);
            $table->boolean('is_retention_agent')->default(false);
            $table->boolean('is_perception_agent')->default(false);
            $table->boolean('is_good_taxpayer')->default(false);
            $table->boolean('is_sire_obliged')->default(true);
            $table->boolean('is_ple_obliged')->default(false);
            $table->string('change_reason', 500);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['company_id', 'id'], 'uq_fiscal_profiles_company_id');
            $table->unique(['company_id', 'fiscal_year', 'revision'], 'uq_fiscal_profiles_revision');
            $table->unique(['company_id', 'fiscal_year', 'effective_date'], 'uq_fiscal_profiles_effective');
            $table->index(['company_id', 'fiscal_year', 'effective_date'], 'idx_fiscal_profiles_timeline');
        });

        DB::unprepared(<<<'SQL'
        ALTER TABLE public.company_fiscal_profiles
          ADD CONSTRAINT ck_fiscal_profiles_year CHECK (fiscal_year BETWEEN 2000 AND 2100),
          ADD CONSTRAINT ck_fiscal_profiles_revision CHECK (revision >= 1),
          ADD CONSTRAINT ck_fiscal_profiles_effective_year CHECK (
            EXTRACT(YEAR FROM effective_date)::integer = fiscal_year
          ),
          ADD CONSTRAINT ck_fiscal_profiles_reference_year CHECK (
            income_reference_year IS NULL OR income_reference_year <= fiscal_year
          ),
          ADD CONSTRAINT ck_fiscal_profiles_income CHECK (
            annual_income IS NULL OR annual_income >= 0
          ),
          ADD CONSTRAINT ck_fiscal_profiles_vat CHECK (
            default_vat_rate >= 0 AND default_vat_rate <= 100
          ),
          ADD CONSTRAINT ck_fiscal_profiles_tax_regime CHECK (
            tax_regime IN ('GENERAL','MYPE_TRIBUTARIO','RER','ESPECIAL','NRUS')
          ),
          ADD CONSTRAINT ck_fiscal_profiles_reason CHECK (length(trim(change_reason)) >= 10);

        ALTER TABLE public.company_fiscal_profiles ENABLE ROW LEVEL SECURITY;
        ALTER TABLE public.company_fiscal_profiles FORCE ROW LEVEL SECURITY;

        CREATE POLICY fiscal_profiles_tenant_isolation_policy
          ON public.company_fiscal_profiles
          TO app_backend
          USING (company_id = (SELECT public.app_current_company_id()))
          WITH CHECK (company_id = (SELECT public.app_current_company_id()));

        REVOKE ALL ON TABLE public.company_fiscal_profiles FROM PUBLIC;
        REVOKE ALL ON TABLE public.company_fiscal_profiles FROM app_backend;
        GRANT SELECT, INSERT ON TABLE public.company_fiscal_profiles TO app_backend;
        GRANT USAGE, SELECT ON SEQUENCE public.company_fiscal_profiles_id_seq TO app_backend;

        CREATE OR REPLACE FUNCTION public.reject_fiscal_profile_mutation()
        RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog, public AS $$
        BEGIN
          RAISE EXCEPTION 'Fiscal policy revisions are immutable: add a new revision'
              USING ERRCODE = '23514';
        END;
        $$;

        CREATE TRIGGER trg_fiscal_profiles_no_update_delete
        BEFORE UPDATE OR DELETE ON public.company_fiscal_profiles
        FOR EACH ROW EXECUTE FUNCTION public.reject_fiscal_profile_mutation();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('company_fiscal_profiles');
        DB::unprepared('DROP FUNCTION IF EXISTS public.reject_fiscal_profile_mutation()');
    }
};
