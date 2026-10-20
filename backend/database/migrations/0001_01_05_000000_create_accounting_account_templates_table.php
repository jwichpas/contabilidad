<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla de Plantillas Matrices de Planes Contables (`accounting_account_templates`)
 * para soportar el PCGE 2019 oficial y el PCGE 2026 (NIIF 18 / NIIF 19).
 * Amplía los niveles de `accounting_accounts` hasta nivel 6 (para admitir subdivisionarias y analíticas)
 * y flexibiliza la regla operativa para hojas de nivel >= 3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_account_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('plan_version', 15); // 'PCGE_2019', 'PCGE_2026'
            $table->string('code', 20);
            $table->string('name', 255);
            $table->smallInteger('level');
            $table->string('parent_code', 20)->nullable();
            $table->text('path');
            $table->string('account_type', 20);
            $table->string('balance_type', 10);
            $table->string('currency_mode', 10)->default('PEN');
            $table->string('entity_requirement_type', 30)->default('NONE');
            $table->boolean('requires_cost_center')->default(false);
            $table->boolean('has_automatic_destination')->default(false);
            $table->string('destination_debit_code', 20)->nullable();
            $table->string('destination_credit_code', 20)->nullable();
            $table->boolean('requires_cash_flow_area')->default(false);
            $table->string('cash_flow_category', 30)->default('NONE');
            $table->string('financial_statement_rubric', 40)->default('NONE');
            $table->boolean('is_operational')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['plan_version', 'code'], 'uq_account_templates_version_code');
            $table->index(['plan_version', 'level', 'code'], 'idx_account_templates_lookup');
            $table->index(['plan_version', 'parent_code'], 'idx_account_templates_parent');
        });

        DB::unprepared(<<<'SQL'
        -- 1. Ampliar el rango de niveles en accounting_accounts (1 a 6) para cubrir subdivisionarias oficiales
        ALTER TABLE public.accounting_accounts
            DROP CONSTRAINT IF EXISTS chk_accounting_accounts_level_range;

        ALTER TABLE public.accounting_accounts
            ADD CONSTRAINT chk_accounting_accounts_level_range
            CHECK (level BETWEEN 1 AND 6);

        -- 2. Flexibilizar la regla de cuentas operativas para admitir hojas desde nivel 3 en adelante
        ALTER TABLE public.accounting_accounts
            DROP CONSTRAINT IF EXISTS chk_accounting_accounts_operational_level;

        ALTER TABLE public.accounting_accounts
            ADD CONSTRAINT chk_accounting_accounts_operational_level
            CHECK (NOT is_operational OR level >= 3);

        -- 3. Permisos de lectura del catálogo matriz para el rol app_backend
        GRANT SELECT ON public.accounting_account_templates TO app_backend;
        GRANT USAGE, SELECT ON SEQUENCE public.accounting_account_templates_id_seq TO app_backend;
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_account_templates');

        DB::unprepared(<<<'SQL'
        ALTER TABLE public.accounting_accounts
            DROP CONSTRAINT IF EXISTS chk_accounting_accounts_level_range;

        ALTER TABLE public.accounting_accounts
            ADD CONSTRAINT chk_accounting_accounts_level_range
            CHECK (level BETWEEN 1 AND 4);

        ALTER TABLE public.accounting_accounts
            DROP CONSTRAINT IF EXISTS chk_accounting_accounts_operational_level;

        ALTER TABLE public.accounting_accounts
            ADD CONSTRAINT chk_accounting_accounts_operational_level
            CHECK (NOT is_operational OR level = 4);
        SQL);
    }
};
