<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.company_payroll_revisions
                DROP CONSTRAINT company_payroll_revision_kind_check,
                ADD CONSTRAINT company_payroll_revision_kind_check
                CHECK (kind IN ('worker','day','attendance','payroll','vacation','benefit','parameter','payroll_input') AND revision > 0);
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Las novedades de planilla requieren una migración compensatoria auditada.');
    }
};
