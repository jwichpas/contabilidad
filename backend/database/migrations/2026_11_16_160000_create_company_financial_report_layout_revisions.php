<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only layout revisions per company AND statement.
 * No journal, balance or standard reference table is modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_financial_report_layout_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('statement', 8);
            $table->unsignedInteger('revision');
            $table->jsonb('settings');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'statement', 'revision'], 'uq_financial_layout_company_statement_revision');
            $table->index(['company_id', 'statement', 'created_at'], 'ix_financial_layout_history');
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.company_financial_report_layout_revisions
                ADD CONSTRAINT chk_financial_layout_statement CHECK (statement IN ('ESF','ERI','EFE','ECP')),
                ADD CONSTRAINT chk_financial_layout_revision CHECK (revision > 0);
            ALTER TABLE public.company_financial_report_layout_revisions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.company_financial_report_layout_revisions FORCE ROW LEVEL SECURITY;
            CREATE POLICY company_financial_layout_tenant_policy
                ON public.company_financial_report_layout_revisions AS PERMISSIVE
                FOR ALL TO app_backend
                USING (company_id = public.app_current_company_id())
                WITH CHECK (company_id = public.app_current_company_id());
            REVOKE ALL ON TABLE public.company_financial_report_layout_revisions FROM app_backend;
            GRANT SELECT, INSERT ON TABLE public.company_financial_report_layout_revisions TO app_backend;
            GRANT USAGE, SELECT ON SEQUENCE public.company_financial_report_layout_revisions_id_seq TO app_backend;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('No se eliminan historiales de configuraciones financieras: aplicar migración compensatoria auditada.');
    }
};
