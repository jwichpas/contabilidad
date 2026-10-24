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
        // Historical immutable entries are classified as GENERAL, never rewritten.
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->string('subdiary_code', 24)->default('GENERAL');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE public.journal_entries
              ADD CONSTRAINT chk_journal_entries_subdiary
              CHECK (subdiary_code IN ('GENERAL', 'COMPRAS', 'VENTAS', 'CAJA',
                'BANCOS', 'PLANILLAS', 'AJUSTES', 'APERTURA_CIERRE'))
            SQL);

        // Preserve the existing immutable-journal trigger's only permitted UPDATE,
        // but protect the newly added classification against alteration on reversal.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.trg_fn_prevent_journal_entry_mutation()
            RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog AS $
            BEGIN
              IF TG_OP = 'DELETE' THEN
                RAISE EXCEPTION 'IMMUTABLE_JOURNAL_ENTRY: Los asientos contables son inmutables y no pueden eliminarse. Utilice un asiento de extorno.'
                  USING ERRCODE = '23514';
              END IF;
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
                  AND OLD.subdiary_code IS NOT DISTINCT FROM NEW.subdiary_code
                THEN RETURN NEW;
                END IF;
              END IF;
              RAISE EXCEPTION 'IMMUTABLE_JOURNAL_ENTRY: Un asiento contable registrado es inmutable. Para corregirlo, emita un asiento de extorno.'
                USING ERRCODE = '23514';
            END;
            $$;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('No revertir la clasificación de un libro inmutable sin auditoría y plan de datos.');
    }
};
