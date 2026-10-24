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
        Schema::table('journal_entry_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_area_id')->nullable();
            $table->unsignedBigInteger('cash_flow_area_id')->nullable();
            $table->index(['company_id', 'company_area_id'], 'idx_journal_lines_company_area');
            $table->index(['company_id', 'cash_flow_area_id'], 'idx_journal_lines_cash_flow_area');
        });
        DB::statement('ALTER TABLE public.journal_entry_lines
            ADD CONSTRAINT fk_journal_lines_company_area_same_tenant
            FOREIGN KEY (company_id, company_area_id) REFERENCES public.company_areas (company_id, id)
            ON UPDATE RESTRICT ON DELETE RESTRICT,
            ADD CONSTRAINT fk_journal_lines_cash_flow_area_same_tenant
            FOREIGN KEY (company_id, cash_flow_area_id) REFERENCES public.cash_flow_areas (company_id, id)
            ON UPDATE RESTRICT ON DELETE RESTRICT');
    }

    public function down(): void
    {
        throw new RuntimeException('No se eliminan dimensiones contables con historial sin un plan auditado.');
    }
};
