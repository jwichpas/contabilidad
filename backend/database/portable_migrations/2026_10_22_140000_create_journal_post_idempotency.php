<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F2.2 — Immutable tenant-scoped idempotency receipt for a posted voucher.
 * Inserted only after successful POST+audit; it never tracks pending/failed
 * requests. UNIQUE key + transaction-scoped advisory lock stop double posting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_post_idempotency', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->char('request_sha256', 64);
            $table->unsignedBigInteger('journal_entry_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['company_id', 'idempotency_key'], 'uq_journal_post_idempotency_tenant_key');
            $table->unique(['company_id', 'journal_entry_id'], 'uq_journal_post_idempotency_voucher');
            $table->index(['company_id', 'user_id'], 'idx_journal_post_idempotency_actor');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.journal_post_idempotency
                ADD CONSTRAINT fk_journal_post_idempotency_tenant_voucher
                    FOREIGN KEY (company_id, journal_entry_id)
                    REFERENCES public.journal_entries(company_id, id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                ADD CONSTRAINT chk_journal_post_idempotency_sha
                    CHECK (request_sha256 ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT chk_journal_post_idempotency_key
                    CHECK (idempotency_key ~ '^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$');

            ALTER TABLE public.journal_post_idempotency ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.journal_post_idempotency FORCE ROW LEVEL SECURITY;

            CREATE POLICY journal_post_idempotency_read_tenant
                ON public.journal_post_idempotency FOR SELECT TO app_backend
                USING (company_id = public.app_current_company_id());

            CREATE POLICY journal_post_idempotency_insert_tenant
                ON public.journal_post_idempotency FOR INSERT TO app_backend
                WITH CHECK (company_id = public.app_current_company_id());

            REVOKE ALL ON TABLE public.journal_post_idempotency FROM PUBLIC, anon, authenticated, app_backend;
            GRANT SELECT, INSERT ON TABLE public.journal_post_idempotency TO app_backend;
            GRANT USAGE, SELECT ON SEQUENCE public.journal_post_idempotency_id_seq TO app_backend;
        SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('La idempotencia del libro diario no se elimina sin auditoría del historial.');
    }
};
