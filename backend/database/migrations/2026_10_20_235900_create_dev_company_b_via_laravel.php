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

        // Artisan --database=pgsql_migrations cambia la conexión predeterminada.
        // TenantContext, Modelos y servicio PCGE necesitan usar la MISMA conexión
        // operativa app_backend para que SET LOCAL sea visible bajo FORCE RLS.
        // Restablecer antes de que Artisan anote esta migración administrativa.
        $previousDefaultConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql');
        try {
            $tenant = app(TenantContext::class);
            // Elegir el usuario desde las dos tablas globales; no depender de un
            // cursor Eloquent cuya sesión pudiera no tener aún contexto del tenant.
            // La verificación de membresía se vuelve a efectuar con app_backend
            // DENTRO de TenantContext::run(), bajo RLS y con el usuario activo.
            $candidate = $db->selectOne(<<<'SQL'
    SELECT u.id AS user_id, c.id AS company_id
    FROM public.users u
    JOIN public.companies c ON c.id = u.current_company_id
    WHERE c.is_active IS TRUE
    ORDER BY u.id
    LIMIT 1
    SQL);
            if ($candidate === null) {
                throw new RuntimeException('No hay usuario con empresa activa en Supabase Dev.');
            }
            $ownerId = (int) $candidate->user_id;
            $sourceCompanyId = (int) $candidate->company_id;
            $check = $tenant->run($sourceCompanyId, static function () use ($db, $ownerId, $sourceCompanyId): array {
                $db->select("SELECT set_config('app.current_user_id', ?, true)", [(string) $ownerId]);
                $state = $db->selectOne(<<<'SQL'
    SELECT
        current_user AS database_role,
        public.app_current_company_id() AS tenant_id,
        nullif(current_setting('app.current_user_id', true), '') AS user_context,
        (SELECT count(*) FROM public.company_user
         WHERE company_id = ?::bigint
           AND user_id = ?::bigint
           AND role IN ('ADMIN','CHIEF_ACCOUNTANT')
           AND is_active IS TRUE) AS matching_memberships
    SQL, [$sourceCompanyId, $ownerId]);
    
                return [
                    'role' => (string) $state->database_role,
                    'tenant' => (int) ($state->tenant_id ?? 0),
                    'user' => (int) ($state->user_context ?? 0),
                    'matches' => (int) $state->matching_memberships,
                ];
            });
            if ($check['role'] !== 'app_backend'
                || $check['tenant'] !== $sourceCompanyId
                || $check['user'] !== $ownerId
                || $check['matches'] !== 1) {
                throw new RuntimeException(sprintf(
                    'Diagnóstico de contexto Laravel: role=%s, tenant=%d, user=%d, memberships=%d (esperados %d/%d/1).',
                    $check['role'], $check['tenant'], $check['user'], $check['matches'],
                    $sourceCompanyId, $ownerId
                ));
            }
            $owner = User::query()->findOrFail($ownerId);
    
            $currencies = Currency::query()->whereIn('code', ['PEN', 'USD'])
                ->whereRaw('currencies.is_active IS TRUE')->get()->keyBy('code');
            if (! isset($currencies['PEN'], $currencies['USD'])) {
                throw new RuntimeException('Se requieren monedas PEN y USD activas.');
            }
    
            // Las operaciones de negocio permanecen en UNA transacción app_backend.
            // La migración administrativa solo registra que la operación fue aplicada.
            // PostgreSQL + PDO emulate prepares: los booleanos enlazados como PHP bool\n            // se convierten en 1/0. SQL TRUE/FALSE fijos conservan tipo boolean.\n            $db->transaction(function () use ($db, $tenant, $owner, $currencies): void {
                $company = Company::query()->create([
                    'tax_id' => self::TAX_ID,
                    'business_name' => 'NEXUS EMPRESA PRUEBA B S.A.C.',
                    'legal_address' => 'Av. Industrial 100, Tacna - Entorno de desarrollo',
                    'is_active' => DB::raw('TRUE'),
                ]);
                $tenant->run((int) $company->id, function () use ($db, $company, $owner, $currencies): void {
                    $now = now();
                    $db->table('company_user')->insert([
                        'company_id' => $company->id,
                        'user_id' => $owner->id,
                        'role' => 'ADMIN',
                        'is_active' => DB::raw('TRUE'),
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
                        'is_sire_obliged' => DB::raw('FALSE'),
                        'is_ple_obliged' => DB::raw('FALSE'),
                    ]);
                    foreach (['PEN', 'USD'] as $code) {
                        CompanyCurrency::query()->create([
                            'company_id' => $company->id,
                            'currency_id' => $currencies[$code]->id,
                            'is_base' => DB::raw($code === 'PEN' ? 'TRUE' : 'FALSE'),
                            'is_active' => DB::raw('TRUE'),
                        ]);
                    }
                    CompanyEconomicActivity::query()->create([
                        'company_id' => $company->id,
                        'sector_code' => 'COMERCIAL',
                        'ciiu_code' => '4752',
                        'description' => 'Venta de artículos de ferretería, pinturas y productos de vidrio',
                        'is_primary' => DB::raw('TRUE'),
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
        } finally {
            DB::setDefaultConnection($previousDefaultConnection);
        }

    }

    public function down(): void
    {
        // Nunca eliminar automáticamente datos ni cuentas de una empresa de prueba
        // que podrían haber acumulado asientos después de aplicar esta migración.
        throw new RuntimeException('Rollback destructivo deshabilitado; evaluar eliminación manual controlada.');
    }
};
