<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL 18 portable. Immutable installation registry for global master data.
 * Applied by a narrowly guarded installer, NEVER by generic db:seed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_data_versions', function (Blueprint $table): void {
            $table->string('dataset_key', 120)->primary();
            $table->char('manifest_sha256', 64);
            $table->jsonb('source_sha256');
            $table->jsonb('expected_counts');
            $table->jsonb('verified_counts');
            $table->timestampTz('installed_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            REVOKE ALL ON TABLE public.reference_data_versions FROM PUBLIC;
            REVOKE ALL ON TABLE public.reference_data_versions FROM app_backend;
            SQL);
    }

    public function down(): void
    {
        // Reverting a reference ledger must not silently permit reimport of
        // non-matching regulatory or PCGE data. Requires explicit review.
        throw new RuntimeException('No se elimina automaticamente el registro de datos globales.');
    }
};
