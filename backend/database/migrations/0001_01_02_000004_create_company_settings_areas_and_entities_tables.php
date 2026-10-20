<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entidades Operativas, Áreas y Configuración Tributario-Contable de Empresa:
 * 1. `company_areas`: Áreas organizacionales de la empresa (Administración, Ventas, Operaciones, Finanzas, etc.)
 *    con FK compuesta `(company_id, id)` y RLS obligatorio.
 * 2. `company_settings`: Parámetros contables de la empresa (Moneda base/extranjera, régimen tributario,
 *    cuentas por defecto para IGV, Diferencia de Cambio NIC 21, Amarre Cta. 79, CPC y SIRE).
 * 3. `entities`: Directorio Maestro de Terceros / Anexos (Clientes, Proveedores, Bancos, Trabajadores, SUNAT)
 *    con validación de documento (Tabla 2 SUNAT), condición tributaria, parte relacionada (NIC 24) y RLS forzado.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Áreas de la Empresa
        Schema::create('company_areas', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->string('manager_name', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'id'], 'uq_company_areas_company_id');
            $table->unique(['company_id', 'code'], 'uq_company_areas_company_code');
        });

        // 2. Configuración General y Contable de la Empresa (1 a 1 con companies)
        Schema::create('company_settings', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('company_id')->unique()->constrained('companies')->restrictOnDelete();

            // Monedas de operación
            $table->foreignId('base_currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('secondary_currency_id')->nullable()->constrained('currencies')->restrictOnDelete();

            // Régimen tributario SUNAT
            $table->string('tax_regime', 40)->default('GENERAL'); // GENERAL, MYPE_TRIBUTARIO, RER, ESPECIAL
            $table->decimal('default_vat_rate', 5, 2)->default(18.00); // 18.00% IGV general o 10.00% especial
            $table->boolean('is_retention_agent')->default(false);
            $table->boolean('is_perception_agent')->default(false);
            $table->boolean('is_good_taxpayer')->default(false); // Buenos Contribuyentes SUNAT
            $table->boolean('is_sire_obliged')->default(true); // Obligado a SIRE (RVIE y RCE)
            $table->boolean('is_ple_obliged')->default(false); // Libros Electrónicos PLE

            // Representante Legal y Contador
            $table->string('legal_representative_name', 150)->nullable();
            $table->string('legal_rep_identity_doc_code', 2)->nullable();
            $table->string('legal_representative_id_number', 15)->nullable();
            $table->string('chief_accountant_name', 150)->nullable();
            $table->string('chief_accountant_cpc_number', 30)->nullable(); // Matrícula Colegio de Contadores

            // Cuentas contables automáticas por defecto (mismo tenant)
            $table->unsignedBigInteger('sales_vat_account_id')->nullable();
            $table->unsignedBigInteger('purchase_vat_account_id')->nullable();
            $table->unsignedBigInteger('fx_gain_account_id')->nullable(); // Cta 776
            $table->unsignedBigInteger('fx_loss_account_id')->nullable(); // Cta 676
            $table->unsignedBigInteger('cost_absorption_account_id')->nullable(); // Cta 7911
            $table->unsignedBigInteger('retained_earnings_account_id')->nullable(); // Cta 5911

            $table->timestampsTz();

            $table->unique(['company_id', 'id'], 'uq_company_settings_company_id');
        });

        // 3. Directorio Maestro de Entidades / Terceros (Clientes, Proveedores, Bancos, etc.)
        Schema::create('entities', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('identity_document_type_code', 2);
            $table->string('tax_id', 20); // DNI, RUC, Pasaporte
            $table->string('legal_name', 200); // Razón Social o Nombre Completo
            $table->string('commercial_name', 200)->nullable();
            $table->string('fiscal_address', 250)->nullable();
            $table->string('country_code', 3)->default('PER'); // ISO 3166-1 alpha-3
            $table->string('email', 120)->nullable();
            $table->string('phone', 30)->nullable();

            // Roles de la Entidad
            $table->boolean('is_client')->default(false);
            $table->boolean('is_supplier')->default(false);
            $table->boolean('is_employee')->default(false);
            $table->boolean('is_financial_institution')->default(false);
            $table->boolean('is_shareholder')->default(false);
            $table->boolean('is_tax_authority')->default(false); // SUNAT, Municipalidades

            // Datos Bancarios y Financieros
            $table->string('bank_code', 5)->nullable(); // FK a sunat_table_03_financial_institutions
            $table->string('bank_account_number', 40)->nullable();
            $table->string('bank_cci_number', 40)->nullable();

            // Indicadores Tributarios SUNAT
            $table->boolean('is_related_party')->default(false); // Parte Relacionada (NIC 24)
            $table->boolean('is_retention_agent')->default(false);
            $table->boolean('is_good_taxpayer')->default(false);
            $table->boolean('has_detraction_account')->default(false);
            $table->string('detraction_account_number', 30)->nullable(); // Banco de la Nación

            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'id'], 'uq_entities_company_id');
            $table->unique(['company_id', 'identity_document_type_code', 'tax_id'], 'uq_entities_company_doc');
            $table->index(['company_id', 'legal_name'], 'idx_entities_company_name');
            $table->index(['company_id', 'is_client', 'is_active'], 'idx_entities_clients');
            $table->index(['company_id', 'is_supplier', 'is_active'], 'idx_entities_suppliers');
        });

        DB::unprepared(<<<'SQL'
        -- FKs compuestas intra-tenant en company_settings hacia accounting_accounts
        ALTER TABLE public.company_settings
            ADD CONSTRAINT fk_company_settings_sales_vat
            FOREIGN KEY (company_id, sales_vat_account_id)
            REFERENCES public.accounting_accounts (company_id, id)
            ON UPDATE RESTRICT ON DELETE SET NULL;

        ALTER TABLE public.company_settings
            ADD CONSTRAINT fk_company_settings_purchase_vat
            FOREIGN KEY (company_id, purchase_vat_account_id)
            REFERENCES public.accounting_accounts (company_id, id)
            ON UPDATE RESTRICT ON DELETE SET NULL;

        ALTER TABLE public.company_settings
            ADD CONSTRAINT fk_company_settings_fx_gain
            FOREIGN KEY (company_id, fx_gain_account_id)
            REFERENCES public.accounting_accounts (company_id, id)
            ON UPDATE RESTRICT ON DELETE SET NULL;

        ALTER TABLE public.company_settings
            ADD CONSTRAINT fk_company_settings_fx_loss
            FOREIGN KEY (company_id, fx_loss_account_id)
            REFERENCES public.accounting_accounts (company_id, id)
            ON UPDATE RESTRICT ON DELETE SET NULL;

        ALTER TABLE public.company_settings
            ADD CONSTRAINT fk_company_settings_cost_absorption
            FOREIGN KEY (company_id, cost_absorption_account_id)
            REFERENCES public.accounting_accounts (company_id, id)
            ON UPDATE RESTRICT ON DELETE SET NULL;

        ALTER TABLE public.company_settings
            ADD CONSTRAINT fk_company_settings_retained_earnings
            FOREIGN KEY (company_id, retained_earnings_account_id)
            REFERENCES public.accounting_accounts (company_id, id)
            ON UPDATE RESTRICT ON DELETE SET NULL;

        -- FKs a catálogos SUNAT
        ALTER TABLE public.company_settings
            ADD CONSTRAINT fk_company_settings_legal_rep_doc
            FOREIGN KEY (legal_rep_identity_doc_code)
            REFERENCES public.sunat_table_02_identity_document_types (code)
            ON UPDATE RESTRICT ON DELETE SET NULL;

        ALTER TABLE public.entities
            ADD CONSTRAINT fk_entities_identity_doc_type
            FOREIGN KEY (identity_document_type_code)
            REFERENCES public.sunat_table_02_identity_document_types (code)
            ON UPDATE RESTRICT ON DELETE RESTRICT;

        ALTER TABLE public.entities
            ADD CONSTRAINT fk_entities_bank
            FOREIGN KEY (bank_code)
            REFERENCES public.sunat_table_03_financial_institutions (code)
            ON UPDATE RESTRICT ON DELETE SET NULL;

        -- Row Level Security (ENABLE + FORCE)
        ALTER TABLE public.company_areas ENABLE ROW LEVEL SECURITY;
        ALTER TABLE public.company_areas FORCE ROW LEVEL SECURITY;

        CREATE POLICY company_areas_tenant_isolation_policy
            ON public.company_areas
            AS PERMISSIVE
            FOR ALL
            TO app_backend
            USING (company_id = public.app_current_company_id())
            WITH CHECK (company_id = public.app_current_company_id());

        ALTER TABLE public.company_settings ENABLE ROW LEVEL SECURITY;
        ALTER TABLE public.company_settings FORCE ROW LEVEL SECURITY;

        CREATE POLICY company_settings_tenant_isolation_policy
            ON public.company_settings
            AS PERMISSIVE
            FOR ALL
            TO app_backend
            USING (company_id = public.app_current_company_id())
            WITH CHECK (company_id = public.app_current_company_id());

        ALTER TABLE public.entities ENABLE ROW LEVEL SECURITY;
        ALTER TABLE public.entities FORCE ROW LEVEL SECURITY;

        CREATE POLICY entities_tenant_isolation_policy
            ON public.entities
            AS PERMISSIVE
            FOR ALL
            TO app_backend
            USING (company_id = public.app_current_company_id())
            WITH CHECK (company_id = public.app_current_company_id());

        REVOKE ALL ON TABLE public.company_areas FROM app_backend;
        GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.company_areas TO app_backend;
        GRANT USAGE, SELECT ON SEQUENCE public.company_areas_id_seq TO app_backend;

        REVOKE ALL ON TABLE public.company_settings FROM app_backend;
        GRANT SELECT, INSERT, UPDATE ON TABLE public.company_settings TO app_backend;
        GRANT USAGE, SELECT ON SEQUENCE public.company_settings_id_seq TO app_backend;

        REVOKE ALL ON TABLE public.entities FROM app_backend;
        GRANT SELECT, INSERT, UPDATE ON TABLE public.entities TO app_backend;
        GRANT USAGE, SELECT ON SEQUENCE public.entities_id_seq TO app_backend;
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('entities');
        Schema::dropIfExists('company_settings');
        Schema::dropIfExists('company_areas');
    }
};
