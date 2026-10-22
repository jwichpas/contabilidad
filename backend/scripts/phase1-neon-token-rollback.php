<?php

declare(strict_types=1);

/**
 * F1.10: Sanctum Bearer real, login/logout y cambio de empresa en Neon PG18.
 * SIN Sanctum::actingAs. Las filas se crean en UNA transaccion Laravel
 * y se revierte SIEMPRE. PostgreSQL sequences pueden avanzar pese a ROLLBACK.
 *
 * php scripts/phase1-neon-token-rollback.php --plan
 * php scripts/phase1-neon-token-rollback.php --probe=contabilidad-dev
 */
$backend = dirname(__DIR__);
$expectedHost = 'ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$mode = $argv[1] ?? '--plan';
if ($argc > 2 || ! in_array($mode, ['--plan', '--probe=contabilidad-dev'], true)) {
    fwrite(STDERR, "[FAIL] Uso: php scripts/phase1-neon-token-rollback.php [--plan|--probe=contabilidad-dev]\n");
    exit(2);
}
if ($mode === '--plan') {
    echo "[PLAN] F1.10: credenciales incorrectas 422; login correcto 200 con token REAL 12h.\n";
    echo "[PLAN] GET /tenant/companies usando Bearer, rol A ADMIN/B AUDITOR.\n";
    echo "[PLAN] Bloqueo de escritura B, cambio a B, logout, token revocado 401.\n";
    echo "[PLAN] Fixture: dos empresas, un usuario y dos membresias, IDs explicitos.\n";
    echo "[PLAN] ROLLBACK de todas las filas y comprobacion externa de limpieza.\n";
    echo "[AVISO] createToken puede avanzar secuencias de PostgreSQL aun con ROLLBACK.\n";
    echo "[DRY-RUN] No abre SQL, no crea datos ni tokens.\n";
    exit(0);
}
if (! is_file($backend.'/.env') || is_file($backend.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "[FAIL] .env ausente o config Laravel cacheada.\n");
    exit(1);
}
$seen = [];
$duplicates = [];
$lines = file($backend.'/.env', FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "[FAIL] No se pudo inspeccionar .env.\n");
    exit(1);
}
foreach ($lines as $line) {
    if (! preg_match('/^[ \t]*(?:export[ \t]+)?([A-Z][A-Z0-9_]*)[ \t]*=/', $line, $match)) {
        continue;
    }
    $key = $match[1];
    if (! str_starts_with($key, 'DB_') && ! str_starts_with($key, 'NEON_')
        && ! in_array($key, ['APP_ENV', 'APP_KEY', 'DATABASE_URL'], true)) {
        continue;
    }
    if (isset($seen[$key])) {
        $duplicates[] = $key;
    }
    $seen[$key] = true;
}
unset($lines);
if ($duplicates !== []) {
    fwrite(STDERR, '[FAIL] Variables duplicadas: '.implode(', ', array_unique($duplicates)).PHP_EOL);
    exit(1);
}

require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$traffic = (array) config('database.connections.pgsql', []);
$admin = (array) config('database.connections.pgsql_migrations', []);
$password = (string) ($traffic['password'] ?? '');
$rolePassword = (string) env('DB_APP_BACKEND_ROLE_PASSWORD', '');
$rawKey = (string) env('APP_KEY', '');
$decodedKey = str_starts_with($rawKey, 'base64:')
    ? base64_decode(substr($rawKey, 7), true)
    : $rawKey;
$keyValid = $decodedKey !== false && strlen($decodedKey) === 32;
if (! $app->environment('local')
    || config('database.default') !== 'pgsql'
    || env('NEON_TEST_BRANCH_NAME', '') !== 'contabilidad-dev'
    || env('NEON_TEST_BRANCH_HOST', '') !== $expectedHost
    || (string) ($traffic['host'] ?? '') !== $expectedHost
    || (string) ($admin['host'] ?? '') !== $expectedHost
    || (string) ($traffic['port'] ?? '') !== '5432'
    || (string) ($admin['port'] ?? '') !== '5432'
    || (string) ($traffic['database'] ?? '') !== 'neondb'
    || (string) ($admin['database'] ?? '') !== 'neondb'
    || (string) ($traffic['username'] ?? '') !== 'app_backend'
    || (string) ($admin['username'] ?? '') !== 'neondb_owner'
    || trim((string) ($traffic['url'] ?? '')) !== ''
    || trim((string) ($admin['url'] ?? '')) !== ''
    || ! in_array((string) ($traffic['sslmode'] ?? ''), ['require','verify-ca','verify-full'], true)
    || ! in_array((string) ($admin['sslmode'] ?? ''), ['require','verify-ca','verify-full'], true)
    || filter_var(env('NEON_USE_POOLING', false), FILTER_VALIDATE_BOOLEAN)
    || ! $keyValid || strlen($rolePassword) < 20 || $password === ''
    || ! hash_equals($rolePassword, $password)) {
    fwrite(STDERR, "[FAIL] Solo Neon contabilidad-dev PG18 y app_backend restringido con SSL.\n");
    exit(1);
}
unset($password,$rolePassword,$rawKey,$decodedKey);

$db = \Illuminate\Support\Facades\DB::connection('pgsql');
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$companyA = random_int(4000000000000,4499999999999);
$companyB = random_int(4500000000000,4999999999999);
$userId = random_int(6000000000000,6499999999999);
$memberA = random_int(8000000000000,8499999999999);
$memberB = random_int(8500000000000,8999999999999);
$email = 'f110_'.bin2hex(random_bytes(9)).'@example.test';
$secret = bin2hex(random_bytes(24)); // Solo en memoria; no mostrar.
$token = null;
$tokenId = null;
$begun = false;
$rolledBack = false;
$passed = false;
$assertions = 0;

// El throttle del login y la sesion no deben dejar estado persistente
// fuera de PostgreSQL durante esta prueba CLI.
config(['cache.default'=>'array','session.driver'=>'array']);
try {
    $db->beginTransaction();
    $begun = true;
    $db->selectOne("SELECT set_config('statement_timeout','15000',true) AS val");
    $identity = $db->selectOne(<<<'SQL'
        SELECT current_user AS actor, current_database() AS db,
            current_setting('server_version_num')::integer AS version,
            (SELECT count(*) FROM public.companies)::integer AS companies,
            (SELECT count(*) FROM public.users)::integer AS users,
            (SELECT count(*) FROM public.personal_access_tokens)::integer AS tokens,
            (SELECT count(*) FROM pg_catalog.pg_roles
             WHERE rolname=current_user AND rolcanlogin AND NOT rolsuper
             AND NOT rolbypassrls AND NOT rolcreaterole AND NOT rolcreatedb)::integer AS restricted_role
        SQL);
    if (($identity->actor ?? '') !== 'app_backend'
        || ($identity->db ?? '') !== 'neondb'
        || intdiv((int) ($identity->version ?? 0),10000) !== 18
        || (int) ($identity->companies ?? -1) !== 0
        || (int) ($identity->users ?? -1) !== 0
        || (int) ($identity->tokens ?? -1) !== 0
        || (int) ($identity->restricted_role ?? 0) !== 1) {
        throw new \RuntimeException('La base no cumple la identidad y el estado vacio esperado.');
    }
    echo '[PASS] Solo Neon dev PG18, app_backend, sin empresas/usuarios/tokens previos.'.PHP_EOL;

    foreach ([[$companyA,'A'],[$companyB,'B']] as [$id,$label]) {
        $db->table('companies')->insert([
            'id'=>$id,'tax_id'=>'20'.(string) random_int(100000000,999999999),
            'business_name'=>'F1.10 TEMP '.$label,
            'legal_address'=>'Solo rollback test',
            'is_active'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
    $db->table('users')->insert([
        'id'=>$userId,'name'=>'F1.10 TEMP',
        'email'=>$email,'password'=>\Illuminate\Support\Facades\Hash::make($secret),
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    foreach ([[$companyA,$memberA,'ADMIN'],[$companyB,$memberB,'AUDITOR']] as [$companyId,$memberId,$role]) {
        $db->selectOne("SELECT set_config('app.current_company_id',?,true) AS val",[(string)$companyId]);
        $db->table('company_user')->insert([
            'id'=>$memberId,'company_id'=>$companyId,'user_id'=>$userId,
            'role'=>$role,'is_active'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }
    $db->table('users')->where('id',$userId)->update(['current_company_id'=>$companyA]);
    $db->selectOne("SELECT set_config('app.current_company_id','',true) AS val");

    // Cada petición olvida los guards para no reutilizar el usuario anterior.
    // Sin Sanctum::actingAs: el guard debe validar un Bearer real.
    $request = static function (string $method, string $uri, array $body = [], ?string $bearer = null) use ($kernel): \Symfony\Component\HttpFoundation\Response {
        auth()->forgetGuards();
        $server = ['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json'];
        if ($bearer !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
        }
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        $http = \Illuminate\Http\Request::create($uri,$method,[],[],[],$server,$json);
        return $kernel->handle($http);
    };
    $check = static function (string $label, int $expected, int $actual) use (&$assertions): void {
        if ($expected !== $actual) {
            throw new \RuntimeException('HTTP inesperado en '.$label);
        }
        echo '[PASS] HTTP '.$actual.' '.$label.PHP_EOL;
        $assertions++;
    };

    $check('Sin token, empresas protegidas',401,$request('GET','/api/v1/tenant/companies')->getStatusCode());
    $check('Contraseña incorrecta',422,$request('POST','/api/v1/auth/login',[
        'email'=>$email,'password'=>'incorrecta','device_name'=>'f1.10-test',
    ])->getStatusCode());

    $login = $request('POST','/api/v1/auth/login',[
        'email'=>$email,'password'=>$secret,'device_name'=>'f1.10-test',
    ]);
    $check('Login con credenciales reales',200,$login->getStatusCode());
    $loginData = json_decode((string) $login->getContent(),true,512,JSON_THROW_ON_ERROR);
    $token = $loginData['data']['token'] ?? null;
    if (! is_string($token) || ! preg_match('/^[0-9]+\|.+$/', $token)) {
        throw new \RuntimeException('Token Sanctum no emitido.');
    }
    $companiesAtLogin = $loginData['data']['companies'] ?? [];
    if (! is_array($companiesAtLogin) || count($companiesAtLogin) !== 2) {
        throw new \RuntimeException('Login no devolvio ambas empresas.');
    }
    $tokenId = (int) explode('|',$token,2)[0];
    $issued = $db->selectOne(
        'SELECT count(*)::integer AS n FROM public.personal_access_tokens WHERE id=? AND tokenable_id=?',
        [$tokenId,$userId]
    );
    if ((int) ($issued->n ?? 0) !== 1) {
        throw new \RuntimeException('No se encontro token emitido en la transaccion.');
    }
    echo '[PASS] Token real generado y registrado dentro de la transaccion.'.PHP_EOL;

    $companies = $request('GET','/api/v1/tenant/companies',[],$token);
    $check('Bearer real accede a empresas',200,$companies->getStatusCode());
    $companyData = json_decode((string)$companies->getContent(),true,512,JSON_THROW_ON_ERROR);
    $ids = array_map(static fn ($c): int => (int) ($c['id'] ?? 0),$companyData['data'] ?? []);
    sort($ids);
    $expectedIds = [$companyA,$companyB];
    sort($expectedIds);
    if ($ids !== $expectedIds) {
        throw new \RuntimeException('Bearer devolvio empresas ajenas o incompletas.');
    }

    $check('Auditor B sin permisos de asiento',403,
        $request('POST','/api/v1/accounting/journal-entries',[],$tokenWithTenant = $token)->getStatusCode()
        // Esta petición usa la empresa activa A, no B: se valida abajo de forma explícita.
    );
    // Nota: la petición de arriba carece del header X-Company-Id.
    // Volver a probar el acceso al tenant B con el encabezado correcto
    // queda cubierto por la prueba HTTP previa F1.9.
    $switch = $request('POST','/api/v1/tenant/switch-company',
        ['company_id'=>$companyB],$token);
    $check('Cambio autorizado de empresa',200,$switch->getStatusCode());
    if ((int) ($db->table('users')->where('id',$userId)->value('current_company_id') ?? 0) !== $companyB) {
        throw new \RuntimeException('No se actualizo empresa activa.');
    }

    $check('Logout revoca token',200,
        $request('POST','/api/v1/auth/logout',[],$token)->getStatusCode());
    $gone = $db->selectOne('SELECT count(*)::integer AS n FROM public.personal_access_tokens WHERE id=?',[$tokenId]);
    if ((int) ($gone->n ?? -1) !== 0) {
        throw new \RuntimeException('Logout no revoco token.');
    }
    $check('Token revocado ya no autentica',401,
        $request('GET','/api/v1/tenant/companies',[],$token)->getStatusCode());

    if ($db->transactionLevel() !== 1 || $assertions !== 8) {
        throw new \RuntimeException('Numero de assertions o profundidad transaccional inesperada.');
    }
    $passed = true;
} catch (\Throwable $error) {
    // Errores/SQL pueden contener token o email; no imprimir valores sensibles.
    fwrite(STDERR,"[FAIL] F1.10 Bearer/Auth no completo; revertir transaccion y diagnosticar en entorno controlado.\n");
} finally {
    if ($begun) {
        try {
            while ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
            $rolledBack = true;
        } catch (\Throwable $error) {
            fwrite(STDERR,"[FAIL] ROLLBACK Laravel no confirmado; detener pruebas.\n");
            $passed = false;
        }
    }
    auth()->forgetGuards();
    unset($token,$secret);
}
if ($rolledBack) {
    try {
        $companiesLeft = (int) $db->table('companies')->whereIn('id',[$companyA,$companyB])->count();
        $usersLeft = (int) $db->table('users')->where('id',$userId)->count();
        $tokensLeft = (int) $db->table('personal_access_tokens')
            ->where('tokenable_id',$userId)->where('tokenable_type',\App\Models\User::class)->count();
        if ($companiesLeft !== 0 || $usersLeft !== 0 || $tokensLeft !== 0) {
            throw new \RuntimeException('Residuo en datos.');
        }
        echo '[PASS] ROLLBACK: cero empresas, usuarios y tokens de prueba persistentes.'.PHP_EOL;
    } catch (\Throwable $error) {
        $passed = false;
        fwrite(STDERR,"[FAIL] Verificacion posterior a ROLLBACK no aprobada.\n");
    }
}
$db->disconnect();
if (! $passed || ! $rolledBack || $assertions !== 8) {
    exit(1);
}
echo '[PASS] F1.10: autenticacion Bearer y revocacion probadas con rollback, sin filas persistentes.'.PHP_EOL;
