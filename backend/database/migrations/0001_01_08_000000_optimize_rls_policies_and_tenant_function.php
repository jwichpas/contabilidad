<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Optimización de alto rendimiento para políticas RLS en PostgreSQL según Supabase Best Practices:
 * 1. Reescribe `public.app_current_company_id()` como función pura `LANGUAGE sql STABLE PARALLEL SAFE`.
 * 2. Reemplaza `app_current_company_id()` por `(SELECT app_current_company_id())` en todas las políticas RLS.
 *    Esto hace que PostgreSQL evalúe el tenant una sola vez por consulta (caché de planificador)
 *    en lugar de ejecutar la función por cada fila escaneada.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
        -- 1. Función SQL pura STABLE PARALLEL SAFE inlinable
        CREATE OR REPLACE FUNCTION public.app_current_company_id()
        RETURNS bigint
        LANGUAGE sql
        STABLE
        PARALLEL SAFE
        AS $$
            SELECT nullif(current_setting('app.current_company_id', true), '')::bigint;
        $$;

        GRANT EXECUTE ON FUNCTION public.app_current_company_id() TO app_backend;

        -- 2. Optimización de políticas RLS: envolver en (SELECT app_current_company_id())
        
        -- accounting_accounts
        DROP POLICY IF EXISTS accounting_accounts_tenant_isolation_policy ON public.accounting_accounts;
        CREATE POLICY accounting_accounts_tenant_isolation_policy ON public.accounting_accounts
            TO app_backend
            USING (company_id = (SELECT app_current_company_id()))
            WITH CHECK (company_id = (SELECT app_current_company_id()));

        -- company_user
        DROP POLICY IF EXISTS company_user_tenant_isolation_policy ON public.company_user;
        CREATE POLICY company_user_tenant_isolation_policy ON public.company_user
            TO app_backend
            USING (company_id = (SELECT app_current_company_id()))
            WITH CHECK (company_id = (SELECT app_current_company_id()));

        -- cost_centers
        DROP POLICY IF EXISTS cost_centers_tenant_isolation_policy ON public.cost_centers;
        CREATE POLICY cost_centers_tenant_isolation_policy ON public.cost_centers
            TO app_backend
            USING (company_id = (SELECT app_current_company_id()))
            WITH CHECK (company_id = (SELECT app_current_company_id()));

        -- cash_flow_areas
        DROP POLICY IF EXISTS cash_flow_areas_tenant_isolation_policy ON public.cash_flow_areas;
        CREATE POLICY cash_flow_areas_tenant_isolation_policy ON public.cash_flow_areas
            TO app_backend
            USING (company_id = (SELECT app_current_company_id()))
            WITH CHECK (company_id = (SELECT app_current_company_id()));

        -- company_areas
        DROP POLICY IF EXISTS company_areas_tenant_isolation_policy ON public.company_areas;
        CREATE POLICY company_areas_tenant_isolation_policy ON public.company_areas
            TO app_backend
            USING (company_id = (SELECT app_current_company_id()))
            WITH CHECK (company_id = (SELECT app_current_company_id()));

        -- company_settings
        DROP POLICY IF EXISTS company_settings_tenant_isolation_policy ON public.company_settings;
        CREATE POLICY company_settings_tenant_isolation_policy ON public.company_settings
            TO app_backend
            USING (company_id = (SELECT app_current_company_id()))
            WITH CHECK (company_id = (SELECT app_current_company_id()));

        -- company_currencies
        DROP POLICY IF EXISTS company_currencies_tenant_isolation_policy ON public.company_currencies;
        CREATE POLICY company_currencies_tenant_isolation_policy ON public.company_currencies
            TO app_backend
            USING (company_id = (SELECT app_current_company_id()))
            WITH CHECK (company_id = (SELECT app_current_company_id()));

        -- entities
        DROP POLICY IF EXISTS entities_tenant_isolation_policy ON public.entities;
        CREATE POLICY entities_tenant_isolation_policy ON public.entities
            TO app_backend
            USING (company_id = (SELECT app_current_company_id()))
            WITH CHECK (company_id = (SELECT app_current_company_id()));

        -- fiscal_periods
        DROP POLICY IF EXISTS fiscal_periods_tenant_isolation_policy ON public.fiscal_periods;
        CREATE POLICY fiscal_periods_tenant_isolation_policy ON public.fiscal_periods
            TO app_backend
            USING (company_id = (SELECT app_current_company_id()))
            WITH CHECK (company_id = (SELECT app_current_company_id()));

        -- exchange_rates
        DROP POLICY IF EXISTS exchange_rates_tenant_isolation_policy ON public.exchange_rates;
        CREATE POLICY exchange_rates_tenant_isolation_policy ON public.exchange_rates
            TO app_backend
            USING (company_id = (SELECT app_current_company_id()))
            WITH CHECK (company_id = (SELECT app_current_company_id()));

        -- journal_entries
        DROP POLICY IF EXISTS journal_entries_tenant_isolation_policy ON public.journal_entries;
        CREATE POLICY journal_entries_tenant_isolation_policy ON public.journal_entries
            TO app_backend
            USING (company_id = (SELECT app_current_company_id()))
            WITH CHECK (company_id = (SELECT app_current_company_id()));

        -- journal_entry_lines
        DROP POLICY IF EXISTS journal_entry_lines_tenant_isolation_policy ON public.journal_entry_lines;
        CREATE POLICY journal_entry_lines_tenant_isolation_policy ON public.journal_entry_lines
            TO app_backend
            USING (company_id = (SELECT app_current_company_id()))
            WITH CHECK (company_id = (SELECT app_current_company_id()));

        -- audit_logs
        DROP POLICY IF EXISTS audit_logs_select_policy ON public.audit_logs;
        CREATE POLICY audit_logs_select_policy ON public.audit_logs
            AS PERMISSIVE
            FOR SELECT
            TO app_backend
            USING ((company_id IS NULL) OR (company_id = (SELECT app_current_company_id())));
        SQL);
    }

    public function down(): void
    {
        // Revertir a la versión anterior si fuera necesario
    }
};
