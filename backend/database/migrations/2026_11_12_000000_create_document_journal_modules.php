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
        Schema::create('company_document_journal_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('module', 10);
            $table->string('subdiary_code', 24);
            $table->unsignedBigInteger('main_account_id');
            $table->unsignedBigInteger('tax_account_id')->nullable();
            $table->unsignedBigInteger('settlement_account_id');
            $table->decimal('default_tax_rate', 7, 4)->default(18);
            $table->timestampsTz();
            $table->unique(['company_id', 'module'], 'uq_document_settings_company_module');
            $table->unique(['company_id', 'id'], 'uq_document_settings_tenant_id');
        });

        Schema::create('accounting_document_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('module', 10);
            $table->char('document_type', 2);
            $table->string('series', 20);
            $table->string('number', 20);
            $table->unsignedBigInteger('entity_id');
            $table->date('issue_date');
            $table->date('entry_date');
            $table->char('currency', 3);
            $table->decimal('exchange_rate', 14, 6);
            $table->decimal('taxable_amount', 14, 4);
            $table->decimal('tax_amount', 14, 4);
            $table->decimal('total_amount', 14, 4);
            $table->unsignedBigInteger('journal_entry_id');
            $table->timestampsTz();
            $table->unique(
                ['company_id', 'module', 'document_type', 'series', 'number', 'entity_id'],
                'uq_accounting_document_identity'
            );
            $table->unique(['company_id', 'journal_entry_id'], 'uq_accounting_document_journal');
            $table->index(['company_id', 'module', 'entry_date'], 'idx_accounting_documents_date');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.company_document_journal_settings
              ADD CONSTRAINT chk_document_settings_module CHECK (module IN ('PURCHASE', 'SALE')),
              ADD CONSTRAINT chk_document_settings_rate CHECK (default_tax_rate BETWEEN 0 AND 100),
              ADD CONSTRAINT fk_doc_settings_subdiary FOREIGN KEY (company_id, subdiary_code)
                REFERENCES public.company_subdiaries(company_id, code) ON UPDATE RESTRICT ON DELETE RESTRICT,
              ADD CONSTRAINT fk_doc_settings_main FOREIGN KEY (company_id, main_account_id)
                REFERENCES public.accounting_accounts(company_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              ADD CONSTRAINT fk_doc_settings_tax FOREIGN KEY (company_id, tax_account_id)
                REFERENCES public.accounting_accounts(company_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              ADD CONSTRAINT fk_doc_settings_settlement FOREIGN KEY (company_id, settlement_account_id)
                REFERENCES public.accounting_accounts(company_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT;

            ALTER TABLE public.accounting_document_entries
              ADD CONSTRAINT chk_accounting_document_module CHECK (module IN ('PURCHASE', 'SALE')),
              ADD CONSTRAINT chk_accounting_document_positive CHECK (
                taxable_amount > 0 AND tax_amount >= 0 AND total_amount = taxable_amount + tax_amount
                AND exchange_rate > 0
              ),
              ADD CONSTRAINT fk_accounting_document_entity FOREIGN KEY (company_id, entity_id)
                REFERENCES public.entities(company_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT,
              ADD CONSTRAINT fk_accounting_document_journal FOREIGN KEY (company_id, journal_entry_id)
                REFERENCES public.journal_entries(company_id, id) ON UPDATE RESTRICT ON DELETE RESTRICT;

            ALTER TABLE public.company_document_journal_settings ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.company_document_journal_settings FORCE ROW LEVEL SECURITY;
            CREATE POLICY document_settings_tenant_policy ON public.company_document_journal_settings
              AS PERMISSIVE FOR ALL TO app_backend
              USING (company_id = public.app_current_company_id())
              WITH CHECK (company_id = public.app_current_company_id());
            REVOKE ALL ON TABLE public.company_document_journal_settings FROM app_backend;
            GRANT SELECT, INSERT, UPDATE ON TABLE public.company_document_journal_settings TO app_backend;
            GRANT USAGE, SELECT ON SEQUENCE public.company_document_journal_settings_id_seq TO app_backend;

            ALTER TABLE public.accounting_document_entries ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.accounting_document_entries FORCE ROW LEVEL SECURITY;
            CREATE POLICY document_entries_tenant_policy ON public.accounting_document_entries
              AS PERMISSIVE FOR ALL TO app_backend
              USING (company_id = public.app_current_company_id())
              WITH CHECK (company_id = public.app_current_company_id());
            REVOKE ALL ON TABLE public.accounting_document_entries FROM app_backend;
            GRANT SELECT, INSERT ON TABLE public.accounting_document_entries TO app_backend;
            GRANT USAGE, SELECT ON SEQUENCE public.accounting_document_entries_id_seq TO app_backend;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Los registros de documentos vinculados a asientos inmutables no se eliminan por rollback.');
    }
};
