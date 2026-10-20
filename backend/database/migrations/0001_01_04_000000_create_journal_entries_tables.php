<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crea el Motor de Asientos Contables Bimoneda (`journal_entries` y `journal_entry_lines`) con:
 * 1. Montos `NUMERIC(14,4)` y tasa de cambio `NUMERIC(14,6)`.
 * 2. FKs compuestas intra-tenant para `fiscal_period_id`, `reversed_entry_id`, `journal_entry_id` y `accounting_account_id`.
 * 3. Trigger `BEFORE INSERT OR UPDATE` que exige que las cuentas imputadas sean operativas (`is_operational = true`)
 *    y que el periodo contable esté abierto (`OPEN`).
 * 4. Trigger `BEFORE UPDATE OR DELETE` de Inmutabilidad (Journaling Puro): un asiento aprobado (`POSTED`) o extornado
 *    (`REVERSED`) jamás se modifica ni se elimina; solo se corrige mediante un asiento de extorno.
 * 5. `CONSTRAINT TRIGGER ... DEFERRABLE INITIALLY DEFERRED` que verifica al hacer COMMIT que todo asiento
 *    cumpla `SUM(debe) - SUM(haber) = 0.0000` en moneda local y moneda extranjera.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('fiscal_period_id');
            $table->string('entry_number', 30);
            $table->date('entry_date');
            $table->string('entry_type', 20)->default('STANDARD');
            $table->string('status', 15)->default('POSTED');
            $table->char('currency', 3)->default('PEN');
            $table->decimal('exchange_rate', 14, 6)->default(1.000000);
            $table->string('exchange_rate_type', 10)->default('SELL');
            $table->string('description', 350);
            $table->unsignedBigInteger('reversed_entry_id')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['company_id', 'id'], 'uq_journal_entries_company_id');
            $table->unique(
                ['company_id', 'fiscal_period_id', 'entry_number'],
                'uq_journal_entries_company_period_number'
            );
            $table->index(['company_id', 'entry_date', 'id'], 'idx_journal_entries_company_date');
        });

        Schema::create('journal_entry_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->unsignedBigInteger('journal_entry_id');
            $table->smallInteger('line_number');
            $table->unsignedBigInteger('accounting_account_id');
            $table->string('description', 250)->nullable();
            // Bimoneda simultánea con precisión NUMERIC(14,4)
            $table->decimal('debit_local', 14, 4)->default(0.0000);
            $table->decimal('credit_local', 14, 4)->default(0.0000);
            $table->decimal('debit_foreign', 14, 4)->default(0.0000);
            $table->decimal('credit_foreign', 14, 4)->default(0.0000);
            $table->decimal('exchange_rate', 14, 6)->default(1.000000);
            $table->timestampsTz();

            $table->unique(['company_id', 'id'], 'uq_journal_entry_lines_company_id');
            $table->unique(
                ['company_id', 'journal_entry_id', 'line_number'],
                'uq_journal_entry_lines_number'
            );
            $table->index(
                ['company_id', 'accounting_account_id', 'journal_entry_id'],
                'idx_journal_entry_lines_ledger'
            );
        });

        DB::unprepared(<<<'SQL'
        -- 1. Claves foráneas compuestas intra-tenant (imposible cruzar datos entre empresas)
        ALTER TABLE public.journal_entries
            ADD CONSTRAINT fk_journal_entries_period_same_tenant
            FOREIGN KEY (company_id, fiscal_period_id)
            REFERENCES public.fiscal_periods (company_id, id)
            ON UPDATE RESTRICT
            ON DELETE RESTRICT;

        ALTER TABLE public.journal_entries
            ADD CONSTRAINT fk_journal_entries_reversed_same_tenant
            FOREIGN KEY (company_id, reversed_entry_id)
            REFERENCES public.journal_entries (company_id, id)
            ON UPDATE RESTRICT
            ON DELETE RESTRICT;

        ALTER TABLE public.journal_entry_lines
            ADD CONSTRAINT fk_journal_lines_entry_same_tenant
            FOREIGN KEY (company_id, journal_entry_id)
            REFERENCES public.journal_entries (company_id, id)
            ON UPDATE RESTRICT
            ON DELETE RESTRICT;

        ALTER TABLE public.journal_entry_lines
            ADD CONSTRAINT fk_journal_lines_account_same_tenant
            FOREIGN KEY (company_id, accounting_account_id)
            REFERENCES public.accounting_accounts (company_id, id)
            ON UPDATE RESTRICT
            ON DELETE RESTRICT;

        -- 2. Restricciones CHECK de dominio contable
        ALTER TABLE public.journal_entries
            ADD CONSTRAINT chk_journal_entries_type
            CHECK (entry_type IN ('OPENING', 'STANDARD', 'ADJUSTMENT', 'REVERSAL', 'CLOSING'));

        ALTER TABLE public.journal_entries
            ADD CONSTRAINT chk_journal_entries_status
            CHECK (status IN ('POSTED', 'REVERSED'));

        ALTER TABLE public.journal_entries
            ADD CONSTRAINT chk_journal_entries_rate_type
            CHECK (exchange_rate_type IN ('BUY', 'SELL', 'PARITY'));

        ALTER TABLE public.journal_entries
            ADD CONSTRAINT chk_journal_entries_exchange_rate_positive
            CHECK (exchange_rate > 0.000000);

        -- Cada línea debe ser estrictamente Deudora o Acreedora (nunca ambas a la vez ni ambas en cero)
        ALTER TABLE public.journal_entry_lines
            ADD CONSTRAINT chk_journal_entry_lines_non_negative
            CHECK (
                debit_local >= 0.0000
                AND credit_local >= 0.0000
                AND debit_foreign >= 0.0000
                AND credit_foreign >= 0.0000
            );

        ALTER TABLE public.journal_entry_lines
            ADD CONSTRAINT chk_journal_entry_lines_exclusive_side
            CHECK (
                (debit_local > 0.0000 AND credit_local = 0.0000 AND debit_foreign > 0.0000 AND credit_foreign = 0.0000)
                OR
                (credit_local > 0.0000 AND debit_local = 0.0000 AND credit_foreign > 0.0000 AND debit_foreign = 0.0000)
            );

        -- 3. Trigger de Inmutabilidad (Journaling Puro) en `journal_entries` y `journal_entry_lines`
        CREATE OR REPLACE FUNCTION public.trg_fn_prevent_journal_entry_mutation()
        RETURNS trigger
        LANGUAGE plpgsql
        AS $$
        BEGIN
            IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'IMMUTABLE_JOURNAL_ENTRY: Los asientos contables son inmutables y no pueden eliminarse. Utilice un asiento de extorno.'
                    USING ERRCODE = '23514';
            END IF;

            -- En UPDATE sobre `journal_entries`, la única mutación permitida es marcar `status = 'REVERSED'`
            -- desde `status = 'POSTED'` manteniendo idénticos todos los demás atributos contables.
            IF TG_TABLE_NAME = 'journal_entries' AND TG_OP = 'UPDATE' THEN
                IF OLD.status = 'POSTED'
                   AND NEW.status = 'REVERSED'
                   AND OLD.company_id = NEW.company_id
                   AND OLD.fiscal_period_id = NEW.fiscal_period_id
                   AND OLD.entry_number = NEW.entry_number
                   AND OLD.entry_date = NEW.entry_date
                   AND OLD.currency = NEW.currency
                   AND OLD.exchange_rate = NEW.exchange_rate
                   AND OLD.description = NEW.description
                THEN
                    RETURN NEW;
                END IF;
            END IF;

            RAISE EXCEPTION 'IMMUTABLE_JOURNAL_ENTRY: Un asiento contable registrado es inmutable. Para corregirlo, emita un asiento de extorno.'
                USING ERRCODE = '23514';
        END;
        $$;

        CREATE TRIGGER trg_prevent_journal_entries_mutation
            BEFORE UPDATE OR DELETE ON public.journal_entries
            FOR EACH ROW
            EXECUTE FUNCTION public.trg_fn_prevent_journal_entry_mutation();

        CREATE TRIGGER trg_prevent_journal_entry_lines_mutation
            BEFORE UPDATE OR DELETE ON public.journal_entry_lines
            FOR EACH ROW
            EXECUTE FUNCTION public.trg_fn_prevent_journal_entry_mutation();

        -- 4. Trigger que verifica que la cuenta sea operativa (`is_operational = true`) y activa,
        --    y que el periodo contable esté abierto (`OPEN`).
        CREATE OR REPLACE FUNCTION public.trg_fn_validate_journal_line_context()
        RETURNS trigger
        LANGUAGE plpgsql
        AS $$
        DECLARE
            v_account RECORD;
            v_period_status varchar(15);
        BEGIN
            SELECT is_operational, is_active, code
            INTO v_account
            FROM public.accounting_accounts
            WHERE company_id = NEW.company_id
              AND id = NEW.accounting_account_id;

            IF NOT FOUND OR NOT v_account.is_active OR NOT v_account.is_operational THEN
                RAISE EXCEPTION 'NON_OPERATIONAL_ACCOUNT: Solo se permite imputar movimientos en cuentas operativas (nivel 4) y activas.'
                    USING ERRCODE = '23514';
            END IF;

            SELECT fp.status
            INTO v_period_status
            FROM public.journal_entries je
            INNER JOIN public.fiscal_periods fp
                ON fp.company_id = je.company_id AND fp.id = je.fiscal_period_id
            WHERE je.company_id = NEW.company_id
              AND je.id = NEW.journal_entry_id;

            IF v_period_status IS DISTINCT FROM 'OPEN' THEN
                RAISE EXCEPTION 'CLOSED_FISCAL_PERIOD: No se pueden registrar asientos en un periodo contable cerrado o bloqueado.'
                    USING ERRCODE = '23514';
            END IF;

            RETURN NEW;
        END;
        $$;

        CREATE TRIGGER trg_validate_journal_line_context
            BEFORE INSERT ON public.journal_entry_lines
            FOR EACH ROW
            EXECUTE FUNCTION public.trg_fn_validate_journal_line_context();

        -- 5. CONSTRAINT TRIGGER ... DEFERRABLE INITIALLY DEFERRED para Partida Doble Bimoneda:
        --    Se evalúa al final de la transacción (COMMIT) para verificar que el asiento tenga
        --    al menos 2 líneas y que SUM(debe) - SUM(haber) = 0.0000 en moneda local y extranjera.
        CREATE OR REPLACE FUNCTION public.trg_fn_enforce_double_entry_balance()
        RETURNS trigger
        LANGUAGE plpgsql
        AS $$
        DECLARE
            v_entry_id bigint;
            v_company_id bigint;
            v_line_count integer;
            v_diff_local numeric(14,4);
            v_diff_foreign numeric(14,4);
            v_total_debit_local numeric(14,4);
        BEGIN
            IF TG_TABLE_NAME = 'journal_entries' THEN
                v_entry_id := NEW.id;
                v_company_id := NEW.company_id;
            ELSE
                v_entry_id := NEW.journal_entry_id;
                v_company_id := NEW.company_id;
            END IF;

            SELECT
                COUNT(*),
                COALESCE(SUM(debit_local), 0.0000) - COALESCE(SUM(credit_local), 0.0000),
                COALESCE(SUM(debit_foreign), 0.0000) - COALESCE(SUM(credit_foreign), 0.0000),
                COALESCE(SUM(debit_local), 0.0000)
            INTO
                v_line_count,
                v_diff_local,
                v_diff_foreign,
                v_total_debit_local
            FROM public.journal_entry_lines
            WHERE company_id = v_company_id
              AND journal_entry_id = v_entry_id;

            IF v_line_count < 2 THEN
                RAISE EXCEPTION 'INSUFFICIENT_JOURNAL_LINES: Todo asiento contable requiere como mínimo 2 líneas (partida doble).'
                    USING ERRCODE = '23514';
            END IF;

            IF v_total_debit_local <= 0.0000 THEN
                RAISE EXCEPTION 'ZERO_JOURNAL_ENTRY: El total del asiento contable debe ser mayor a 0.0000.'
                    USING ERRCODE = '23514';
            END IF;

            IF v_diff_local <> 0.0000 OR v_diff_foreign <> 0.0000 THEN
                RAISE EXCEPTION 'UNBALANCED_JOURNAL_ENTRY: Violación de partida doble en asiento [%]. Diferencia MN: %, Diferencia ME: %.',
                    v_entry_id, v_diff_local, v_diff_foreign
                    USING ERRCODE = '23514';
            END IF;

            RETURN NULL;
        END;
        $$;

        CREATE CONSTRAINT TRIGGER trg_enforce_double_entry_on_header
            AFTER INSERT ON public.journal_entries
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION public.trg_fn_enforce_double_entry_balance();

        CREATE CONSTRAINT TRIGGER trg_enforce_double_entry_on_lines
            AFTER INSERT ON public.journal_entry_lines
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION public.trg_fn_enforce_double_entry_balance();

        -- 6. Row Level Security (ENABLE + FORCE) y revocación absoluta de DELETE para `app_backend`
        ALTER TABLE public.journal_entries ENABLE ROW LEVEL SECURITY;
        ALTER TABLE public.journal_entries FORCE ROW LEVEL SECURITY;

        CREATE POLICY journal_entries_tenant_isolation_policy
            ON public.journal_entries
            AS PERMISSIVE
            FOR ALL
            TO app_backend
            USING (company_id = public.app_current_company_id())
            WITH CHECK (company_id = public.app_current_company_id());

        ALTER TABLE public.journal_entry_lines ENABLE ROW LEVEL SECURITY;
        ALTER TABLE public.journal_entry_lines FORCE ROW LEVEL SECURITY;

        CREATE POLICY journal_entry_lines_tenant_isolation_policy
            ON public.journal_entry_lines
            AS PERMISSIVE
            FOR ALL
            TO app_backend
            USING (company_id = public.app_current_company_id())
            WITH CHECK (company_id = public.app_current_company_id());

        REVOKE ALL ON TABLE public.journal_entries FROM app_backend;
        GRANT SELECT, INSERT, UPDATE ON TABLE public.journal_entries TO app_backend;
        GRANT USAGE, SELECT ON SEQUENCE public.journal_entries_id_seq TO app_backend;

        REVOKE ALL ON TABLE public.journal_entry_lines FROM app_backend;
        GRANT SELECT, INSERT ON TABLE public.journal_entry_lines TO app_backend;
        GRANT USAGE, SELECT ON SEQUENCE public.journal_entry_lines_id_seq TO app_backend;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
        DROP TRIGGER IF EXISTS trg_enforce_double_entry_on_lines ON public.journal_entry_lines;
        DROP TRIGGER IF EXISTS trg_enforce_double_entry_on_header ON public.journal_entries;
        DROP FUNCTION IF EXISTS public.trg_fn_enforce_double_entry_balance();

        DROP TRIGGER IF EXISTS trg_validate_journal_line_context ON public.journal_entry_lines;
        DROP FUNCTION IF EXISTS public.trg_fn_validate_journal_line_context();

        DROP TRIGGER IF EXISTS trg_prevent_journal_entry_lines_mutation ON public.journal_entry_lines;
        DROP TRIGGER IF EXISTS trg_prevent_journal_entries_mutation ON public.journal_entries;
        DROP FUNCTION IF EXISTS public.trg_fn_prevent_journal_entry_mutation();
        SQL);

        Schema::dropIfExists('journal_entry_lines');
        Schema::dropIfExists('journal_entries');
    }
};
