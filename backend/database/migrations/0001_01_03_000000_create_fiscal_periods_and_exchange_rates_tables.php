<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crea las tablas de Periodos Contables (`fiscal_periods`) y Tipos de Cambio diarios (`exchange_rates` con NUMERIC(14,6)).
 * Ambas tablas están protegidas por FK compuesta `(company_id, id)` y FORCE ROW LEVEL SECURITY.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_periods', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->smallInteger('year');
            $table->smallInteger('month');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 15)->default('OPEN');
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'id'], 'uq_fiscal_periods_company_id');
            $table->unique(['company_id', 'year', 'month'], 'uq_fiscal_periods_company_year_month');
            $table->index(['company_id', 'status', 'start_date', 'end_date'], 'idx_fiscal_periods_lookup');
        });

        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->date('rate_date');
            $table->char('source_currency', 3)->default('USD');
            $table->char('target_currency', 3)->default('PEN');
            // Escala NUMERIC(14,6) obligatoria para precisión bancaria de arbitraje cambiario.
            $table->decimal('buy_rate', 14, 6);
            $table->decimal('sell_rate', 14, 6);
            $table->timestampsTz();

            $table->unique(['company_id', 'id'], 'uq_exchange_rates_company_id');
            $table->unique(
                ['company_id', 'rate_date', 'source_currency', 'target_currency'],
                'uq_exchange_rates_company_date_pair'
            );
        });

        DB::unprepared(<<<'SQL'
        ALTER TABLE public.fiscal_periods
            ADD CONSTRAINT chk_fiscal_periods_year_range
            CHECK (year BETWEEN 2000 AND 2100);

        ALTER TABLE public.fiscal_periods
            ADD CONSTRAINT chk_fiscal_periods_month_range
            CHECK (month BETWEEN 1 AND 12);

        ALTER TABLE public.fiscal_periods
            ADD CONSTRAINT chk_fiscal_periods_date_order
            CHECK (end_date >= start_date);

        ALTER TABLE public.fiscal_periods
            ADD CONSTRAINT chk_fiscal_periods_status
            CHECK (status IN ('OPEN', 'CLOSED', 'LOCKED'));

        ALTER TABLE public.exchange_rates
            ADD CONSTRAINT chk_exchange_rates_positive
            CHECK (buy_rate > 0.000000 AND sell_rate > 0.000000);

        ALTER TABLE public.exchange_rates
            ADD CONSTRAINT chk_exchange_rates_distinct_currencies
            CHECK (source_currency <> target_currency);

        -- Row Level Security (ENABLE + FORCE)
        ALTER TABLE public.fiscal_periods ENABLE ROW LEVEL SECURITY;
        ALTER TABLE public.fiscal_periods FORCE ROW LEVEL SECURITY;

        CREATE POLICY fiscal_periods_tenant_isolation_policy
            ON public.fiscal_periods
            AS PERMISSIVE
            FOR ALL
            TO app_backend
            USING (company_id = public.app_current_company_id())
            WITH CHECK (company_id = public.app_current_company_id());

        ALTER TABLE public.exchange_rates ENABLE ROW LEVEL SECURITY;
        ALTER TABLE public.exchange_rates FORCE ROW LEVEL SECURITY;

        CREATE POLICY exchange_rates_tenant_isolation_policy
            ON public.exchange_rates
            AS PERMISSIVE
            FOR ALL
            TO app_backend
            USING (company_id = public.app_current_company_id())
            WITH CHECK (company_id = public.app_current_company_id());

        REVOKE ALL ON TABLE public.fiscal_periods FROM app_backend;
        GRANT SELECT, INSERT, UPDATE ON TABLE public.fiscal_periods TO app_backend;
        GRANT USAGE, SELECT ON SEQUENCE public.fiscal_periods_id_seq TO app_backend;

        REVOKE ALL ON TABLE public.exchange_rates FROM app_backend;
        GRANT SELECT, INSERT, UPDATE ON TABLE public.exchange_rates TO app_backend;
        GRANT USAGE, SELECT ON SEQUENCE public.exchange_rates_id_seq TO app_backend;
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('fiscal_periods');
    }
};
