<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Immutable, tenant-scoped versions of cash flow presentation only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_cash_flow_presentation_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->jsonb('settings');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'revision'], 'uq_cash_flow_presentation_company_revision');
            $table->index(['company_id', 'created_at'], 'idx_cash_flow_presentation_history');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.company_cash_flow_presentation_revisions
              ADD CONSTRAINT chk_cash_flow_revision CHECK (revision > 0);
            ALTER TABLE public.company_cash_flow_presentation_revisions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.company_cash_flow_presentation_revisions FORCE ROW LEVEL SECURITY;
            CREATE POLICY cash_flow_presentation_tenant_policy
              ON public.company_cash_flow_presentation_revisions AS PERMISSIVE
              FOR ALL TO app_backend
              USING (company_id = public.app_current_company_id())
              WITH CHECK (company_id = public.app_current_company_id());
            REVOKE ALL ON TABLE public.company_cash_flow_presentation_revisions FROM app_backend;
            GRANT SELECT, INSERT ON TABLE public.company_cash_flow_presentation_revisions TO app_backend;
            GRANT USAGE, SELECT ON SEQUENCE public.company_cash_flow_presentation_revisions_id_seq TO app_backend;
            SQL);
    }

    public function down(): void
    {
        // Historical presentation revisions are append-only, including under rollback.
        throw new RuntimeException('Las revisiones contables de presentación requieren una migración compensatoria revisada.');
    }
};
