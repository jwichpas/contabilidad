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
        Schema::create('company_monthly_work_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->date('period');
            $table->string('code', 48);
            $table->unsignedInteger('revision');
            $table->jsonb('payload');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'period', 'code', 'revision'], 'uq_monthly_work_revision');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.company_monthly_work_revisions
                ADD CHECK (EXTRACT(DAY FROM period) = 1 AND revision > 0);
            ALTER TABLE public.company_monthly_work_revisions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.company_monthly_work_revisions FORCE ROW LEVEL SECURITY;
            CREATE POLICY monthly_work_company ON public.company_monthly_work_revisions
                TO app_backend USING (company_id = (SELECT public.app_current_company_id()))
                WITH CHECK (company_id = (SELECT public.app_current_company_id()));
            REVOKE ALL ON TABLE public.company_monthly_work_revisions FROM PUBLIC, app_backend;
            GRANT SELECT, INSERT ON TABLE public.company_monthly_work_revisions TO app_backend;
            GRANT USAGE, SELECT ON SEQUENCE public.company_monthly_work_revisions_id_seq TO app_backend;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('La agenda mensual requiere migración compensatoria auditada.');
    }
};
