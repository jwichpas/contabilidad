<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla `company_currencies` para parametrizar las monedas de operación de cada empresa (Tenant):
 * - Permite elegir cualquier moneda base (por defecto PEN) y una o más monedas secundarias de trabajo (USD, EUR, etc.).
 * - Si la empresa selecciona más de 2 monedas, el sistema exigirá obligatoriamente el tipo de cambio
 *   por cada moneda secundaria activa en la fecha de registro de cada asiento contable.
 * - Protegida con FORCE ROW LEVEL SECURITY y aislamiento estricto multi-tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_currencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->boolean('is_base')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['company_id', 'currency_id'], 'uq_company_currencies_company_currency');
            $table->index(['company_id', 'is_active', 'is_base'], 'idx_company_currencies_lookup');
        });

        DB::unprepared(<<<'SQL'
        -- Row Level Security (ENABLE + FORCE)
        ALTER TABLE public.company_currencies ENABLE ROW LEVEL SECURITY;
        ALTER TABLE public.company_currencies FORCE ROW LEVEL SECURITY;

        CREATE POLICY company_currencies_tenant_isolation_policy
            ON public.company_currencies
            AS PERMISSIVE
            FOR ALL
            TO app_backend
            USING (company_id = public.app_current_company_id())
            WITH CHECK (company_id = public.app_current_company_id());

        GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.company_currencies TO app_backend;
        GRANT USAGE, SELECT ON SEQUENCE public.company_currencies_id_seq TO app_backend;
        SQL);

        // Migración inicial de datos existentes desde company_settings
        DB::unprepared(<<<'SQL'
        INSERT INTO public.company_currencies (company_id, currency_id, is_base, is_active, created_at, updated_at)
        SELECT cs.company_id, cs.base_currency_id, true, true, NOW(), NOW()
        FROM public.company_settings cs
        ON CONFLICT (company_id, currency_id) DO NOTHING;

        INSERT INTO public.company_currencies (company_id, currency_id, is_base, is_active, created_at, updated_at)
        SELECT cs.company_id, cs.secondary_currency_id, false, true, NOW(), NOW()
        FROM public.company_settings cs
        WHERE cs.secondary_currency_id IS NOT NULL
        ON CONFLICT (company_id, currency_id) DO NOTHING;
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('company_currencies');
    }
};
