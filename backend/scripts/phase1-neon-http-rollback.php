<?php

declare(strict_types=1);

/**
 * F1.9 — Laravel HTTP Kernel + Sanctum actingAs (rol multiempresa).
 *
 * php scripts/phase1-neon-http-rollback.php --plan
 * php scripts/phase1-neon-http-rollback.php --probe=contabilidad-dev
 *
 * --probe usa exclusivamente Neon dev, app_backend y una transaccion
 * Laravel global con ROLLBACK; NO consume secuencias ni crea tokens.
 * Sanctum::actingAs simula usuarios autenticados; NO prueba login real por token.
 */
$backend = dirname(__DIR__);
$host = 'ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$mode = $argv[1] ?? '--plan';
if ($argc !== 2 && $argc !== 1
    || ! in_array($mode, ['--plan', '--probe=contabilidad-dev'], true)) {
    fwrite(STDERR, "[FAIL] Uso: php scripts/phase1-neon-http-rollback.php [--plan|--probe=contabilidad-dev]\n");
    exit(2);
}

if ($mode === '--plan') {
    echo "[PLAN] HTTP Kernel Laravel con app_backend, Sanctum::actingAs y contexto empresa A/B.\n";
    echo "[PLAN] Comprueba HTTP 401 (anónimo), 403 (sin permisos), 422 (autorizado, validación), 200 (lectura).\n";
    echo "[PLAN] Inserta usuarios, membresías y 2 empresas con IDs explícitos dentro de una transacción.\n";
    echo "[PLAN] ROLLBACK obligatorio y verificación de cero datos ficticios persistentes.\n";
    echo "[AVISO] No prueba emisión/autenticación real de tokens ni posteo completo del libro diario.\n";
    echo "[DRY-RUN] Ningún SQL ni petición HTTP se ejecutó.\n";
    exit(0);
}

if (! is_file($backend.'/.env') || is_file($backend.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "[FAIL] Falta .env o existe configuracion cacheada.\n");
    exit(1);
}
$keys = [];
$duplicates = [];
$lines = file($backend.'/.env', FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "[FAIL] No se pudo leer .env.\n");
    exit(1);
}
foreach ($lines as $line) {
    if (! preg_match('/^[ \t]*(?:export[ \t]+)?([A-Z][A-Z0-9_]*)[ \t]*=/', $line, $match)) {
        continue;
    }
    $key = $match[1];
    if (str_starts_with($key, 'DB_') || str_starts_with($key, 'NEON_')
        || in_array($key, ['APP_ENV','APP_KEY','DATABASE_URL'], true)) {
        if (isset($keys[$key])) {
            $duplicates[] = $key;
        }
        $keys[$key] = true;
    }
}
unset($lines);
if ($duplicates !== []) {
    fwrite(STDERR, '[FAIL] Claves duplicadas en .env: '.implode(', ', array_unique($duplicates)).PHP_EOL);
    exit(1);
}

require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$traffic = (array) config('database.connections.pgsql', []);
$admin = (array) config('database.connections.pgsql_migrations', []);
$appPwd = (string) ($traffic['password'] ?? '');
$rolePwd = (string) env('DB_APP_BACKEND_ROLE_PASSWORD', '');
if (! $app->environment('local')
    || config('database.default') !== 'pgsql'
    || (string) env('NEON_TEST_BRANCH_NAME','') !== 'contabilidad-dev'
    || (string) env('NEON_TEST_BRANCH_HOST','') !== $host
    || (string) ($traffic['host'] ?? '') !== $host
    || (string) ($admin['host'] ?? '') !== $host
    || (string) ($traffic['database'] ?? '') !== 'neondb'
    || (string) ($admin['database'] ?? '') !== 'neondb'
    || (string) ($traffic['port'] ?? '') !== '5432'
    || (string) ($admin['port'] ?? '') !== '5432'
    || (string) ($traffic['username'] ?? '') !== 'app_backend'
    || (string) ($admin['username'] ?? '') !== 'neondb_owner'
    || trim((string) ($traffic['url'] ?? '')) !== ''
    || trim((string) ($admin['url'] ?? '')) !== ''
    || ! in_array((string) ($traffic['sslmode'] ?? ''), ['require','verify-ca','verify-full'], true)
    || filter_var(env('NEON_USE_POOLING', false), FILTER_VALIDATE_BOOLEAN)
    || strlen($rolePwd) < 20
    || $appPwd === '' || ! hash_equals($appPwd, $rolePwd)) {
    fwrite(STDERR, "[FAIL] Entorno incompatible: sólo Neon contabilidad-dev y app_backend directo.\n");
    exit(1);
}
unset($appPwd,$rolePwd);

$db = \Illuminate\Support\Facades\DB::connection('pgsql');
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$begun = false;
$rolledBack = false;
$passed = false;
$companyA = random_int(4000000000000, 4499999999999);
$companyB = random_int(4500000000000, 4999999999999);
$userAdmin = random_int(6000000000000, 6499999999999);
$userAccountant = random_int(6500000000000, 6999999999999);
$userOperator = random_int(7000000000000, 7499999999999);
$memberBase = random_int(8000000000000, 8499999999999);
$ids = [$companyA,$companyB];
$userIds = [$userAdmin,$userAccountant,$userOperator];
$assertions = 0;

try {
    $db->beginTransaction(); // IMPORTANTE: el gestor Laravel conoce la transacción.
    $begun = true;
    $db->selectOne("SELECT set_config('statement_timeout','15000',true) AS val");

    $identity = $db->selectOne(<<<'SQL'
        SELECT current_user AS role_name, current_database() AS db_name,
               current_setting('server_version_num')::integer AS pg_ver,
               (SELECT count(*) FROM public.companies)::integer AS company_count,
               (SELECT count(*) FROM public.users)::integer AS user_count,
               (SELECT count(*) FROM pg_catalog.pg_roles WHERE rolname=current_user
                 AND NOT rolsuper AND NOT rolbypassrls
                 AND NOT rolcreaterole AND NOT rolcreatedb)::integer AS safe_role
        SQL);
    if (($identity->role_name ?? '') !== 'app_backend'
        || ($identity->db_name ?? '') !== 'neondb'
        || intdiv((int) ($identity->pg_ver ?? 0),10000) !== 18
        || (int) ($identity->company_count ?? -1) !== 0
        || (int) ($identity->user_count ?? -1) !== 0
        || (int) ($identity->safe_role ?? 0) !== 1) {
        throw new \RuntimeException('La base debe ser Neon PG18 dev vacía y la conexión app_backend restringida.');
    }
    echo '[PASS] Neon dev verificada: PG18, 0 empresas, 0 usuarios y rol app_backend.'.PHP_EOL;

    foreach ([[$companyA,'A'],[$companyB,'B']] as [$companyId,$label]) {
        $db->table('companies')->insert([
            'id'=>$companyId,
            'tax_id'=>'20'.(string)random_int(100000000,999999999),
            'business_name'=>'F1.9 TEMP Empresa '.$label,
            'legal_address'=>'Transaccion HTTP rollback',
            'is_active'=>true,
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);
    }
    $suffix = bin2hex(random_bytes(6));
    foreach ([
        [$userAdmin,'ADMIN'],
        [$userAccountant,'ACCOUNTANT'],
        [$userOperator,'DATA_ENTRY'],
    ] as [$userId,$label]) {
        $db->table('users')->insert([
            'id'=>$userId,
            'name'=>'F1.9 TEMP '.$label,
            'email'=>'f19_'.strtolower($label).'_'.$suffix.'@example.test',
            'password'=>'unused-in-transient-test',
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);
    }

    $memberRows = [
        [$companyA,$userAdmin,'ADMIN'],
        [$companyB,$userAdmin,'AUDITOR'],
        [$companyB,$userAccountant,'ACCOUNTANT'],
        [$companyB,$userOperator,'DATA_ENTRY'],
    ];
    foreach ($memberRows as $i => [$companyId,$userId,$roleName]) {
        $db->selectOne("SELECT set_config('app.current_company_id', ?, true) AS val",[(string)$companyId]);
        $db->table('company_user')->insert([
            'id'=>$memberBase+$i,
            'company_id'=>$companyId,
            'user_id'=>$userId,
            'role'=>$roleName,
            'is_active'=>true,
            'created_at'=>now(),
            'updated_at'=>now(),
        ]);
    }
    $db->table('users')->where('id',$userAdmin)->update(['current_company_id'=>$companyA]);
    $db->table('users')->whereIn('id',[$userAccountant,$userOperator])->update(['current_company_id'=>$companyB]);
    $db->selectOne("SELECT set_config('app.current_company_id', '', true) AS val");

    $adminUser = \App\Models\User::query()->findOrFail($userAdmin);
    $accountantUser = \App\Models\User::query()->findOrFail($userAccountant);
    $operatorUser = \App\Models\User::query()->findOrFail($userOperator);

    /** @var callable(string,string,?int,?\App\Models\User):int $request */
    $request = static function (string $method, string $uri, ?int $tenant, ?\App\Models\User $actor) use ($kernel): int {
        if ($actor === null) {
            auth()->forgetGuards();
        } else {
            \Laravel\Sanctum\Sanctum::actingAs($actor, ['*'], 'sanctum');
        }
        $server = [
            'HTTP_ACCEPT'=>'application/json',
            'CONTENT_TYPE'=>'application/json',
        ];
        if ($tenant !== null) {
            $server['HTTP_X_COMPANY_ID'] = (string) $tenant;
        }
        $http = \Illuminate\Http\Request::create($uri,$method,[],[],[],$server,'{}');
        $response = $kernel->handle($http);
        return $response->getStatusCode();
    };
    $check = static function (string $name, int $expected, int $actual) use (&$assertions): void {
        if ($actual !== $expected) {
            // Unicamente estado HTTP: no mostrar respuesta ni datos privados.
            throw new \RuntimeException($name.' retorno HTTP '.$actual.' en vez de '.$expected);
        }
        echo '[PASS] HTTP '.$actual.' '.$name.PHP_EOL;
        $assertions++;
    };

    // Comenzar sin usuario autenticado para verificar auth:sanctum.
    $check('Anónimo no puede crear asiento',401,$request('POST','/api/v1/accounting/journal-entries',null,null));
    $check('Administrador A autorizado llega a validacion',422,$request('POST','/api/v1/accounting/accounts',$companyA,$adminUser));
    $check('Auditor B no puede escribir asientos',403,$request('POST','/api/v1/accounting/journal-entries',$companyB,$adminUser));
    $check('Auditor B sí puede listar asientos',200,$request('GET','/api/v1/accounting/journal-entries?limit=1',$companyB,$adminUser));
    $check('Contador B autorizado llega a validacion',422,$request('POST','/api/v1/accounting/journal-entries',$companyB,$accountantUser));
    $check('Contador B no administra plan de cuentas',403,$request('POST','/api/v1/accounting/accounts',$companyB,$accountantUser));
    $check('Contador B no obtiene acceso A',403,$request('POST','/api/v1/accounting/journal-entries',$companyA,$accountantUser));
    $check('Digitador B no publica asientos',403,$request('POST','/api/v1/accounting/journal-entries',$companyB,$operatorUser));

    if ($db->transactionLevel() !== 1) {
        throw new \RuntimeException('La transaccion global fue cerrada inesperadamente por alguna peticion HTTP.');
    }
    $passed = true;
} catch (\Throwable $error) {
    // No imprimir errores Laravel/PDO o contenido HTTP; pueden contener datos locales.
    fwrite(STDERR, '[FAIL] Integracion HTTP Neon no aprobada; ejecutar rollback y revisar con Docker/logs privados.'.PHP_EOL);
} finally {
    if ($begun) {
        try {
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            $rolledBack = true;
        } catch (\Throwable $error) {
            fwrite(STDERR, '[FAIL] No se pudo confirmar ROLLBACK Laravel; detener pruebas remotas.'.PHP_EOL);
            $passed = false;
        }
    }
    auth()->forgetGuards();
}
if ($rolledBack) {
    try {
        $companiesLeft = (int) ($db->table('companies')->whereIn('id',$ids)->count());
        $usersLeft = (int) ($db->table('users')->whereIn('id',$userIds)->count());
        if ($companiesLeft !== 0 || $usersLeft !== 0) {
            fwrite(STDERR, '[FAIL] Quedaron empresas o usuarios ficticios después de ROLLBACK.'.PHP_EOL);
            $passed = false;
        } else {
            echo '[PASS] ROLLBACK confirmado: 0 empresas y 0 usuarios ficticios persistidos.'.PHP_EOL;
        }
    } catch (\Throwable $error) {
        fwrite(STDERR, '[FAIL] No se pudo comprobar la limpieza posprueba.'.PHP_EOL);
        $passed = false;
    }
}
$db->disconnect();
if (! $passed || ! $rolledBack || $assertions !== 8) {
    exit(1);
}
echo '[PASS] 8 escenarios HTTP Laravel + Sanctum simulado sobre Neon dev, sin datos persistentes.'.PHP_EOL;
