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
        // Existing posted lines remain NULL; never invent historical third parties.
        Schema::table('journal_entry_lines', function (Blueprint $table): void {
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->index(['company_id', 'entity_id'], 'idx_journal_lines_company_entity');
        });
        DB::statement('ALTER TABLE public.journal_entry_lines
            ADD CONSTRAINT fk_journal_lines_entity_same_tenant
            FOREIGN KEY (company_id, entity_id) REFERENCES public.entities (company_id, id)
            ON UPDATE RESTRICT ON DELETE RESTRICT');
    }

    public function down(): void
    {
        throw new RuntimeException('No se elimina una dimensión contable con historial sin un plan auditado.');
    }
};
