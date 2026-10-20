<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla maestra de empresas (tenants) `companies`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('tax_id', 20)->unique();
            $table->string('business_name', 200);
            $table->string('legal_address', 300);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['is_active', 'id'], 'idx_companies_active_id');
        });

        DB::unprepared(<<<'SQL'
        ALTER TABLE public.companies
            ADD CONSTRAINT chk_companies_tax_id_non_empty
            CHECK (char_length(btrim(tax_id)) >= 8);

        GRANT SELECT, INSERT, UPDATE ON TABLE public.companies TO app_backend;
        GRANT USAGE, SELECT ON SEQUENCE public.companies_id_seq TO app_backend;
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
