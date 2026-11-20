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
                ADD CONSTRAINT company_payroll_revision_kind_check
                CHECK (kind IN ('worker','day','attendance','payroll','vacation','benefit','parameter') AND revision > 0),
                DROP CONSTRAINT company_payroll_revisions_check;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('El historial de parámetros de planilla requiere una migración compensatoria auditada.');
    }
};
