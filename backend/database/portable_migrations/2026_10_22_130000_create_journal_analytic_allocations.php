<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F2.3b: immutable lineage from a natural expense line to its two analytic
 * counterpart lines (94/95 vs 79), resolved under one versioned cost-center rule.
 * No automatic posting is enabled by installing this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        // References also encode the same voucher, not only the same company.
        Schema::table('journal_entry_lines', function (Blueprint $table): void {
            $table->unique(
                ['company_id', 'journal_entry_id', 'id'],
                'uq_journal_lines_tenant_voucher_id'
            );
        });

        Schema::create('journal_analytic_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('journal_entry_id');
            $table->unsignedBigInteger('source_line_id');
            $table->unsignedBigInteger('debit_line_id');
            $table->unsignedBigInteger('credit_line_id');
            $table->unsignedBigInteger('rule_version_id');
            $table->unsignedBigInteger('cost_center_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['company_id', 'journal_entry_id', 'source_line_id'],
                'uq_analytic_allocation_source_once'
            );
            $table->unique(
                ['company_id', 'journal_entry_id', 'debit_line_id'],
                'uq_analytic_allocation_debit_once'
            );
            $table->unique(
                ['company_id', 'journal_entry_id', 'credit_line_id'],
                'uq_analytic_allocation_credit_once'
            );
            $table->index(['company_id', 'rule_version_id'], 'idx_analytic_alloc_version');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.journal_analytic_allocations
              ADD CONSTRAINT fk_analytic_alloc_entry_tenant
                FOREIGN KEY (company_id, journal_entry_id)
                REFERENCES public.journal_entries(company_id, id) ON DELETE RESTRICT,
              ADD CONSTRAINT fk_analytic_alloc_source_same_voucher
                FOREIGN KEY (company_id, journal_entry_id, source_line_id)
                REFERENCES public.journal_entry_lines(company_id, journal_entry_id, id)
                ON DELETE RESTRICT,
              ADD CONSTRAINT fk_analytic_alloc_debit_same_voucher
                FOREIGN KEY (company_id, journal_entry_id, debit_line_id)
                REFERENCES public.journal_entry_lines(company_id, journal_entry_id, id)
                ON DELETE RESTRICT,
              ADD CONSTRAINT fk_analytic_alloc_credit_same_voucher
                FOREIGN KEY (company_id, journal_entry_id, credit_line_id)
                REFERENCES public.journal_entry_lines(company_id, journal_entry_id, id)
                ON DELETE RESTRICT,
              ADD CONSTRAINT fk_analytic_alloc_version_tenant
                FOREIGN KEY (company_id, rule_version_id)
                REFERENCES public.accounting_destination_rule_versions(company_id, id)
                ON DELETE RESTRICT,
              ADD CONSTRAINT fk_analytic_alloc_center_tenant
                FOREIGN KEY (company_id, cost_center_id)
                REFERENCES public.cost_centers(company_id, id)
                ON DELETE RESTRICT,
              ADD CONSTRAINT chk_analytic_alloc_distinct_lines
                CHECK (source_line_id <> debit_line_id AND source_line_id <> credit_line_id
                  AND debit_line_id <> credit_line_id);

            CREATE UNIQUE INDEX uq_dest_versions_one_active_per_company
                ON public.accounting_destination_rule_versions(company_id)
                WHERE status = 'ACTIVE';

            ALTER TABLE public.journal_analytic_allocations ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.journal_analytic_allocations FORCE ROW LEVEL SECURITY;

            CREATE POLICY analytic_alloc_tenant_read
                ON public.journal_analytic_allocations
                FOR SELECT TO app_backend
                USING (company_id = public.app_current_company_id());

            CREATE POLICY analytic_alloc_tenant_insert
                ON public.journal_analytic_allocations
                FOR INSERT TO app_backend
                WITH CHECK (company_id = public.app_current_company_id());

            REVOKE ALL ON public.journal_analytic_allocations FROM PUBLIC, app_backend;
            GRANT SELECT, INSERT ON public.journal_analytic_allocations TO app_backend;
            GRANT USAGE, SELECT ON SEQUENCE public.journal_analytic_allocations_id_seq TO app_backend;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Los amarres analíticos contables no se eliminan sin reversión auditada.');
    }
};
