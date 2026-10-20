<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normatividad Contable y Calendario Tributario Oficial:
 * 1. `financial_statement_rubrics`: Catálogo de Rubros Oficiales de Estados Financieros (SMV / NIIF 18).
 * 2. `accounting_standards`: NIIF y NIC vigentes en el Perú para el Ejercicio 2026 (Resolución N.° 003-2026-EF/30
 *    del Consejo Normativo de Contabilidad). Incluye las 18 NIIF (con NIIF 18 y NIIF 19 nuevas en 2026) y las 24 NIC.
 * 3. `tax_calendar_deadlines`: Cronograma oficial de vencimientos SUNAT según último dígito del RUC
 *    para declaraciones mensuales (PDT 621, PLAME) y Libros Electrónicos / SIRE (RVIE y RCE).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Rubros Oficiales de Estados Financieros
        Schema::create('financial_statement_rubrics', function (Blueprint $table): void {
            $table->string('code', 35)->primary(); // e.g. ESF_ACT_CORR_EFE, ERF_GASTOS_ADMIN_94
            $table->string('statement_type', 10); // ESF, ERN, ERF, EFE
            $table->string('group_name', 50); // ACTIVO_CORRIENTE, PASIVO_CORRIENTE, PATRIMONIO, GASTOS_OPERATIVOS, etc.
            $table->string('name', 200);
            $table->string('description', 350)->nullable();
            $table->string('normal_balance', 10); // DEBIT, CREDIT
            $table->smallInteger('presentation_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // 2. Normas Contables NIIF y NIC (Set 2026 Resolución CNC N.° 003-2026-EF/30)
        Schema::create('accounting_standards', function (Blueprint $table): void {
            $table->string('code', 15)->primary(); // e.g. NIIF 1, NIIF 18, NIC 7, NIC 16
            $table->string('standard_type', 10); // NIIF, NIC, CINIIF, SIC
            $table->smallInteger('standard_number'); // 1, 7, 16, 18
            $table->string('title', 250);
            $table->string('summary', 500)->nullable();
            $table->string('legal_base', 120)->default('Resolución N.° 003-2026-EF/30 CNC');
            $table->string('status', 20)->default('VIGENTE'); // VIGENTE, NUEVA_2026, RETIRADA
            $table->boolean('affects_balance_sheet')->default(false);
            $table->boolean('affects_income_statement')->default(false);
            $table->boolean('affects_cash_flow')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // 3. Cronograma de Vencimientos Tributarios SUNAT (Libros Electrónicos/SIRE y Declaraciones)
        Schema::create('tax_calendar_deadlines', function (Blueprint $table): void {
            $table->id();
            $table->smallInteger('fiscal_year'); // 2026
            $table->smallInteger('fiscal_month'); // 1 a 12
            $table->string('last_digit_ruc', 5); // '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'BC' (Buenos Contribuyentes)
            $table->date('electronic_books_deadline'); // Fecha límite para atraso de libros / SIRE
            $table->date('tax_returns_deadline'); // Fecha límite para presentación y pago mensual (PDT 621)
            $table->string('legal_resolution', 80)->nullable(); // Resolución de Superintendencia SUNAT
            $table->timestampsTz();

            $table->unique(['fiscal_year', 'fiscal_month', 'last_digit_ruc'], 'uq_tax_calendar_period_digit');
            $table->index(['fiscal_year', 'fiscal_month'], 'idx_tax_calendar_period');
        });

        DB::unprepared(<<<'SQL'
        ALTER TABLE public.financial_statement_rubrics
            ADD CONSTRAINT chk_fs_rubric_statement_type
            CHECK (statement_type IN ('ESF', 'ERN', 'ERF', 'EFE'));

        ALTER TABLE public.financial_statement_rubrics
            ADD CONSTRAINT chk_fs_rubric_normal_balance
            CHECK (normal_balance IN ('DEBIT', 'CREDIT'));

        ALTER TABLE public.accounting_standards
            ADD CONSTRAINT chk_accounting_standards_type
            CHECK (standard_type IN ('NIIF', 'NIC', 'CINIIF', 'SIC'));

        ALTER TABLE public.accounting_standards
            ADD CONSTRAINT chk_accounting_standards_status
            CHECK (status IN ('VIGENTE', 'NUEVA_2026', 'RETIRADA'));

        ALTER TABLE public.tax_calendar_deadlines
            ADD CONSTRAINT chk_tax_calendar_month
            CHECK (fiscal_month BETWEEN 1 AND 12);

        REVOKE ALL ON TABLE public.financial_statement_rubrics FROM app_backend;
        GRANT SELECT ON TABLE public.financial_statement_rubrics TO app_backend;

        REVOKE ALL ON TABLE public.accounting_standards FROM app_backend;
        GRANT SELECT ON TABLE public.accounting_standards TO app_backend;

        REVOKE ALL ON TABLE public.tax_calendar_deadlines FROM app_backend;
        GRANT SELECT ON TABLE public.tax_calendar_deadlines TO app_backend;
        GRANT USAGE, SELECT ON SEQUENCE public.tax_calendar_deadlines_id_seq TO app_backend;
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_calendar_deadlines');
        Schema::dropIfExists('accounting_standards');
        Schema::dropIfExists('financial_statement_rubrics');
    }
};
