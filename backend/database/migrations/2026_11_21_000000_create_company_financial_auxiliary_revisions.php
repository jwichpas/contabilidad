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
        Schema::create('company_financial_auxiliary_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('kind', 8);
            $table->string('reference', 40);
            $table->unsignedInteger('revision');
            $table->string('label', 160);
            $table->string('source_reference', 255);
            $table->jsonb('inputs');
            $table->jsonb('result');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'kind', 'reference', 'revision'], 'uq_fin_aux_revision');
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.company_financial_auxiliary_revisions
                ADD CHECK (kind IN ('loan', 'asset')), ADD CHECK (revision > 0);
            ALTER TABLE public.company_financial_auxiliary_revisions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.company_financial_auxiliary_revisions FORCE ROW LEVEL SECURITY;
            CREATE POLICY financial_auxiliary_company ON public.company_financial_auxiliary_revisions
                FOR ALL TO app_backend USING (company_id = public.app_current_company_id())
                WITH CHECK (company_id = public.app_current_company_id());
            REVOKE ALL ON TABLE public.company_financial_auxiliary_revisions FROM app_backend;
            GRANT SELECT, INSERT ON TABLE public.company_financial_auxiliary_revisions TO app_backend;
            GRANT USAGE, SELECT ON SEQUENCE public.company_financial_auxiliary_revisions_id_seq TO app_backend;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Las fichas de auxiliares requieren migración compensatoria auditada.');
    }
};
