<?php

declare(strict_types=1);

/**
 * F1.11: POST de asiento balanceado, trigger de partida doble e inmutabilidad.
 * Solo Neon contabilidad-dev PG18, con transaccion global + ROLLBACK.
 * --plan no abre SQL. --probe=contabilidad-dev requiere opcion explicita.
 */
$backend = dirname(__DIR__);
$endpoint = 'ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$mode = $argv[1] ?? '--plan';
if ($argc > 2 || ! in_array($mode, ['--plan','--probe=contabilidad-dev'], true)) {
    fwrite(STDERR, "[FAIL] Uso: php scripts/phase1-neon-journal-rollback.php --plan|--probe=contabilidad-dev\n");
    exit(2);
}
if ($mode === '--plan') {
    echo "[PLAN] Comprobar rol app_backend, Neon contabilidad-dev PG18 y tablas de negocio vacias.\n";
    echo "[PLAN] Crear empresa, admin, periodo y dos cuentas nivel 4 con jerarquia PCGE.\n";
    echo "[PLAN] HTTP 201 balanceado; 2 partidas; SET CONSTRAINTS ALL IMMEDIATE para trigger diferido.\n";
    echo "[PLAN] HTTP 422 descuadrado, UPDATE SQL inmutable rechazado y auditoria por empresa.\n";
    echo "[PLAN] ROLLBACK y SELECT de limpieza de todos los datos ficticios.\n";
    echo "[AVISO] Secuencias journal_entries/audit_logs pueden avanzar pese al ROLLBACK.\n";
    echo "[DRY-RUN] Ninguna conexion o escritura.\n";
    exit(0);
}
if (! is_file($backend.'/.env') || is_file($backend.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "[FAIL] .env ausente o config cacheada.\n");
    exit(1);
}
$seen = [];
$duplicates = [];
$lines = file($backend.'/.env', FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "[FAIL] No se pudo leer .env.\n");
    exit(1);
}
foreach ($lines as $line) {
    if (! preg_match('/^[ \t]*(?:export[ \t]+)?([A-Z][A-Z0-9_]*)[ \t]*=/', $line, $m)) {
        continue;
    }
    $key = $m[1];
    if (! str_starts_with($key, 'DB_') && ! str_starts_with($key, 'NEON_')
        && ! in_array($key, ['APP_ENV','APP_KEY','DATABASE_URL'], true)) {
        continue;
    }
    if (isset($seen[$key])) { $duplicates[] = $key; }
    $seen[$key] = true;
}
unset($lines);
if ($duplicates !== []) {
    fwrite(STDERR,'[FAIL] Variables .env duplicadas: '.implode(', ',array_unique($duplicates)).PHP_EOL);
    exit(1);
}
require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$traffic = (array) config('database.connections.pgsql', []);
$admin = (array) config('database.connections.pgsql_migrations', []);
$appPassword = (string) ($traffic['password'] ?? '');
$rolePassword = (string) env('DB_APP_BACKEND_ROLE_PASSWORD', '');
$appKey = (string) env('APP_KEY', '');
$keyBytes = str_starts_with($appKey, 'base64:')
    ? base64_decode(substr($appKey, 7), true) : $appKey;
if (! $app->environment('local')
    || config('database.default') !== 'pgsql'
    || env('NEON_TEST_BRANCH_NAME', '') !== 'contabilidad-dev'
    || env('NEON_TEST_BRANCH_HOST', '') !== $endpoint
    || (string) ($traffic['host'] ?? '') !== $endpoint
    || (string) ($admin['host'] ?? '') !== $endpoint
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
    || $keyBytes === false || strlen($keyBytes) !== 32
    || strlen($rolePassword) < 20 || $appPassword === ''
    || ! hash_equals($rolePassword, $appPassword)) {
    fwrite(STDERR, "[FAIL] Guardas de entorno: solo Neon PG18 dev con app_backend y SSL.\n");
    exit(1);
}
unset($rolePassword,$appPassword,$keyBytes,$appKey);

$db = \Illuminate\Support\Facades\DB::connection('pgsql');
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$companyId = random_int(4000000000000,4499999999999);
$userId = random_int(6000000000000,6499999999999);
$memberId = random_int(8000000000000,8499999999999);
$periodId = random_int(5000000000000,5499999999999);
$accountBase = random_int(7000000000000,7099999999999);
$entryId = null;
$begun = false;
$rolledBack = false;
$passed = false;
$checks = 0;
try {
    $db->beginTransaction();
    $begun = true;
    $db->selectOne("SELECT set_config('statement_timeout','15000',true) AS value");
    $identity = $db->selectOne(<<<'SQL'
        SELECT current_user AS actor, current_database() AS database_name,
               current_setting('server_version_num')::integer AS version_num,
               (SELECT count(*) FROM public.companies)::integer AS companies,
               (SELECT count(*) FROM public.users)::integer AS users,
               (SELECT count(*) FROM public.journal_entries)::integer AS journals,
               (SELECT count(*) FROM pg_catalog.pg_roles
                WHERE rolname=current_user AND rolcanlogin AND NOT rolsuper
                  AND NOT rolbypassrls AND NOT rolcreaterole AND NOT rolcreatedb)::integer AS restricted
        SQL);
    if (($identity->actor ?? '') !== 'app_backend'
        || ($identity->database_name ?? '') !== 'neondb'
        || intdiv((int) ($identity->version_num ?? 0), 10000) !== 18
        || (int) ($identity->companies ?? -1) !== 0
        || (int) ($identity->users ?? -1) !== 0
        || (int) ($identity->journals ?? -1) !== 0
        || (int) ($identity->restricted ?? 0) !== 1) {
        throw new \RuntimeException('Destino o estado inicial inesperado.');
    }
    echo "[PASS] app_backend en Neon dev PG18 sin datos de negocio previos.\n";
    $db->table('companies')->insert([
        'id'=>$companyId,'tax_id'=>'20'.(string)random_int(100000000,999999999),
        'business_name'=>'F1.11 TEMP','legal_address'=>'Solo rollback',
        'is_active'=>true,'created_at'=>now(),'updated_at'=>now(),
    ]);
    $db->table('users')->insert([
        'id'=>$userId,'name'=>'F1.11 TEMP Admin',
        'email'=>'f111_'.bin2hex(random_bytes(8)).'@example.test',
        'password'=>'unused-in-test','created_at'=>now(),'updated_at'=>now(),
    ]);
    $db->selectOne("SELECT set_config('app.current_company_id',?,true) AS value", [(string)$companyId]);
    $db->table('company_user')->insert([
        'id'=>$memberId,'company_id'=>$companyId,'user_id'=>$userId,
        'role'=>'ADMIN','is_active'=>true,'created_at'=>now(),'updated_at'=>now(),
    ]);
    $db->table('users')->where('id',$userId)->update(['current_company_id'=>$companyId]);
    $db->table('fiscal_periods')->insert([
        'id'=>$periodId,'company_id'=>$companyId,'year'=>2026,'month'=>10,
        'start_date'=>'2026-10-01','end_date'=>'2026-10-31',
        'status'=>'OPEN','created_at'=>now(),'updated_at'=>now(),
    ]);
    $accountIds = [];
    foreach ([
        [['1','10','101','1011'],'ASSET','DEBIT'],
        [['4','40','401','4011'],'LIABILITY','CREDIT'],
    ] as $familyIndex => [$codes,$type,$balance]) {
        $parentId = null;
        foreach ($codes as $index => $code) {
            $id = $accountBase + $familyIndex*4 + $index;
            $db->table('accounting_accounts')->insert([
                'id'=>$id,'company_id'=>$companyId,'parent_id'=>$parentId,
                'code'=>$code,'name'=>'F1.11 TEMP '.$code,
                'level'=>$index+1,'path'=>$code,
                'account_type'=>$type,'balance_type'=>$balance,
                'is_operational'=>$index===3,'is_active'=>true,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            if ($index === 3) { $accountIds[] = $id; }
            $parentId = $id;
        }
    }
    if (count($accountIds) !== 2) {
        throw new \RuntimeException('Cuentas operativas incompletas.');
    }
    echo "[PASS] Fixtures PCGE: periodo OPEN y dos cuentas operativas nivel 4.\n";
    $db->selectOne("SELECT set_config('app.current_company_id','',true) AS value");
    $actor = \App\Models\User::query()->findOrFail($userId);
    \Laravel\Sanctum\Sanctum::actingAs($actor,['*'],'sanctum');
    $post = static function (array $payload) use ($kernel,$companyId): \Symfony\Component\HttpFoundation\Response {
        $http = \Illuminate\Http\Request::create(
            '/api/v1/accounting/journal-entries','POST',[],[],[],[
                'HTTP_ACCEPT'=>'application/json',
                'CONTENT_TYPE'=>'application/json',
                'HTTP_X_COMPANY_ID'=>(string)$companyId,
            ],json_encode($payload,JSON_THROW_ON_ERROR)
        );
        return $kernel->handle($http);
    };
    $payload = [
        'entry_date'=>'2026-10-21','entry_number'=>'F111-POST',
        'entry_type'=>'STANDARD','currency'=>'PEN',
        'description'=>'F1.11 balanceado rollback',
        'lines'=>[
            ['accounting_account_id'=>$accountIds[0],
             'debit_local'=>'120.0000','credit_local'=>'0.0000',
             'debit_foreign'=>'120.0000','credit_foreign'=>'0.0000'],
            ['accounting_account_id'=>$accountIds[1],
             'debit_local'=>'0.0000','credit_local'=>'120.0000',
             'debit_foreign'=>'0.0000','credit_foreign'=>'120.0000'],
        ],
    ];
    $response = $post($payload);
    if ($response->getStatusCode() !== 201) {
        throw new \RuntimeException('POST contable no retorno HTTP 201.');
    }
    $created = json_decode((string)$response->getContent(),true,512,JSON_THROW_ON_ERROR);
    $entryId = (int)($created['data']['id'] ?? 0);
    if ($entryId <= 0) { throw new \RuntimeException('Respuesta sin ID de asiento.'); }
    echo "[PASS] HTTP 201 asiento balanceado publicado.\n";
    $checks++;
    // El middleware REST restaura el GUC tenant al salir del request.
    // Leer partidas y forzar triggers requiere el contexto A explícito
    // dentro de esta transacción de prueba, nunca en sesión global.
    $db->selectOne("SELECT set_config('app.current_company_id',?,true) AS value", [(string)$companyId]);
    $totals = $db->selectOne(<<<'SQL'
        SELECT count(*)::integer AS n,
          COALESCE(sum(debit_local),0)::numeric(14,4) AS debit_local,
          COALESCE(sum(credit_local),0)::numeric(14,4) AS credit_local,
          COALESCE(sum(debit_foreign),0)::numeric(14,4) AS debit_foreign,
          COALESCE(sum(credit_foreign),0)::numeric(14,4) AS credit_foreign
        FROM public.journal_entry_lines
        WHERE company_id=? AND journal_entry_id=?
        SQL,[$companyId,$entryId]);
    if ((int)($totals->n ?? 0) !== 2
        || (string)($totals->debit_local ?? '') !== '120.0000'
        || (string)($totals->credit_local ?? '') !== '120.0000'
        || (string)($totals->debit_foreign ?? '') !== '120.0000'
        || (string)($totals->credit_foreign ?? '') !== '120.0000') {
        throw new \RuntimeException('Partidas no cuadran exactamente.');
    }
    echo "[PASS] Debe/Haber exactos en moneda local y extranjera.\n";
    $checks++;
    // ROLLBACK no dispara triggers diferidos; activarlos explicitamente.
    $db->statement('SET CONSTRAINTS ALL IMMEDIATE');
    echo "[PASS] PostgreSQL ejecuto y aprobo los triggers diferidos.\n";
    $checks++;
    $db->selectOne("SELECT set_config('app.current_company_id','',true) AS value");
    $bad = $payload;
    $bad['entry_number'] = 'F111-INVALID';
    $bad['lines'][1]['credit_local'] = '119.0000';
    if ($post($bad)->getStatusCode() !== 422) {
        throw new \RuntimeException('El controlador no rechazo el asiento descuadrado.');
    }
    // SetTenantContext ya restauro el GUC a blanco; no contar bajo RLS vacio.
    $db->selectOne("SELECT set_config('app.current_company_id',?,true) AS value", [(string)$companyId]);
    if ((int)$db->table('journal_entries')->where('company_id',$companyId)->count() !== 1) {
        throw new \RuntimeException('El motor creo un asiento invalido.');
    }
    echo "[PASS] HTTP 422: partida descuadrada rechazada sin nuevo asiento.\n";
    $checks++;
    // SAVEPOINT para capturar el trigger de inmutabilidad (23514).
    $db->statement('SAVEPOINT f111_immutable');
    $blocked = false;
    try {
        $db->update('UPDATE public.journal_entries SET description=? WHERE id=?',
            ['MUTACION PROHIBIDA',$entryId]);
    } catch (\Throwable $error) {
        $state = (string)($error->errorInfo[0] ?? $error->getCode());
        $blocked = $state === '23514';
    } finally {
        $db->statement('ROLLBACK TO SAVEPOINT f111_immutable');
        $db->statement('RELEASE SAVEPOINT f111_immutable');
    }
    if (! $blocked) { throw new \RuntimeException('Fallo inmutabilidad SQL.'); }
    echo "[PASS] UPDATE de asiento POSTED bloqueado por trigger 23514.\n";
    $checks++;
    $audit = $db->selectOne(<<<'SQL'
        SELECT count(*)::integer AS n FROM public.audit_logs
        WHERE company_id=? AND event_type='JOURNAL_ENTRY_CREATED'
        SQL,[$companyId]);
    if ((int)($audit->n ?? 0) !== 1) {
        throw new \RuntimeException('Falta auditoria de registro.');
    }
    echo "[PASS] Auditoria JOURNAL_ENTRY_CREATED bajo el tenant.\n";
    $checks++;
    if ($db->transactionLevel() !== 1 || $checks !== 6) {
        throw new \RuntimeException('Profundidad de transaccion o numero de checks incorrecto.');
    }
    $passed = true;
} catch (\Throwable $error) {
    // Nunca mostrar SQL bruto ni payloads.
    fwrite(STDERR,"[FAIL] F1.11 incompleta: revisar en Docker y registrar etapa fallida.\n");
} finally {
    if ($begun) {
        try {
            while ($db->transactionLevel() > 0) { $db->rollBack(); }
            $rolledBack = true;
        } catch (\Throwable $error) {
            fwrite(STDERR,"[FAIL] ROLLBACK no confirmado; detener pruebas.\n");
            $passed = false;
        }
    }
    auth()->forgetGuards();
}
if ($rolledBack) {
    try {
        $left = $db->selectOne(<<<'SQL'
            SELECT (SELECT count(*) FROM public.companies WHERE id=?)::integer AS companies,
                   (SELECT count(*) FROM public.users WHERE id=?)::integer AS users,
                   (SELECT count(*) FROM public.journal_entries WHERE id=?)::integer AS entries,
                   (SELECT count(*) FROM public.accounting_accounts WHERE company_id=?)::integer AS accounts,
                   (SELECT count(*) FROM public.audit_logs WHERE company_id=?)::integer AS audits
            SQL,[$companyId,$userId,$entryId ?? 0,$companyId,$companyId]);
        if ((int)$left->companies !== 0 || (int)$left->users !== 0
            || (int)$left->entries !== 0 || (int)$left->accounts !== 0
            || (int)$left->audits !== 0) {
            throw new \RuntimeException('Filas de prueba persistidas.');
        }
        echo "[PASS] ROLLBACK confirmado: sin empresa, usuario, asiento, cuentas ni auditoria.\n";
    } catch (\Throwable $error) {
        $passed = false;
        fwrite(STDERR,"[FAIL] Verificacion posterior a ROLLBACK no aprobada.\n");
    }
}
$db->disconnect();
if (! $passed || ! $rolledBack || $checks !== 6) { exit(1); }
echo "[PASS] F1.11: motor POST y triggers SQL validados sin filas persistentes.\n";
