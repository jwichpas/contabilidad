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
        Schema::create('company_daot_document_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('document_id');
            $table->unsignedInteger('revision');
            $table->boolean('included');
            $table->string('reference', 500);
            $table->decimal('base_pen', 16, 4)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'document_id', 'revision'], 'uq_daot_document_review');
            $table->foreign(['company_id', 'document_id'], 'fk_daot_document')
                ->references(['company_id', 'id'])->on('accounting_document_entries')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.company_daot_document_reviews ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.company_daot_document_reviews FORCE ROW LEVEL SECURITY;
            CREATE POLICY daot_document_company ON public.company_daot_document_reviews
                TO app_backend USING (company_id = (SELECT public.app_current_company_id()))
                WITH CHECK (company_id = (SELECT public.app_current_company_id()));
            REVOKE ALL ON TABLE public.company_daot_document_reviews FROM PUBLIC, app_backend;
            GRANT SELECT, INSERT ON TABLE public.company_daot_document_reviews TO app_backend;
            GRANT USAGE, SELECT ON SEQUENCE public.company_daot_document_reviews_id_seq TO app_backend;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Las revisiones del DAOT requieren migración compensatoria auditada.');
    }
};
