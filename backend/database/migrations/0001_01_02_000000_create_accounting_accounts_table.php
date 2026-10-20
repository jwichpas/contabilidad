<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crea el catálogo/plan de cuentas contables (`accounting_accounts`) con:
 * - Aislamiento multi-tenant mediante FK compuesta `(company_id, parent_id) -> (company_id, id)`.
 * - Estrategia híbrida de árbol: `parent_id` + `path` materializado + índice `text_pattern_ops`.
 * - Configuración avanzada de cuenta contable:
 *   1. Moneda de configuración (`currency_mode`: PEN, USD, EUR, MULTI) + `requires_currency`.
 *   2. Exigencia de Entidad/Anexo (`requires_auxiliary` + `entity_requirement_type`).
 *   3. Centro de Costos y Destino Automático a Elemento 9 (`requires_cost_center`, `has_automatic_destination`,
 *      `destination_debit_code` ej. 9411/9511 y `destination_credit_code` ej. 7911).
 *   4. Área de Flujo de Efectivo para cuentas del Elemento 10 (`requires_cash_flow_area` + `cash_flow_category`).
 *   5. Rubro de Estados Financieros (`financial_statement_rubric`, ej. 1041 -> ESF_ACT_CORR_EFE = Efectivo y Equivalentes de Efectivo).
 * - Trigger de integridad en BD que prohíbe subcuentas bajo cuentas operativas (`is_operational = true`).
 * - Row Level Security (ENABLE + FORCE) y revocación de DELETE para `app_backend`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code', 20);
            $table->string('name', 180);
            $table->smallInteger('level');
            // Path materializado de códigos separados por punto (ej. "1.10.104.1041")
            $table->string('path', 120);
            $table->string('account_type', 25);
            $table->string('balance_type', 15);

            // 1. Configuración de Moneda
            $table->string('currency_mode', 10)->default('PEN');
            $table->boolean('requires_currency')->default(false);

            // 2. Configuración de Entidad / Anexo (RUC, DNI, Banco, Trabajador)
            $table->boolean('requires_auxiliary')->default(false);
            $table->string('entity_requirement_type', 30)->default('NONE');

            // 3. Configuración de Centro de Costos y Amarre Automático (Elemento 6 -> Cuentas 94/95 con abono a 79)
            $table->boolean('requires_cost_center')->default(false);
            $table->boolean('has_automatic_destination')->default(false);
            $table->string('destination_debit_code', 20)->nullable();
            $table->string('destination_credit_code', 20)->nullable();

            // 4. Configuración de Área para Flujo de Efectivo (Especial para Cuenta 10)
            $table->boolean('requires_cash_flow_area')->default(false);
            $table->string('cash_flow_category', 25)->default('NONE');

            // 5. Configuración de Rubro para Reportes / Estados Financieros (ej. 1041 -> ESF_ACT_CORR_EFE)
            $table->string('financial_statement_rubric', 40)->default('NONE');

            $table->boolean('is_operational')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            // Requisito indispensable para que la FK compuesta (company_id, parent_id) pueda apuntar
            // a (company_id, id) impidiendo a nivel de motor referenciar una cuenta padre de otro tenant.
            $table->unique(['company_id', 'id'], 'uq_accounting_accounts_company_id');

            // Unicidad de código contable por empresa.
            $table->unique(['company_id', 'code'], 'uq_accounting_accounts_company_code');

            // Índices compuestos de alto rendimiento para navegación, reportes y digitación intensiva:
            $table->index(['company_id', 'parent_id'], 'idx_accounting_accounts_company_parent');
            $table->index(['company_id', 'is_operational', 'is_active'], 'idx_accounting_accounts_operational_active');
            $table->index(['company_id', 'financial_statement_rubric'], 'idx_accounting_accounts_fs_rubric');
        });

        DB::unprepared(<<<'SQL'
        -- 1. FK compuesta intra-tenant para la jerarquía padre-hijo:
        ALTER TABLE public.accounting_accounts
            ADD CONSTRAINT fk_accounting_accounts_parent_same_tenant
            FOREIGN KEY (company_id, parent_id)
            REFERENCES public.accounting_accounts (company_id, id)
            ON UPDATE RESTRICT
            ON DELETE RESTRICT;

        -- 2. Restricciones CHECK de dominio contable:
        ALTER TABLE public.accounting_accounts
            ADD CONSTRAINT chk_accounting_accounts_no_self_parent
            CHECK (parent_id IS NULL OR id <> parent_id);

        ALTER TABLE public.accounting_accounts
            ADD CONSTRAINT chk_accounting_accounts_level_range
            CHECK (level BETWEEN 1 AND 4);

        ALTER TABLE public.accounting_accounts
            ADD CONSTRAINT chk_accounting_accounts_root_vs_child_level
            CHECK (
                (level = 1 AND parent_id IS NULL)
                OR (level > 1 AND parent_id IS NOT NULL)
            );

        ALTER TABLE public.accounting_accounts
            ADD CONSTRAINT chk_accounting_accounts_code_alphanumeric
            CHECK (code ~ '^[A-Za-z0-9]+$');

        ALTER TABLE public.accounting_accounts
            ADD CONSTRAINT chk_accounting_accounts_account_type
            CHECK (account_type IN (
                'ASSET',
                'LIABILITY',
                'EQUITY',
                'REVENUE',
                'EXPENSE',
                'COST',
                'MEMORANDUM'
            ));

        ALTER TABLE public.accounting_accounts
            ADD CONSTRAINT chk_accounting_accounts_balance_type
            CHECK (balance_type IN ('DEBIT', 'CREDIT'));

        ALTER TABLE public.accounting_accounts
            ADD CONSTRAINT chk_accounting_accounts_currency_mode
            CHECK (currency_mode IN ('PEN', 'USD', 'EUR', 'MULTI'));

        ALTER TABLE public.accounting_accounts
            ADD CONSTRAINT chk_accounting_accounts_entity_requirement
            CHECK (entity_requirement_type IN (
                'NONE',
                'CUSTOMER',
                'SUPPLIER',
                'EMPLOYEE',
                'SHAREHOLDER',
                'FINANCIAL_INSTITUTION',
                'TAX_AUTHORITY',
                'ANY_THIRD_PARTY'
            ));

        ALTER TABLE public.accounting_accounts
            ADD CONSTRAINT chk_accounting_accounts_cash_flow_category
            CHECK (cash_flow_category IN (
                'NONE',
                'CASH_EQUIVALENT',
                'OPERATING',
                'INVESTING',
                'FINANCING'
            ));

        ALTER TABLE public.accounting_accounts
            ADD CONSTRAINT chk_accounting_accounts_fs_rubric
            CHECK (financial_statement_rubric IN (
                'NONE',
                'ESF_ACT_CORR_EFE',
                'ESF_ACT_CORR_CXC_COM',
                'ESF_ACT_CORR_OTR_CXC',
                'ESF_ACT_CORR_INV',
                'ESF_ACT_NCORR_IME',
                'ESF_ACT_NCORR_INT',
                'ESF_PAS_CORR_TRIB',
                'ESF_PAS_CORR_REM',
                'ESF_PAS_CORR_CXP_COM',
                'ESF_PAS_OBLIG_FIN',
                'ESF_PAT_CAP',
                'ESF_PAT_RES_ACUM',
                'ER_ING_VENTAS_NETAS',
                'ER_ING_OTROS_OPER',
                'ERN_COMPRAS_MERC',
                'ERN_GASTOS_PERSONAL',
                'ERN_SERV_TERCEROS',
                'ERN_GASTOS_TRIBUTOS',
                'ERN_OTROS_GASTOS_GESTION',
                'ERF_COSTO_VENTAS',
                'ERF_GASTOS_ADMIN_94',
                'ERF_GASTOS_VENTAS_95',
                'CONTROL_CARGAS_IMPUTABLES_79'
            ));

        -- Solo las cuentas de nivel 4 (registro) pueden ser operativas.
        ALTER TABLE public.accounting_accounts
            ADD CONSTRAINT chk_accounting_accounts_operational_level
            CHECK (NOT is_operational OR level = 4);

        -- Si tiene destino automático (amarre al Elemento 9), debe especificar cuenta de cargo (94/95) y abono (79).
        ALTER TABLE public.accounting_accounts
            ADD CONSTRAINT chk_accounting_accounts_auto_destination_codes
            CHECK (
                NOT has_automatic_destination
                OR (destination_debit_code IS NOT NULL AND destination_credit_code IS NOT NULL)
            );

        -- 3. Índices con `text_pattern_ops` para búsqueda instantánea por prefijo (`LIKE '104%'`)
        CREATE INDEX idx_accounting_accounts_company_code_prefix
            ON public.accounting_accounts (company_id, code text_pattern_ops);

        CREATE INDEX idx_accounting_accounts_company_path_prefix
            ON public.accounting_accounts (company_id, path text_pattern_ops);

        -- 4. Función y Trigger de Integridad Jerárquica en Base de Datos:
        CREATE OR REPLACE FUNCTION public.trg_fn_enforce_accounting_account_hierarchy()
        RETURNS trigger
        LANGUAGE plpgsql
        AS $$
        DECLARE
            v_parent RECORD;
            v_has_children boolean;
        BEGIN
            IF NEW.is_operational = true AND TG_OP = 'UPDATE' THEN
                SELECT EXISTS (
                    SELECT 1
                    FROM public.accounting_accounts c
                    WHERE c.company_id = NEW.company_id
                      AND c.parent_id = NEW.id
                ) INTO v_has_children;

                IF v_has_children THEN
                    RAISE EXCEPTION 'ACCOUNT_HAS_CHILDREN: No se puede marcar como operativa una cuenta que ya posee subcuentas hijas.'
                        USING ERRCODE = '23514';
                END IF;
            END IF;

            IF NEW.parent_id IS NULL THEN
                IF NEW.level <> 1 THEN
                    RAISE EXCEPTION 'INVALID_ROOT_LEVEL: Las cuentas sin padre deben ser de nivel 1.'
                        USING ERRCODE = '23514';
                END IF;
                NEW.path := NEW.code;
            ELSE
                SELECT id, code, level, path, is_operational
                INTO v_parent
                FROM public.accounting_accounts
                WHERE company_id = NEW.company_id
                  AND id = NEW.parent_id
                FOR SHARE;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'PARENT_NOT_FOUND_IN_TENANT: La cuenta padre no existe en la empresa activa.'
                        USING ERRCODE = '23503';
                END IF;

                IF v_parent.is_operational THEN
                    RAISE EXCEPTION 'PARENT_IS_OPERATIONAL: Una cuenta operativa (imputable) no puede tener cuentas hijas.'
                        USING ERRCODE = '23514';
                END IF;

                IF NEW.level <> (v_parent.level + 1) THEN
                    RAISE EXCEPTION 'INVALID_CHILD_LEVEL: El nivel de la cuenta hija (%) debe ser exactamente el nivel del padre (%) + 1.',
                        NEW.level, v_parent.level
                        USING ERRCODE = '23514';
                END IF;

                IF position(v_parent.code IN NEW.code) <> 1 OR char_length(NEW.code) <= char_length(v_parent.code) THEN
                    RAISE EXCEPTION 'INVALID_CHILD_CODE_PREFIX: El código de la cuenta hija (%) debe iniciar con el código de su cuenta padre (%) y ser más específico.',
                        NEW.code, v_parent.code
                        USING ERRCODE = '23514';
                END IF;

                NEW.path := v_parent.path || '.' || NEW.code;
            END IF;

            RETURN NEW;
        END;
        $$;

        CREATE TRIGGER trg_enforce_accounting_account_hierarchy
            BEFORE INSERT OR UPDATE OF parent_id, code, level, is_operational
            ON public.accounting_accounts
            FOR EACH ROW
            EXECUTE FUNCTION public.trg_fn_enforce_accounting_account_hierarchy();

        -- 5. Activación y forzado de Row Level Security (RLS)
        ALTER TABLE public.accounting_accounts ENABLE ROW LEVEL SECURITY;
        ALTER TABLE public.accounting_accounts FORCE ROW LEVEL SECURITY;

        CREATE POLICY accounting_accounts_tenant_isolation_policy
            ON public.accounting_accounts
            AS PERMISSIVE
            FOR ALL
            TO app_backend
            USING (company_id = public.app_current_company_id())
            WITH CHECK (company_id = public.app_current_company_id());

        -- 6. Privilegios mínimos para `app_backend` (SIN privilegio DELETE sobre el plan de cuentas)
        REVOKE ALL ON TABLE public.accounting_accounts FROM app_backend;
        GRANT SELECT, INSERT, UPDATE ON TABLE public.accounting_accounts TO app_backend;
        GRANT USAGE, SELECT ON SEQUENCE public.accounting_accounts_id_seq TO app_backend;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP TRIGGER IF EXISTS trg_enforce_accounting_account_hierarchy ON public.accounting_accounts;
        DROP FUNCTION IF EXISTS public.trg_fn_enforce_accounting_account_hierarchy();
        SQL);

        Schema::dropIfExists('accounting_accounts');
    }
};
