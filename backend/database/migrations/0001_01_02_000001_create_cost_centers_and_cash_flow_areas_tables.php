<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crea los catálogos paramétricos asociados al Plan de Cuentas:
 * 1. `cost_centers`: Centros de Costos para gastos del Elemento 6 que determinan su transferencia
 *    automática al Elemento 9 (ej. Cuenta 94 Gastos Administrativos / Cuenta 95 Gastos de Ventas)
 *    con abono a la Cuenta 79 (Cargas Imputables a Cuentas de Costos y Gastos).
 * 2. `cash_flow_areas`: Áreas de Flujo de Efectivo para desglosar los movimientos de las cuentas
 *    del Elemento 10 (Efectivo y Equivalentes de Efectivo) en el Estado de Flujos de Efectivo (NIC 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_centers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 150);
            // Cuentas operativas de destino en el Elemento 9 (94/95) y contrapartida (79) del mismo tenant
            $table->unsignedBigInteger('destination_debit_account_id');
            $table->unsignedBigInteger('destination_credit_account_id');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'id'], 'uq_cost_centers_company_id');
            $table->unique(['company_id', 'code'], 'uq_cost_centers_company_code');
        });

        Schema::create('cash_flow_areas', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 150);
            $table->string('activity_category', 25);
            $table->string('flow_direction', 10)->default('BOTH');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'id'], 'uq_cash_flow_areas_company_id');
            $table->unique(['company_id', 'code'], 'uq_cash_flow_areas_company_code');
        });

        DB::unprepared(<<<'SQL'
        -- FKs compuestas intra-tenant desde Centros de Costos hacia las cuentas 94/95 y 79
        ALTER TABLE public.cost_centers
            ADD CONSTRAINT fk_cost_centers_dest_debit_same_tenant
            FOREIGN KEY (company_id, destination_debit_account_id)
            REFERENCES public.accounting_accounts (company_id, id)
            ON UPDATE RESTRICT
            ON DELETE RESTRICT;

        ALTER TABLE public.cost_centers
            ADD CONSTRAINT fk_cost_centers_dest_credit_same_tenant
            FOREIGN KEY (company_id, destination_credit_account_id)
            REFERENCES public.accounting_accounts (company_id, id)
            ON UPDATE RESTRICT
            ON DELETE RESTRICT;

        ALTER TABLE public.cost_centers
            ADD CONSTRAINT chk_cost_centers_distinct_accounts
            CHECK (destination_debit_account_id <> destination_credit_account_id);

        ALTER TABLE public.cash_flow_areas
            ADD CONSTRAINT chk_cash_flow_areas_category
            CHECK (activity_category IN ('OPERATING', 'INVESTING', 'FINANCING', 'CASH_EQUIVALENT'));

        ALTER TABLE public.cash_flow_areas
            ADD CONSTRAINT chk_cash_flow_areas_direction
            CHECK (flow_direction IN ('INFLOW', 'OUTFLOW', 'BOTH'));

        -- Row Level Security (ENABLE + FORCE)
        ALTER TABLE public.cost_centers ENABLE ROW LEVEL SECURITY;
        ALTER TABLE public.cost_centers FORCE ROW LEVEL SECURITY;

        CREATE POLICY cost_centers_tenant_isolation_policy
            ON public.cost_centers
            AS PERMISSIVE
            FOR ALL
            TO app_backend
            USING (company_id = public.app_current_company_id())
            WITH CHECK (company_id = public.app_current_company_id());

        ALTER TABLE public.cash_flow_areas ENABLE ROW LEVEL SECURITY;
        ALTER TABLE public.cash_flow_areas FORCE ROW LEVEL SECURITY;

        CREATE POLICY cash_flow_areas_tenant_isolation_policy
            ON public.cash_flow_areas
            AS PERMISSIVE
            FOR ALL
            TO app_backend
            USING (company_id = public.app_current_company_id())
            WITH CHECK (company_id = public.app_current_company_id());

        REVOKE ALL ON TABLE public.cost_centers FROM app_backend;
        GRANT SELECT, INSERT, UPDATE ON TABLE public.cost_centers TO app_backend;
        GRANT USAGE, SELECT ON SEQUENCE public.cost_centers_id_seq TO app_backend;

        REVOKE ALL ON TABLE public.cash_flow_areas FROM app_backend;
        GRANT SELECT, INSERT, UPDATE ON TABLE public.cash_flow_areas TO app_backend;
        GRANT USAGE, SELECT ON SEQUENCE public.cash_flow_areas_id_seq TO app_backend;
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_flow_areas');
        Schema::dropIfExists('cost_centers');
    }
};
