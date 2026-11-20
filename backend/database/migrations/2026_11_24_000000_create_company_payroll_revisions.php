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
        Schema::create('company_payroll_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('reference', 60);
            $table->unsignedInteger('revision');
            $table->jsonb('payload');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'kind', 'reference', 'revision'], 'uq_payroll_revision');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.company_payroll_revisions
                ADD CHECK (kind IN ('worker','day','attendance','payroll','vacation','benefit') AND revision > 0);
            ALTER TABLE public.company_payroll_revisions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.company_payroll_revisions FORCE ROW LEVEL SECURITY;
            CREATE POLICY payroll_company ON public.company_payroll_revisions
                TO app_backend USING (company_id = (SELECT public.app_current_company_id()))
                WITH CHECK (company_id = (SELECT public.app_current_company_id()));
            REVOKE ALL ON TABLE public.company_payroll_revisions FROM PUBLIC, app_backend;
            GRANT SELECT, INSERT ON TABLE public.company_payroll_revisions TO app_backend;
            GRANT USAGE, SELECT ON SEQUENCE public.company_payroll_revisions_id_seq TO app_backend;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Las revisiones de planilla requieren una migración compensatoria auditada.');
    }
};
