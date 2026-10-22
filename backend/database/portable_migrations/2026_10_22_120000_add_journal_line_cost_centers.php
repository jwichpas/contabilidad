<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F2.3a: optional cost center dimension for immutable journal lines.
 *
 * Does NOT activate automatic 94/95 vs 79 postings. The composite FK ensures
 * the cost center always belongs to the same tenant as the journal line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('cost_center_id')->nullable();
            $table->index(['company_id', 'cost_center_id'], 'idx_journal_lines_company_cost_center');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.journal_entry_lines
                ADD CONSTRAINT fk_journal_lines_cost_center_same_tenant
                FOREIGN KEY (company_id, cost_center_id)
                REFERENCES public.cost_centers (company_id, id)
                ON UPDATE RESTRICT
                ON DELETE RESTRICT;
            SQL);
    }

    public function down(): void
    {
        // Journal lines are immutable; silently removing a dimension would
        // destroy the audit trail once vouchers have been recorded.
        throw new RuntimeException('No se elimina una dimension contable sin plan de reversión auditada.');
    }
};
