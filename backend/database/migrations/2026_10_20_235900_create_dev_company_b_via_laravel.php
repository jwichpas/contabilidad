<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyEconomicActivity;
use App\Models\CompanySetting;
use App\Models\Currency;
use App\Models\User;
use App\Services\Accounting\InitializeCompanyChartOfAccountsService;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Datos ficticios de F1.2: segundo tenant completo, creado por Laravel.
 *
 * EJECUTAR SOLO en Supabase Dev con --database=pgsql_migrations --path=...
 * Es una migración excepcional de datos de desarrollo, NO un patrón de producción.
 * Todas las escrituras de negocio usan la conexión app_backend + RLS.
 * Las cuentas PCGE las genera el servicio oficial, nunca un INSERT ad hoc.
 */
return new class extends Migration
{
    private const TAX_ID = '20999999990';

    public function up(): void
    {
        $appConfig = (array) config('database.connections.pgsql');
        $adminConfig = (array) config('database.connections.pgsql_migrations');
        if (! app()->environment('local')
            || (string) ($appConfig['host'] ?? '') !== 'aws-1-ca-central-1.pooler.supabase.com'
            || (string) ($appConfig['port'] ?? '') !== '6543'
            || (string) ($appConfig['username'] ?? '') !== 'app_backend.bnjwxphwtkhblvhsnylj'
            || (string) ($adminConfig['database'] ?? '') !== 'postgres') {
            throw new RuntimeException('Bloqueo: esta migración es exclusiva de Supabase Dev en entorno local.');
        }

        $db = DB::connection('pgsql');
        $identity = $db->selectOne('SELECT current_user AS role_name, current_database() AS db_name');
        if ($identity?->role_name !== 'app_backend' || $identity?->db_name !== 'postgres') {
            throw new RuntimeException('La conexión de datos debe utilizar exclusivamente app_backend.');
        }

        if ($db->table('companies')->where('tax_id', self::TAX_ID)->exists()) {
            throw new RuntimeException('La empresa ficticia ya existe. No se permite alta duplicada.');
        }

        $tenant = app(TenantContext::class);
        $owner = null;

        // Alta EXCLUSIVA de datos ficticios Dev, no altera el guard de la API.
        // En el entorno actual el usuario válido tiene CHIEF_ACCOUNTANT, no ADMIN.
        // Se verifica empresa/membresía activa bajo el contexto RLS real.
        foreach (User::query()->whereNotNull('current_company_id')->orderBy('id')->cursor() as $candidate) {
            $currentCompanyId = (int) $candidate->current_company_id;
            $eligible = $tenant->run($currentCompanyId, static function () use ($db, $candidate, $currentCompanyId): bool {
                return $db->table('company_user')
                    ->join('companies', 'companies.id', '=', 'company_user.company_id')
                    ->where('company_user.company_id', $currentCompanyId)
                    ->where('company_user.user_id', $candidate->id)
                    ->whereIn('company_user.role', ['ADMIN', 'CHIEF_ACCOUNTANT'])
                    ->whereRaw('company_user.is_active IS TRUE')
                    ->whereRaw('companies.is_active IS TRUE')
                    ->exists();
            });
            if ($eligible) {
                $owner = $candidate;
                break;
            }
        }
        if ($owner === null) {
            throw new RuntimeException('Se requiere membresía activa ADMIN o CHIEF_ACCOUNTANT para la empresa ficticia Dev.');
        }

        $currencies = Currency::query()->whereIn('code', ['PEN', 'USD'])
            ->whereRaw('currencies.is_active IS TRUE')->get()->keyBy('code');
        if (! isset($currencies['PEN'], $currencies['USD'])) {
            throw new RuntimeException('Se requieren monedas PEN y USD activas.');
        }

        // Las operaciones de negocio permanecen en UNA transacción app_backend.
        // La migración administrativa solo registra que la operación fue aplicada.
        $db->transaction(function () use ($db, $tenant, $owner, $currencies): void {
            $company = Company::query()->create([
                'tax_id' => self::TAX_ID,
                'business_name' => 'NEXUS EMPRESA PRUEBA B S.A.C.',
                'legal_address' => 'Av. Industrial 100, Tacna - Entorno de desarrollo',
                'is_active' => true,
            ]);
            $tenant->run((int) $company->id, function () use ($db, $company, $owner, $currencies): void {
                $now = now();
                $db->table('company_user')->insert([
                    'company_id' => $company->id,
                    'user_id' => $owner->id,
                    'role' => 'ADMIN',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                CompanySetting::query()->create([
                    'company_id' => $company->id,
                    'base_currency_id' => $currencies['PEN']->id,
                    'secondary_currency_id' => $currencies['USD']->id,
                    'legal_form' => 'SAC',
                    'tax_regime' => 'GENERAL',
                    'initial_fiscal_year' => 2026,
                    'income_reference_year' => 2025,
                    'annual_income' => '0.00',
                    'initial_pcge_version' => 'PCGE_2019',
                    'default_vat_rate' => '18.00',
                    'is_sire_obliged' => false,
                    'is_ple_obliged' => false,
                ]);
                foreach (['PEN', 'USD'] as $code) {
                    CompanyCurrency::query()->create([
                        'company_id' => $company->id,
                        'currency_id' => $currencies[$code]->id,
                        'is_base' => $code === 'PEN',
                        'is_active' => true,
                    ]);
                }
                CompanyEconomicActivity::query()->create([
                    'company_id' => $company->id,
                    'sector_code' => 'COMERCIAL',
                    'ciiu_code' => '4752',
                    'description' => 'Venta de artículos de ferretería, pinturas y productos de vidrio',
                    'is_primary' => true,
                    'created_by' => $owner->id,
                    'updated_by' => $owner->id,
                ]);
                $plan = app(InitializeCompanyChartOfAccountsService::class)
                    ->initialize((int) $company->id, 'PCGE_2019', false);
                if ($plan['total_accounts'] < 1) {
                    throw new RuntimeException('No se inicializó el plan contable; se revierte el alta.');
                }
            });
        });
    }

    public function down(): void
    {
        // Nunca eliminar automáticamente datos ni cuentas de una empresa de prueba
        // que podrían haber acumulado asientos después de aplicar esta migración.
        throw new RuntimeException('Rollback destructivo deshabilitado; evaluar eliminación manual controlada.');
    }
};
