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

$phase = 'BOOTSTRAP';

try {
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $phase = 'CONFIG';
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
        throw new RuntimeException('LOCAL_CONFIG');
    }

    $phase = 'DB_STATE';
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
        throw new RuntimeException('LOCAL_STATE');
    }

    $phase = 'REFERENCE_CURRENCIES';
    $currencies = Currency::query()->whereIn('code', ['PEN', 'USD'])->get()->keyBy('code');
    if ($currencies->count() !== 2) {
        throw new RuntimeException('LOCAL_CURRENCY');
    }

    $password = bin2hex(random_bytes(20));
    $createdFile = false;

    try {
        $phase = 'TRANSACTION';
        $details = DB::transaction(static function () use ($currencies, $password, $secretFile, &$createdFile, &$phase): array {
            $phase = 'USER';
            $user = User::query()->create([
                'name' => 'Usuario de prueba PG18',
                'email' => 'demo.pg18@example.test',
                'password' => $password,
            ]);
            $phase = 'COMPANY';
            $company = Company::query()->create([
                'tax_id' => '20999990099',
                'business_name' => 'EMPRESA FICTICIA - LABORATORIO PG18',
                'legal_address' => 'Dirección sintética (solo pruebas)',
                'is_active' => true,
            ]);

            $tenant = app(TenantContext::class);
            $accountCount = $tenant->run((int) $company->id, static function () use ($user, $company, $currencies, &$phase): int {
                $phase = 'TENANT_MEMBERSHIP';
                DB::table('company_user')->insert([
                    'company_id' => $company->id,
                    'user_id' => $user->id,
                    'role' => 'ADMIN',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $phase = 'TENANT_SETTINGS';
                CompanySetting::query()->create([
                    'base_currency_id' => $currencies['PEN']->id,
                    'secondary_currency_id' => $currencies['USD']->id,
                    'tax_regime' => 'GENERAL',
                ]);

                $phase = 'TENANT_CURRENCIES';
                foreach (['PEN' => true, 'USD' => false] as $code => $isBase) {
                    CompanyCurrency::query()->create([
                        'currency_id' => $currencies[$code]->id,
                        'is_base' => $isBase,
                        'is_active' => true,
                    ]);
                }

                $phase = 'SUBDIARIES';
                foreach ([
                    ['GENERAL', 'Diario general'],
                    ['BANCOS', 'Bancos'],
                ] as [$code, $name]) {
                    CompanySubdiary::query()->create(['code' => $code, 'name' => $name]);
                }

                $phase = 'AREAS';
                CompanyArea::query()->create([
                    'code' => 'ADM-DEMO', 'name' => 'Administración de prueba', 'is_active' => true,
                ]);
                CashFlowArea::query()->create([
                    'code' => 'OP-DEMO', 'name' => 'Operación de prueba',
                    'activity_category' => 'OPERATING',
                    'flow_direction' => 'BOTH', 'is_active' => true,
                ]);
                $phase = 'EXCHANGE_RATE';
                ExchangeRate::query()->create([
                    'rate_date' => now()->toDateString(),
                    'source_currency' => 'USD', 'target_currency' => 'PEN',
                    'buy_rate' => '3.741000', 'sell_rate' => '3.755000',
                ]);

                $phase = 'ACCOUNTS';
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

            $phase = 'CURRENT_COMPANY';
            $user->update(['current_company_id' => $company->id]);

            // Write credentials before DB commit, so inability to protect the
            // file rolls the SQL transaction back. Remove file on any failure.
            $phase = 'CREDENTIAL_FILE';
            $lines = [
                'LOCAL_DEMO_EMAIL=demo.pg18@example.test',
                'LOCAL_DEMO_PASSWORD='.$password,
                'LOCAL_DEMO_COMPANY_ID='.$company->id,
            ];
            if (file_put_contents($secretFile, implode(PHP_EOL, $lines).PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException('LOCAL_CREDENTIAL_FILE');
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
    // Only print static, allowlisted diagnostic codes. Never print SQL,
    // bindings, passwords, DB URLs, driver messages or token strings.
    $safeReasons = ['LOCAL_CONFIG', 'LOCAL_STATE', 'LOCAL_CURRENCY', 'LOCAL_CREDENTIAL_FILE'];
    $reason = $e instanceof RuntimeException && in_array($e->getMessage(), $safeReasons, true)
        ? $e->getMessage() : 'OTHER_ERROR';
    $sqlstate = 'NONE';
    if ($e instanceof \Illuminate\Database\QueryException) {
        $raw = (string) ($e->errorInfo[0] ?? '');
        if (preg_match('/^[0-9A-Z]{5}$/D', $raw) === 1) {
            $sqlstate = $raw;
        }
    }
    fwrite(STDERR, '[FAIL] Fixture PG18: fase='.$phase.'; motivo='.$reason
        .'; tipo='.get_class($e).'; SQLSTATE='.$sqlstate.".\n");
    exit(1);
}
