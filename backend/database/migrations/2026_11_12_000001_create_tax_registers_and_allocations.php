<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registros tributarios separados del Libro Diario. Estos asientos de registro
 * son insumos internos para futura conciliación RCE/RVIE; NO son un archivo SIRE.
 * La clasificación se conserva por componente, no como un flag en el voucher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_document_journal_settings', function (Blueprint $table): void {
            $table->jsonb('category_account_map')->nullable();
        });

        Schema::table('accounting_document_entries', function (Blueprint $table): void {
            $table->unique(['company_id', 'id'], 'uq_accounting_document_tenant_id');
        });

        Schema::create('accounting_tax_register_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('accounting_document_entry_id');
            $table->string('module', 10);
            $table->char('tax_period', 6);
            $table->string('source', 16)->default('INTERNAL');
            $table->string('reconciliation_status', 24)->default('PENDING_REVIEW');
            $table->string('counterparty_identity_type', 2);
            $table->string('counterparty_tax_id', 20);
            $table->string('counterparty_name', 200);
            $table->date('due_date')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'id'], 'uq_tax_register_tenant_entry');
            $table->unique(['company_id', 'accounting_document_entry_id'], 'uq_tax_register_document');
            $table->index(['company_id', 'module', 'tax_period'], 'idx_tax_register_period');
        });

        Schema::create('accounting_tax_register_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('tax_register_entry_id');
            $table->string('category', 30);
            $table->decimal('base_amount', 14, 4);
            $table->decimal('tax_amount', 14, 4)->default(0);
            $table->decimal('creditable_tax_amount', 14, 4)->default(0);
            $table->timestampsTz();
            $table->unique(['company_id', 'tax_register_entry_id', 'category'], 'uq_tax_register_category');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.accounting_tax_register_entries
              ADD CONSTRAINT chk_tax_register_module CHECK (module IN ('PURCHASE', 'SALE')),
              ADD CONSTRAINT chk_tax_register_period CHECK (tax_period ~ '^[0-9]{4}(0[1-9]|1[0-2])$'),
              ADD CONSTRAINT chk_tax_register_source CHECK (source IN ('INTERNAL')),
              ADD CONSTRAINT chk_tax_register_reconciliation CHECK (reconciliation_status IN ('PENDING_REVIEW')),
              ADD CONSTRAINT fk_tax_register_document_same_tenant FOREIGN KEY (company_id, accounting_document_entry_id)
                REFERENCES public.accounting_document_entries(company_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT;

            ALTER TABLE public.accounting_tax_register_allocations
              ADD CONSTRAINT chk_tax_register_category CHECK (
                category IN (
                  'P_TAXED_EXCLUSIVE', 'P_TAXED_MIXED', 'P_TAXED_NON_TAXED',
                  'P_NON_TAXED', 'S_TAXED', 'S_EXONERATED', 'S_UNAFFECTED', 'S_EXPORT'
                )
              ),
              ADD CONSTRAINT chk_tax_allocation_values CHECK (
                base_amount > 0 AND tax_amount >= 0 AND creditable_tax_amount >= 0
                AND creditable_tax_amount <= tax_amount
              ),
              ADD CONSTRAINT fk_tax_register_allocation_same_tenant FOREIGN KEY (company_id, tax_register_entry_id)
                REFERENCES public.accounting_tax_register_entries(company_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT;

            ALTER TABLE public.accounting_tax_register_entries ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.accounting_tax_register_entries FORCE ROW LEVEL SECURITY;
            CREATE POLICY tax_register_tenant_policy ON public.accounting_tax_register_entries
              AS PERMISSIVE FOR ALL TO app_backend
              USING (company_id = public.app_current_company_id())
              WITH CHECK (company_id = public.app_current_company_id());
            REVOKE ALL ON TABLE public.accounting_tax_register_entries FROM app_backend;
            GRANT SELECT, INSERT ON TABLE public.accounting_tax_register_entries TO app_backend;
            GRANT USAGE, SELECT ON SEQUENCE public.accounting_tax_register_entries_id_seq TO app_backend;

            ALTER TABLE public.accounting_tax_register_allocations ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.accounting_tax_register_allocations FORCE ROW LEVEL SECURITY;
            CREATE POLICY tax_allocation_tenant_policy ON public.accounting_tax_register_allocations
              AS PERMISSIVE FOR ALL TO app_backend
              USING (company_id = public.app_current_company_id())
              WITH CHECK (company_id = public.app_current_company_id());
            REVOKE ALL ON TABLE public.accounting_tax_register_allocations FROM app_backend;
            GRANT SELECT, INSERT ON TABLE public.accounting_tax_register_allocations TO app_backend;
            GRANT USAGE, SELECT ON SEQUENCE public.accounting_tax_register_allocations_id_seq TO app_backend;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Los registros tributarios respaldan asientos inmutables y no se eliminan en rollback.');
    }
};
