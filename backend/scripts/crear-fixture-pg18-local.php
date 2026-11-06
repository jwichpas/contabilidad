<?php

declare(strict_types=1);

// One-time, synthetic, application-role fixture for an isolated PG18 Docker DB.
// Must be invoked only by preparar-demo-pg18-local.ps1 in a local PHP process.
// Never loads admin credentials, never connects to the remote Neon environment.
use App\Models\AccountingAccount;
use App\Models\CashFlowArea;
use App\Models\Company;
use App\Models\CompanyArea;
use App\Models\CompanyCurrency;
use App\Models\CompanySetting;
use App\Models\CompanySubdiary;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli' || count($argv) !== 1) {
    fwrite(STDERR, "[BLOQUEO] Solo ejecución CLI local sin argumentos.\n");
    exit(73);
}

$root = dirname(__DIR__);
$secretFile = $root.'/.env.pg18.demo.local';
if (is_file($secretFile)) {
    fwrite(STDERR, "[BLOQUEO] Ya existe un usuario de prueba local. No sobrescribir.\n");
    exit(73);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';

try {
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $dbConfig = (array) config('database.connections.pgsql');
    if (! app()->environment('local')
        || config('database.default') !== 'pgsql'
        || (string) ($dbConfig['host'] ?? '') !== '127.0.0.1'
        || (string) ($dbConfig['port'] ?? '') !== '55433'
        || (string) ($dbConfig['database'] ?? '') !== 'contabilidad_local'
        || (string) ($dbConfig['username'] ?? '') !== 'app_backend'
        || (string) ($dbConfig['sslmode'] ?? '') !== 'disable'
        || trim((string) ($dbConfig['url'] ?? '')) !== ''
        || ! is_string(config('app.key')) || strlen((string) config('app.key')) < 32) {
        throw new RuntimeException('Config app no autorizada para DB local.');
    }

    $info = DB::selectOne(<<<'SQL'
        SELECT current_database() AS db, current_user AS actor,
          current_setting('server_version_num')::integer AS version_num,
          (SELECT count(*) FROM public.migrations)::integer AS migrations,
          (SELECT count(*) FROM public.companies)::integer AS companies,
          (SELECT count(*) FROM public.users)::integer AS users,
          (SELECT count(*) FROM public.personal_access_tokens)::integer AS tokens,
          (SELECT count(*) FROM public.currencies)::integer AS currencies,
          (SELECT CASE WHEN has_table_privilege(current_user,'public.currencies','INSERT')
           THEN 1 ELSE 0 END)::integer AS currency_write
        SQL);

    if ($info?->db !== 'contabilidad_local'
        || $info?->actor !== 'app_backend'
        || intdiv((int) ($info?->version_num ?? 0), 10000) !== 18
        || (int) ($info?->migrations ?? 0) !== 25
        || (int) ($info?->companies ?? -1) !== 0
        || (int) ($info?->users ?? -1) !== 0
        || (int) ($info?->tokens ?? -1) !== 0
        || (int) ($info?->currencies ?? 0) !== 3
        || (int) ($info?->currency_write ?? 1) !== 0) {
        throw new RuntimeException('Estado de base local incompatible.');
    }

    $currencies = Currency::query()->whereIn('code', ['PEN', 'USD'])->get()->keyBy('code');
    if ($currencies->count() !== 2) {
        throw new RuntimeException('Faltan PEN/USD en catálogo de solo lectura.');
    }

    $password = bin2hex(random_bytes(20));
    $createdFile = false;

    try {
        $details = DB::transaction(static function () use ($currencies, $password, $secretFile, &$createdFile): array {
            $user = User::query()->create([
                'name' => 'Usuario de prueba PG18',
                'email' => 'demo.pg18@example.test',
                'password' => $password,
            ]);
            $company = Company::query()->create([
                'tax_id' => '20999990099',
                'business_name' => 'EMPRESA FICTICIA - LABORATORIO PG18',
                'legal_address' => 'Dirección sintética (solo pruebas)',
                'is_active' => true,
            ]);

            $tenant = app(TenantContext::class);
            $accountCount = $tenant->run((int) $company->id, static function () use ($user, $company, $currencies): int {
                DB::table('company_user')->insert([
                    'company_id' => $company->id,
                    'user_id' => $user->id,
                    'role' => 'ADMIN',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                CompanySetting::query()->create([
                    'base_currency_id' => $currencies['PEN']->id,
                    'secondary_currency_id' => $currencies['USD']->id,
                    'tax_regime' => 'GENERAL',
                ]);

                foreach (['PEN' => true, 'USD' => false] as $code => $isBase) {
                    CompanyCurrency::query()->create([
                        'currency_id' => $currencies[$code]->id,
                        'is_base' => $isBase,
                        'is_active' => true,
                    ]);
                }

                foreach ([
                    ['GENERAL', 'Diario general'],
                    ['BANCOS', 'Bancos'],
                ] as [$code, $name]) {
                    CompanySubdiary::query()->create(['code' => $code, 'name' => $name]);
                }

                CompanyArea::query()->create([
                    'code' => 'ADM-DEMO', 'name' => 'Administración de prueba', 'is_active' => true,
                ]);
                CashFlowArea::query()->create([
                    'code' => 'OP-DEMO', 'name' => 'Operación de prueba',
                    'activity_category' => 'OPERATING',
                    'flow_direction' => 'BOTH', 'is_active' => true,
                ]);
                ExchangeRate::query()->create([
                    'rate_date' => now()->toDateString(),
                    'source_currency' => 'USD', 'target_currency' => 'PEN',
                    'buy_rate' => '3.741000', 'sell_rate' => '3.755000',
                ]);

                // 200 synthetic 6-digit operational accounts at level 4.
                // PostgreSQL enforces level 1..4 and only level 4 operational.
                // The three parent nodes are 1 -> 12 -> 121.
                $parent = null;
                $path = '';
                foreach (['1', '12', '121'] as $level => $code) {
                    $account = AccountingAccount::query()->create([
                        'code' => $code, 'name' => 'Rama ficticia '.$code,
                        'parent_id' => $parent?->id,
                        'level' => $level + 1,
                        'path' => $path === '' ? $code : $path.'.'.$code,
                        'account_type' => 'ASSET', 'balance_type' => 'DEBIT',
                        'currency_mode' => 'PEN', 'is_operational' => false,
                        'is_active' => true,
                    ]);
                    $parent = $account;
                    $path = $account->path;
                }

                for ($i = 0; $i < 200; ++$i) {
                    $code = '121'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
                    AccountingAccount::query()->create([
                        'code' => $code, 'name' => 'Cuenta sintética '.$code,
                        'parent_id' => $parent->id, 'level' => 4,
                        'path' => $parent->path.'.'.$code,
                        'account_type' => 'ASSET', 'balance_type' => 'DEBIT',
                        'currency_mode' => 'PEN', 'is_operational' => true,
                        'is_active' => true,
                        'entity_requirement_type' => 'NONE',
                        'requires_auxiliary' => false,
                        'requires_company_area' => false,
                        'requires_cash_flow_area' => false,
                        'cash_flow_category' => 'NONE',
                    ]);
                }
                $total = 203;

                return $total;
            });

            $user->update(['current_company_id' => $company->id]);

            // Write credentials before DB commit, so inability to protect the
            // file rolls the SQL transaction back. Remove file on any failure.
            $lines = [
                'LOCAL_DEMO_EMAIL=demo.pg18@example.test',
                'LOCAL_DEMO_PASSWORD='.$password,
                'LOCAL_DEMO_COMPANY_ID='.$company->id,
            ];
            if (file_put_contents($secretFile, implode(PHP_EOL, $lines).PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('No se pudo guardar credenciales privadas.');
            }
            $createdFile = true;

            return ['company_id' => (int) $company->id, 'accounts' => $accountCount];
        }, attempts: 1);
    } catch (Throwable $e) {
        if ($createdFile && is_file($secretFile)) {
            @unlink($secretFile);
        }
        throw $e;
    }

    echo "[PASS] Empresa y usuario sintéticos creados con app_backend / RLS.\n";
    echo "[PASS] {$details['accounts']} cuentas ficticias; 200 operativas; PEN y USD.\n";
    echo "[PASS] Credenciales privadas guardadas en backend/.env.pg18.demo.local.\n";
    echo "[SIGUIENTE] Iniciar backend local 8011 y medir tiempos HTTP reales.\n";
} catch (Throwable $e) {
    // Never echo driver exceptions, SQL bindings, passwords or token values.
    fwrite(STDERR, '[FAIL] Fixture PG18 no completada: '.get_class($e).".\n");
    exit(1);
}
