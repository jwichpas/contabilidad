<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F2.2: versioned, tenant-owned routes from operational class-6 accounts
 * and cost centers to class-9 debit and class-79 credit accounts.
 *
 * Does NOT overwrite historical PCGE destination_debit_code/credit_code,
 * activate POST automation or modify any journal entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_destination_rule_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('version_key', 50);
            $table->string('strategy', 30)->default('COST_CENTER');
            $table->string('status', 16)->default('DRAFT');
            $table->unsignedInteger('source_count');
            $table->unsignedInteger('route_count');
            $table->char('matrix_sha256', 64);
            $table->timestampTz('installed_at')->useCurrent();
            $table->unique(['company_id', 'id'], 'uq_dest_ver_tenant_id');
            $table->unique(['company_id', 'version_key'], 'uq_dest_ver_tenant_key');
        });

        Schema::create('accounting_destination_routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('rule_version_id');
            $table->unsignedBigInteger('source_account_id');
            $table->unsignedBigInteger('cost_center_id');
            $table->unsignedBigInteger('destination_debit_account_id');
            $table->unsignedBigInteger('destination_credit_account_id');
            $table->unique(
                ['company_id', 'rule_version_id', 'source_account_id', 'cost_center_id'],
                'uq_dest_routes_version_source_center'
            );
            $table->index(['company_id', 'cost_center_id'], 'idx_dest_routes_center');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE public.accounting_destination_rule_versions
              ADD CONSTRAINT chk_dest_version_strategy
                CHECK (strategy = 'COST_CENTER'),
              ADD CONSTRAINT chk_dest_version_status
                CHECK (status IN ('DRAFT', 'ACTIVE', 'RETIRED'));

            ALTER TABLE public.accounting_destination_routes
              ADD CONSTRAINT fk_dest_route_version_tenant
                FOREIGN KEY (company_id, rule_version_id)
                REFERENCES public.accounting_destination_rule_versions(company_id, id)
                ON DELETE RESTRICT,
              ADD CONSTRAINT fk_dest_route_source_tenant
                FOREIGN KEY (company_id, source_account_id)
                REFERENCES public.accounting_accounts(company_id, id)
                ON DELETE RESTRICT,
              ADD CONSTRAINT fk_dest_route_center_tenant
                FOREIGN KEY (company_id, cost_center_id)
                REFERENCES public.cost_centers(company_id, id)
                ON DELETE RESTRICT,
              ADD CONSTRAINT fk_dest_route_debit_tenant
                FOREIGN KEY (company_id, destination_debit_account_id)
                REFERENCES public.accounting_accounts(company_id, id)
                ON DELETE RESTRICT,
              ADD CONSTRAINT fk_dest_route_credit_tenant
                FOREIGN KEY (company_id, destination_credit_account_id)
                REFERENCES public.accounting_accounts(company_id, id)
                ON DELETE RESTRICT,
              ADD CONSTRAINT chk_dest_route_distinct
                CHECK (destination_debit_account_id <> destination_credit_account_id);

            ALTER TABLE public.accounting_destination_rule_versions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.accounting_destination_rule_versions FORCE ROW LEVEL SECURITY;
            ALTER TABLE public.accounting_destination_routes ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.accounting_destination_routes FORCE ROW LEVEL SECURITY;

            CREATE POLICY accounting_dest_version_tenant_read
              ON public.accounting_destination_rule_versions
              FOR SELECT TO app_backend
              USING (company_id = public.app_current_company_id());

            CREATE POLICY accounting_dest_routes_tenant_read
              ON public.accounting_destination_routes
              FOR SELECT TO app_backend
              USING (company_id = public.app_current_company_id());

            REVOKE ALL ON public.accounting_destination_rule_versions FROM PUBLIC, app_backend;
            REVOKE ALL ON public.accounting_destination_routes FROM PUBLIC, app_backend;
            GRANT SELECT ON public.accounting_destination_rule_versions TO app_backend;
            GRANT SELECT ON public.accounting_destination_routes TO app_backend;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Las matrices de imputación versionadas requieren reversión auditada.');
    }
};
