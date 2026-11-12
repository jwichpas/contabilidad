<?php

declare(strict_types=1);

/**
 * Creates ONE SEPARATE PCGE_2019 demo company under the existing local demo
 * login, preserving the original synthetic benchmark company and credentials.
 * Only the PowerShell Docker-only wrapper may launch this guarded one-time
 * command. No real tax documents, passwords, journal postings or fake FX rates.
 */
if (PHP_SAPI !== 'cli' || $argc !== 1) {
    exit(73);
}

$backend = dirname(__DIR__);
require $backend.'/vendor/autoload.php';

try {
    $app = require $backend.'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $cfg = (array) config('database.connections.pgsql');
    $admin = (array) config('database.connections.pgsql_migrations');

    $expected = static function (array $conn): bool {
        return ($conn['driver'] ?? '') === 'pgsql'
            && ($conn['host'] ?? '') === '127.0.0.1'
            && (string) ($conn['port'] ?? '') === '55433'
            && ($conn['database'] ?? '') === 'contabilidad_local'
            && ($conn['username'] ?? '') === 'app_backend'
            && ($conn['sslmode'] ?? '') === 'disable'
            && trim((string) ($conn['url'] ?? '')) === ''
            && is_string($conn['password'] ?? null)
            && $conn['password'] !== '';
    };
    if (! app()->environment('local')
        || config('database.default') !== 'pgsql'
        || ! $expected($cfg) || ! $expected($admin)
        || is_file($backend.'/bootstrap/cache/config.php')) {
        throw new RuntimeException('Laravel no esta configurado para app_backend LOCAL exclusivamente.');
    }

    $db = \Illuminate\Support\Facades\DB::connection('pgsql');
    $current = $db->selectOne(<<<'SQL'
        SELECT current_database() AS db, current_user AS actor,
          current_setting('server_version_num')::integer AS pg,
          (SELECT count(*) FROM public.users)::integer AS users,
          (SELECT count(*) FROM public.companies)::integer AS companies,
          (SELECT count(*) FROM public.accounting_account_templates)::integer AS templates
        SQL);
    if ($current?->db !== 'contabilidad_local'
        || $current?->actor !== 'app_backend'
        || intdiv((int) ($current?->pg ?? 0), 10000) !== 18
        || (int) $current->users !== 1
        || (int) $current->companies !== 1
        || (int) $current->templates !== 3401) {
        throw new RuntimeException('Estado base del laboratorio PCGE inesperado.');
    }

    $user = \App\Models\User::query()
        ->where('email', 'demo.pg18@example.test')->first();
    $oldCompany = \App\Models\Company::query()
        ->where('tax_id', '20999990099')->first();
    if ($user === null || $oldCompany === null
        || (int) $user->current_company_id !== (int) $oldCompany->id
        || \App\Models\Company::query()->where('tax_id', '20999990101')->exists()) {
        throw new RuntimeException('Usuario original o empresa objetivo no coinciden; no modificar.');
    }

    $gate = app(\App\Support\Company\GlobalReferenceReadiness::class);
    if ($gate->issue() !== null) {
        throw new RuntimeException('Los catalogos globales completos no estan disponibles.');
    }
    $tenant = app(\App\Support\Tenant\TenantContext::class);
    $oldCount = $tenant->run((int) $oldCompany->id,
        static fn (): int => (int) \Illuminate\Support\Facades\DB::table('accounting_accounts')->count());
    if ($oldCount !== 203) {
        throw new RuntimeException('El plan ficticio anterior cambio; preservar sin modificar.');
    }
    $pen = \App\Models\Currency::query()->where('code', 'PEN')->firstOrFail();
    $usd = \App\Models\Currency::query()->where('code', 'USD')->firstOrFail();

    $companyInfo = $db->transaction(static function () use ($user, $tenant, $pen, $usd): array {
        // The original fixture remains untouched; this new company gets its
        // OWN PCGE account ids, settings, areas, cost centers and subdiaries.
        $company = \App\Models\Company::query()->create([
            'tax_id' => '20999990101',
            'business_name' => 'EMPRESA DEMO PCGE 2019 - SOLO DOCKER',
            'legal_address' => 'Domicilio de laboratorio (no real)',
            'is_active' => true,
        ]);
        $result = $tenant->run((int) $company->id, static function () use ($company, $user, $pen, $usd): array {
            \Illuminate\Support\Facades\DB::table('company_user')->insert([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'role' => 'ADMIN', 'is_active' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            \App\Models\CompanySetting::query()->create([
                'base_currency_id' => $pen->id,
                'secondary_currency_id' => $usd->id,
                'tax_regime' => 'GENERAL',
                'default_vat_rate' => '18.00',
                'is_sire_obliged' => false, 'is_ple_obliged' => false,
            ]);
            foreach ([[$pen, true], [$usd, false]] as [$currency, $isBase]) {
                \App\Models\CompanyCurrency::query()->create([
                    'currency_id' => $currency->id,
                    'is_base' => $isBase,
                    'is_active' => true,
                ]);
            }
            foreach ([
                ['GENERAL','Diario general'], ['COMPRAS','Compras'],
                ['VENTAS','Ventas'], ['CAJA','Caja'], ['BANCOS','Bancos'],
                ['PLANILLAS','Planillas'], ['AJUSTES','Ajustes'],
                ['APERTURA_CIERRE','Apertura y cierre'],
            ] as [$code, $name]) {
                \App\Models\CompanySubdiary::query()->create([
                    'code' => $code, 'name' => $name, 'is_active' => true,
                ]);
            }
            foreach ([
                ['ADM-DEMO','Administracion de laboratorio'],
                ['COM-DEMO','Ventas de laboratorio'],
            ] as [$code, $name]) {
                \App\Models\CompanyArea::query()->create([
                    'code' => $code, 'name' => $name, 'is_active' => true,
                ]);
            }
            foreach ([
                ['OPE-IN','Cobros de clientes', 'INFLOW'],
                ['OPE-OUT','Pagos a proveedores', 'OUTFLOW'],
            ] as [$code, $name, $flow]) {
                \App\Models\CashFlowArea::query()->create([
                    'code' => $code, 'name' => $name,
                    'activity_category' => 'OPERATING',
                    'flow_direction' => $flow, 'is_active' => true,
                ]);
            }
            \App\Models\FiscalPeriod::query()->create([
                'year' => 2026, 'month' => 11,
                'start_date' => '2026-11-01',
                'end_date' => '2026-11-30', 'status' => 'OPEN',
            ]);

            // These are deliberately synthetic taxpayer identifiers,
            // never intended for official submission to SUNAT.
            foreach ([
                ['20999990119', 'PROVEEDOR FICTICIO - PRUEBA DOCKER', true, false],
                ['20999990127', 'CLIENTE FICTICIO - PRUEBA DOCKER', false, true],
            ] as [$taxId, $name, $supplier, $client]) {
                \App\Models\Entity::query()->create([
                    'identity_document_type_code' => '6',
                    'tax_id' => $taxId,
                    'legal_name' => $name,
                    'country_code' => 'PER',
                    'is_supplier' => $supplier,
                    'is_client' => $client,
                    'is_active' => true,
                ]);
            }

            \Illuminate\Support\Facades\DB::table('company_fiscal_profiles')->insert([
                'company_id' => $company->id,
                'fiscal_year' => 2026, 'revision' => 1,
                'effective_date' => '2026-11-01',
                'tax_regime' => 'GENERAL',
                'default_vat_rate' => '18.00',
                'is_sire_obliged' => false, 'is_ple_obliged' => false,
                'change_reason' => 'Configuracion ficticia exclusiva de Docker local',
                'created_by' => $user->id,
                'created_at' => now(),
            ]);

            $initialized = app(\App\Services\Accounting\InitializeCompanyChartOfAccountsService::class)
                ->initialize((int) $company->id, 'PCGE_2019', false, true);
            $accountCount = (int) ($initialized['total_accounts'] ?? 0);
            $actual = (int) \Illuminate\Support\Facades\DB::table('accounting_accounts')
                ->where('company_id', $company->id)->count();
            $centers = (int) \Illuminate\Support\Facades\DB::table('cost_centers')
                ->where('company_id', $company->id)->count();
            if ($actual !== $accountCount || $accountCount < 2955 || $centers !== 2) {
                throw new RuntimeException('No se completo el PCGE ni los centros analiticos.');
            }
            foreach (['401110','791000','941100','951100'] as $code) {
                if (! \Illuminate\Support\Facades\DB::table('accounting_accounts')
                    ->where('company_id', $company->id)
                    ->where('code', $code)->exists()) {
                    throw new RuntimeException('Falta la cuenta operativa PCGE '.$code);
                }
            }
            return ['accounts' => $accountCount, 'cost_centers' => $centers];
        });

        // No password is changed; same demo login, new selected company.
        $user->update(['current_company_id' => $company->id]);
        return ['company_id' => (int) $company->id, ...$result];
    }, attempts: 1);

    echo '[PASS] Empresa PCGE de pruebas creada: ID '.$companyInfo['company_id']."\n";
    echo '[PASS] Cuentas: '.$companyInfo['accounts'].' | centros: '.$companyInfo['cost_centers']."\n";
    echo "[PASS] Mismo usuario: demo.pg18@example.test; conservar su clave anterior.\n";
    echo "[NOTA] La empresa original y su plan ficticio siguen disponibles.\n";
    echo "[PENDIENTE] Ingresar un tipo de cambio DIARIO verificado para la nueva empresa.\n";
} catch (\Throwable $e) {
    fwrite(STDERR, '[BLOQUEO] Empresa PCGE no creada: '
        .($e instanceof RuntimeException ? $e->getMessage() : 'error interno')."\n");
    exit(1);
}
