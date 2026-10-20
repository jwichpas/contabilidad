<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogos Maestros de Referencia Nacional:
 * 1. `currencies`: Monedas oficiales (PEN, USD, EUR) según ISO 4217 y Tabla 4 de SUNAT.
 * 2. Catálogos Oficiales de SUNAT para Libros Electrónicos (PLE) y Sistema Integrado de Registros Electrónicos (SIRE):
 *    - Tabla 1: Medios de Pago
 *    - Tabla 2: Tipos de Documento de Identidad
 *    - Tabla 3: Entidades Financieras
 *    - Tabla 5: Tipos de Existencia
 *    - Tabla 6: Códigos de Unidad de Medida
 *    - Tabla 8: Códigos de Libros o Registros
 *    - Tabla 10: Tipos de Comprobante de Pago o Documento
 *    - Tabla 11: Códigos de Aduanas
 *    - Tabla 12: Tipos de Operación
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Monedas
        Schema::create('currencies', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 3)->unique(); // ISO 4217 (PEN, USD, EUR)
            $table->string('sunat_code', 2)->unique(); // Tabla 4 SUNAT (1, 2, 9)
            $table->string('name', 50);
            $table->string('symbol', 5);
            $table->smallInteger('decimal_places')->default(2);
            $table->smallInteger('accounting_precision')->default(4); // NUMERIC(14,4)
            $table->boolean('is_base_currency')->default(false); // Soles = true
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // 2. SUNAT Tabla 1: Tipos de Medio de Pago
        Schema::create('sunat_table_01_payment_methods', function (Blueprint $table): void {
            $table->string('code', 5)->primary();
            $table->string('name', 300);
            $table->boolean('requires_financial_entity')->default(false);
            $table->boolean('requires_operation_number')->default(false);
            $table->boolean('is_foreign_trade')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // 3. SUNAT Tabla 2: Tipos de Documento de Identidad
        Schema::create('sunat_table_02_identity_document_types', function (Blueprint $table): void {
            $table->string('code', 2)->primary();
            $table->string('name', 100);
            $table->string('abbreviation', 15);
            $table->smallInteger('exact_length')->nullable(); // DNI = 8, RUC = 11
            $table->smallInteger('max_length')->default(15);
            $table->boolean('is_numeric_only')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // 4. SUNAT Tabla 3: Entidades Financieras
        Schema::create('sunat_table_03_financial_institutions', function (Blueprint $table): void {
            $table->string('code', 5)->primary();
            $table->string('name', 150);
            $table->string('short_name', 50)->nullable();
            $table->string('tax_id', 11)->nullable(); // RUC de la institución
            $table->boolean('is_bank')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // 5. SUNAT Tabla 5: Tipos de Existencia
        Schema::create('sunat_table_05_inventory_types', function (Blueprint $table): void {
            $table->string('code', 5)->primary();
            $table->string('name', 150);
            $table->string('related_account_element', 5)->nullable(); // Cta 20, 21, 24, etc.
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // 6. SUNAT Tabla 6: Unidades de Medida
        Schema::create('sunat_table_06_measurement_units', function (Blueprint $table): void {
            $table->string('code', 5)->primary();
            $table->string('name', 100);
            $table->string('symbol', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // 7. SUNAT Tabla 8: Códigos de Libros o Registros
        Schema::create('sunat_table_08_electronic_books', function (Blueprint $table): void {
            $table->string('code', 5)->primary();
            $table->string('name', 250);
            $table->string('ple_book_code', 10)->nullable(); // Formato PLE: 050100, 060100, etc.
            $table->boolean('is_compulsory_general_regime')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // 8. SUNAT Tabla 10: Tipos de Comprobante de Pago o Documento
        Schema::create('sunat_table_10_electronic_document_types', function (Blueprint $table): void {
            $table->string('code', 5)->primary();
            $table->string('name', 300);
            $table->string('short_name', 30)->nullable();
            $table->boolean('allows_tax_credit')->default(false); // Factura = true, Boleta = false
            $table->boolean('is_electronic_default')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // 9. SUNAT Tabla 11: Códigos de Aduanas
        Schema::create('sunat_table_11_customs_dependencies', function (Blueprint $table): void {
            $table->string('code', 5)->primary();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // 10. SUNAT Tabla 12: Tipos de Operación
        Schema::create('sunat_table_12_operation_types', function (Blueprint $table): void {
            $table->string('code', 5)->primary();
            $table->string('name', 150);
            $table->string('operation_category', 20)->default('GENERAL'); // VENTA, COMPRA, INVENTARIO, TRASLADO
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        // Asignación de permisos al rol de aplicación app_backend
        DB::unprepared(<<<'SQL'
        REVOKE ALL ON TABLE public.currencies FROM app_backend;
        GRANT SELECT ON TABLE public.currencies TO app_backend;
        GRANT USAGE, SELECT ON SEQUENCE public.currencies_id_seq TO app_backend;

        REVOKE ALL ON TABLE public.sunat_table_01_payment_methods FROM app_backend;
        GRANT SELECT ON TABLE public.sunat_table_01_payment_methods TO app_backend;

        REVOKE ALL ON TABLE public.sunat_table_02_identity_document_types FROM app_backend;
        GRANT SELECT ON TABLE public.sunat_table_02_identity_document_types TO app_backend;

        REVOKE ALL ON TABLE public.sunat_table_03_financial_institutions FROM app_backend;
        GRANT SELECT ON TABLE public.sunat_table_03_financial_institutions TO app_backend;

        REVOKE ALL ON TABLE public.sunat_table_05_inventory_types FROM app_backend;
        GRANT SELECT ON TABLE public.sunat_table_05_inventory_types TO app_backend;

        REVOKE ALL ON TABLE public.sunat_table_06_measurement_units FROM app_backend;
        GRANT SELECT ON TABLE public.sunat_table_06_measurement_units TO app_backend;

        REVOKE ALL ON TABLE public.sunat_table_08_electronic_books FROM app_backend;
        GRANT SELECT ON TABLE public.sunat_table_08_electronic_books TO app_backend;

        REVOKE ALL ON TABLE public.sunat_table_10_electronic_document_types FROM app_backend;
        GRANT SELECT ON TABLE public.sunat_table_10_electronic_document_types TO app_backend;

        REVOKE ALL ON TABLE public.sunat_table_11_customs_dependencies FROM app_backend;
        GRANT SELECT ON TABLE public.sunat_table_11_customs_dependencies TO app_backend;

        REVOKE ALL ON TABLE public.sunat_table_12_operation_types FROM app_backend;
        GRANT SELECT ON TABLE public.sunat_table_12_operation_types TO app_backend;
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('sunat_table_12_operation_types');
        Schema::dropIfExists('sunat_table_11_customs_dependencies');
        Schema::dropIfExists('sunat_table_10_electronic_document_types');
        Schema::dropIfExists('sunat_table_08_electronic_books');
        Schema::dropIfExists('sunat_table_06_measurement_units');
        Schema::dropIfExists('sunat_table_05_inventory_types');
        Schema::dropIfExists('sunat_table_03_financial_institutions');
        Schema::dropIfExists('sunat_table_02_identity_document_types');
        Schema::dropIfExists('sunat_table_01_payment_methods');
        Schema::dropIfExists('currencies');
    }
};
