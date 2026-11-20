<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Auditable drafts, never automatically marked as approved IFRS disclosures. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_niif_note_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('note_code', 4);
            $table->unsignedInteger('revision');
            $table->text('narrative');
            $table->string('status', 12)->default('DRAFT');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id','year','note_code','revision'], 'uq_niif_note_company_year_code_revision');
            $table->index(['company_id','year','note_code','created_at'], 'ix_niif_note_history');
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.company_niif_note_revisions
                ADD CONSTRAINT chk_niif_note_code CHECK (note_code ~ '^N(0[1-9]|1[0-6])$'),
                ADD CONSTRAINT chk_niif_note_year CHECK (year BETWEEN 2000 AND 2100),
                ADD CONSTRAINT chk_niif_note_revision CHECK (revision > 0),
                ADD CONSTRAINT chk_niif_note_status CHECK (status = 'DRAFT');
            ALTER TABLE public.company_niif_note_revisions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.company_niif_note_revisions FORCE ROW LEVEL SECURITY;
            CREATE POLICY niif_note_company_policy ON public.company_niif_note_revisions
                FOR ALL TO app_backend
                USING (company_id = public.app_current_company_id())
                WITH CHECK (company_id = public.app_current_company_id());
            REVOKE ALL ON TABLE public.company_niif_note_revisions FROM app_backend;
            GRANT SELECT, INSERT ON TABLE public.company_niif_note_revisions TO app_backend;
            GRANT USAGE, SELECT ON SEQUENCE public.company_niif_note_revisions_id_seq TO app_backend;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Las revisiones de notas NIIF no se eliminan: usar migración compensatoria auditada.');
    }
};
