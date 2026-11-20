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
        Schema::create('company_annual_tax_close_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('revision');
            $table->string('status', 12);
            $table->string('rule_version', 30);
            $table->jsonb('inputs');
            $table->jsonb('result');
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'year', 'revision'], 'uq_annual_tax_close_revision');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.company_annual_tax_close_revisions
                ADD CHECK (year BETWEEN 2000 AND 2100),
                ADD CHECK (revision > 0),
                ADD CHECK (status IN ('DRAFT', 'IN_REVIEW', 'APPROVED'));
            ALTER TABLE public.company_annual_tax_close_revisions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.company_annual_tax_close_revisions FORCE ROW LEVEL SECURITY;
            CREATE POLICY annual_tax_close_company ON public.company_annual_tax_close_revisions
                FOR ALL TO app_backend
                USING (company_id = public.app_current_company_id())
                WITH CHECK (company_id = public.app_current_company_id());
            REVOKE ALL ON TABLE public.company_annual_tax_close_revisions FROM app_backend;
            GRANT SELECT, INSERT ON TABLE public.company_annual_tax_close_revisions TO app_backend;
            GRANT USAGE, SELECT ON SEQUENCE public.company_annual_tax_close_revisions_id_seq TO app_backend;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('El expediente fiscal requiere una migración compensatoria auditada.');
    }
};
