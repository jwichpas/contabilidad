<?php

declare(strict_types=1);

/**
 * F1.14 — Concurrencia real de DOS conexiones PostgreSQL en Docker efimero.
 *
 * --plan NO conecta ni escribe.
 * --probe=ephemeral-docker requiere APP_ENV=testing, contabilidad_ci,
 * host loopback, rol app_backend y token aleatorio de guardia Docker.
 *
 * El runner crea fixtures COMMIT dentro de ese contenedor DESECHABLE
 * (no sobre Neon ni Supabase). El wrapper .ps1 destruye el contenedor
 * entero aun cuando el runner falle. No usar directamente en entorno real.
 */
$mode = $argv[1] ?? '--plan';
if ($argc > 2 || ! in_array($mode, ['--plan', '--probe=ephemeral-docker'], true)) {
    fwrite(STDERR, "[FAIL] Uso: php scripts/phase1-journal-concurrency-docker.php [--plan|--probe=ephemeral-docker]\n");
    exit(2);
}
if ($mode === '--plan') {
    echo "[PLAN] DOS conexiones app_backend a un PostgreSQL 18 Docker efimero.\n";
    echo "[PLAN] Empresa/periodo/usuario/cuentas creados en el contenedor descartable.\n";
    echo "[PLAN] A bloquea el periodo FOR UPDATE; B intenta NOWAIT y recibe 55P03.\n";
    echo "[PLAN] A emite 10-00001 y COMMIT; B obtiene lock y emite 10-00002.\n";
    echo "[PLAN] Verifica dos asientos balanceados y constraint UNIQUE de correlativos.\n";
    echo "[PLAN] La limpieza depende de la destruccion del contenedor Docker por el wrapper.\n";
    echo "[DRY-RUN] Ninguna conexion SQL.\n";
    exit(0);
}

$backend = dirname(__DIR__);
if (is_file($backend.'/bootstrap/cache/config.php')
    || getenv('APP_ENV') !== 'testing'
    || getenv('DB_HOST') !== '127.0.0.1'
    || getenv('DB_DATABASE') !== 'contabilidad_ci'
    || getenv('DB_USERNAME') !== 'app_backend'
    || getenv('DB_MIGRATIONS_HOST') !== '127.0.0.1'
    || getenv('DB_MIGRATIONS_DATABASE') !== 'contabilidad_ci'
    || getenv('DB_MIGRATIONS_USERNAME') !== 'postgres'
    || getenv('F1_GUARD_TOKEN') === false
    || strlen((string) getenv('F1_GUARD_TOKEN')) !== 32
    || ! ctype_xdigit((string) getenv('F1_GUARD_TOKEN'))) {
    fwrite(STDERR, "[BLOQUEO] F1.14 solamente en Docker efimero, APP_ENV=testing.\n");
    exit(1);
}

require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$cfg = (array) config('database.connections.pgsql', []);
$migrateCfg = (array) config('database.connections.pgsql_migrations', []);
$port = (string) getenv('DB_PORT');
if (! $app->environment('testing')
    || (string) config('database.default') !== 'pgsql'
    || (string) ($cfg['host'] ?? '') !== '127.0.0.1'
    || (string) ($cfg['port'] ?? '') !== $port
    || (string) ($cfg['database'] ?? '') !== 'contabilidad_ci'
    || (string) ($cfg['username'] ?? '') !== 'app_backend'
    || (string) ($migrateCfg['host'] ?? '') !== '127.0.0.1'
    || (string) ($migrateCfg['port'] ?? '') !== $port
    || (string) ($migrateCfg['database'] ?? '') !== 'contabilidad_ci'
    || (string) ($migrateCfg['username'] ?? '') !== 'postgres'
    || ! preg_match('/^[0-9]{2,5}$/', $port)
    || $port === '5432'
    || (string) ($cfg['sslmode'] ?? '') !== 'disable') {
    fwrite(STDERR, "[BLOQUEO] Config Laravel no apunta al PostgreSQL efimero loopback.\n");
    exit(1);
}

$dbA = \Illuminate\Support\Facades\DB::connection('pgsql');
$databaseManager = app('db');
$previousDefault = $databaseManager->getDefaultConnection();
$start = false;
$passed = false;
$dbB = null;
try {
    // Verificar que el contenedor tiene el token de guardia secreto de ese
    // proceso, no solo un host local controlable. Tabla creada por wrapper.
    $state = $dbA->selectOne(<<<'SQL'
        SELECT current_user AS role_name,current_database() AS db_name,
               current_setting('server_version_num')::integer AS version,
               (SELECT count(*) FROM public.f1_ci_runtime_guard
                WHERE token=?)::integer AS guard_count,
               (SELECT count(*) FROM public.companies)::integer AS companies,
               (SELECT count(*) FROM public.journal_entries)::integer AS journals,
               (SELECT count(*) FROM pg_catalog.pg_roles WHERE rolname=current_user
                AND NOT rolsuper AND NOT rolbypassrls)::integer AS restricted
        SQL,[(string) getenv('F1_GUARD_TOKEN')]);
    if ($state === null
        || $state->role_name !== 'app_backend'
        || $state->db_name !== 'contabilidad_ci'
        || intdiv((int) $state->version,10000) !== 18
        || (int) $state->guard_count !== 1
        || (int) $state->restricted !== 1
        || (int) $state->companies !== 0
        || (int) $state->journals !== 0) {
        throw new \RuntimeException('Identidad Docker o estado inicial inesperados.');
    }
    echo "[PASS] Solo PostgreSQL 18 efimero con guardia Docker y rol app_backend.\n";

    // Crear 2 conexiones fisicas distintas con identica identidad app_backend.
    config(['database.connections.pgsql_f114_b' => $cfg]);
    $dbB = \Illuminate\Support\Facades\DB::connection('pgsql_f114_b');
    if ((int) ($dbA->selectOne('SELECT pg_backend_pid() AS pid')->pid ?? -1)
        === (int) ($dbB->selectOne('SELECT pg_backend_pid() AS pid')->pid ?? -1)) {
        throw new \RuntimeException('Conexiones PostgreSQL no son fisicamente distintas.');
    }

    // Las pruebas realizadas anteriormente en este contenedor hicieron
    // ROLLBACK. Esta fixture COMMIT solo persiste hasta docker rm --force.
    $company = random_int(4000000000000,4499999999999);
    $user = random_int(6000000000000,6499999999999);
    $period = random_int(5000000000000,5499999999999);
    $accountBase = random_int(7000000000000,7099999999999);
    $accountIds = [];
    $entryBase = random_int(7500000000000,7599999999999);
    $lineBase = random_int(7600000000000,7699999999999);

    $dbA->beginTransaction();
    $start = true;
    $dbA->selectOne("SELECT set_config('app.current_company_id',?,true) AS val",[(string)$company]);
    $dbA->table('companies')->insert([
        'id'=>$company,'tax_id'=>'20'.(string)random_int(100000000,999999999),
        'business_name'=>'F1.14 SOLO DOCKER','legal_address'=>'Contenedor descartable',
        'is_active'=>true,'created_at'=>now(),'updated_at'=>now(),
    ]);
    $dbA->table('users')->insert([
        'id'=>$user,'name'=>'F1.14 SOLO DOCKER',
        'email'=>'f114_'.bin2hex(random_bytes(6)).'@example.test',
        'password'=>'unused','created_at'=>now(),'updated_at'=>now(),
    ]);
    $dbA->table('fiscal_periods')->insert([
        'id'=>$period,'company_id'=>$company,'year'=>2026,'month'=>10,
        'start_date'=>'2026-10-01','end_date'=>'2026-10-31','status'=>'OPEN',
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    foreach ([
        [['1','10','101','1011'],'ASSET','DEBIT'],
        [['4','40','401','4011'],'LIABILITY','CREDIT'],
    ] as $family => [$codes,$type,$balance]) {
        $parent = null;
        foreach ($codes as $level => $code) {
            $id = $accountBase + $family*4 + $level;
            $dbA->table('accounting_accounts')->insert([
                'id'=>$id,'company_id'=>$company,'parent_id'=>$parent,
                'code'=>$code,'name'=>'F1.14 '.$code,'path'=>$code,
                'level'=>$level+1,'account_type'=>$type,'balance_type'=>$balance,
                'is_operational'=>$level===3,'is_active'=>true,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            if ($level===3) { $accountIds[]=$id; }
            $parent=$id;
        }
    }
    if (count($accountIds) !== 2) {
        throw new \RuntimeException('Dos cuentas operativas no creadas.');
    }
    $dbA->commit();
    $start = false;

    // Cada conexión utiliza GUC por transacción, bajo RLS real.
    $dbA->beginTransaction();
    $start = true;
    $dbA->selectOne("SELECT set_config('app.current_company_id',?,true) AS val",[(string)$company]);
    $dbA->selectOne("SELECT set_config('lock_timeout','2500',true) AS val");
    $dbA->selectOne('SELECT id FROM public.fiscal_periods WHERE company_id=? AND id=? FOR UPDATE',[$company,$period]);

    $dbB->beginTransaction();
    $dbB->selectOne("SELECT set_config('app.current_company_id',?,true) AS val",[(string)$company]);
    $dbB->selectOne("SELECT set_config('lock_timeout','2500',true) AS val");
    // B no debe obtener el lock mientras A lo tiene.
    $dbB->beginTransaction(); // SAVEPOINT Laravel
    $deniedNowait = false;
    try {
        $dbB->selectOne('SELECT id FROM public.fiscal_periods WHERE company_id=? AND id=? FOR UPDATE NOWAIT',[$company,$period]);
    } catch (\Throwable $error) {
        $deniedNowait = (string)($error->errorInfo[0] ?? $error->getCode()) === '55P03';
    } finally {
        $dbB->rollBack(); // ROLLBACK TO SAVEPOINT; preserva B transacción.
    }
    if (! $deniedNowait) {
        throw new \RuntimeException('B pudo bloquear el periodo mientras A lo retenia.');
    }
    echo "[PASS] Concurrencia real: B recibe 55P03 mientras A retiene FOR UPDATE.\n";

    $numbers = new \App\Services\Accounting\JournalEntryNumberService();
    $insertBalanced = static function (
        \Illuminate\Database\Connection $conn, int $entryId, int $lineOffset, string $number
    ) use ($company,$period,$user,$accountIds,$lineBase): void {
        $conn->table('journal_entries')->insert([
            'id'=>$entryId,'company_id'=>$company,'fiscal_period_id'=>$period,
            'entry_number'=>$number,'entry_date'=>'2026-10-21',
            'entry_type'=>'STANDARD','status'=>'POSTED','currency'=>'PEN',
            'exchange_rate'=>'1.000000','exchange_rate_type'=>'SELL',
            'description'=>'F1.14 prueba concurrente solo Docker',
            'created_by_user_id'=>$user,'created_at'=>now(),'updated_at'=>now(),
        ]);
        foreach ([
            [$accountIds[0],'120.0000','0.0000','120.0000','0.0000'],
            [$accountIds[1],'0.0000','120.0000','0.0000','120.0000'],
        ] as $index => [$account,$debit,$credit,$foreignDebit,$foreignCredit]) {
            $conn->table('journal_entry_lines')->insert([
                'id'=>$lineBase+$lineOffset+$index,
                'company_id'=>$company,'journal_entry_id'=>$entryId,
                'line_number'=>$index+1,'accounting_account_id'=>$account,
                'debit_local'=>$debit,'credit_local'=>$credit,
                'debit_foreign'=>$foreignDebit,'credit_foreign'=>$foreignCredit,
                'exchange_rate'=>'1.000000',
                'created_at'=>now(),'updated_at'=>now(),
            ]);
        }
    };

    $databaseManager->setDefaultConnection('pgsql');
    $n1=$numbers->nextForPeriod($company,$period,10);
    if ($n1!=='10-00001') { throw new \RuntimeException('Numero inicial incorrecto.'); }
    $insertBalanced($dbA,$entryBase,0,$n1);
    $dbA->statement('SET CONSTRAINTS ALL IMMEDIATE');
    $dbA->commit();
    $start=false;

    // B obtiene el MISMO lock ya liberado y observa la fila COMMITTED
    // de A antes de calcular su número con el servicio real.
    $dbB->selectOne('SELECT id FROM public.fiscal_periods WHERE company_id=? AND id=? FOR UPDATE',[$company,$period]);
    $databaseManager->setDefaultConnection('pgsql_f114_b');
    $n2=$numbers->nextForPeriod($company,$period,10);
    if ($n2!=='10-00002') { throw new \RuntimeException('Correlativo duplicado tras liberar FOR UPDATE.'); }
    $insertBalanced($dbB,$entryBase+1,2,$n2);
    $dbB->statement('SET CONSTRAINTS ALL IMMEDIATE');
    $dbB->commit();

    $dbA->beginTransaction();
    $start=true;
    $dbA->selectOne("SELECT set_config('app.current_company_id',?,true) AS val",[(string)$company]);
    $result=$dbA->selectOne(<<<'SQL'
        SELECT count(*)::integer AS headers,
          count(DISTINCT entry_number)::integer AS numbers,
          min(entry_number) AS first_number,max(entry_number) AS last_number,
          (SELECT count(*) FROM public.journal_entry_lines
           WHERE company_id=?)::integer AS lines
        FROM public.journal_entries WHERE company_id=? AND fiscal_period_id=?
        SQL,[$company,$company,$period]);
    if ((int)($result->headers ?? 0)!==2
        || (int)($result->numbers ?? 0)!==2
        || (int)($result->lines ?? 0)!==4
        || ($result->first_number ?? '') !== '10-00001'
        || ($result->last_number ?? '') !== '10-00002') {
        throw new \RuntimeException('No se encontraron dos asientos unicos y balanceados.');
    }
    $dbA->rollBack();
    $start=false;
    echo "[PASS] Dos conexiones: 10-00001 y 10-00002, dos cabeceras, cuatro partidas, sin duplicados.\n";

    if (getenv('F23_PERIOD_CONCURRENCY') === '1') {
        // F2.3 uses the already COMMITTED fixture in disposable Docker.
        // PostgreSQL isolation prevents a second connection from seeing
        // a fixture first inserted in an uncommitted transaction.
        $periodPhase='actor/request setup';
        $databaseManager->setDefaultConnection('pgsql');
        $context=app(\App\Support\Tenant\TenantContext::class);
        $context->setCompanyId($company);
        $actor=\App\Models\User::query()->findOrFail($user);
        $request=\Illuminate\Http\Request::create(
            '/api/v1/accounting/fiscal-periods/'.$period.'/close','POST'
        );
        $request->setUserResolver(static fn()=>$actor);
        $periodLifecycle=app(\App\Services\Accounting\FiscalPeriodStatusService::class);
        $postService=app(\App\Services\Accounting\PostJournalEntryService::class);
        $reason='Cierre de prueba concurrente F2.3, solo Docker descartable';
        $assertPeriod=static function(bool $condition,string $description):void{
            if(!$condition)throw new \RuntimeException($description);
        };
        $tryNowait=static function(
            \Illuminate\Database\Connection $conn,int $companyId,int $periodId
        ):bool{
            // Isolate 55P03 in a savepoint so B can continue after A commits.
            $conn->beginTransaction();
            $locked=false;
            try{
                $conn->selectOne(
                    'SELECT id FROM public.fiscal_periods WHERE company_id=? AND id=? FOR UPDATE NOWAIT',
                    [$companyId,$periodId]
                );
            }catch(\Illuminate\Database\QueryException $error){
                $locked=(string)($error->errorInfo[0]??$error->getCode())==='55P03';
                if(!$locked)throw $error;
            }finally{
                $conn->rollBack();
            }
            return $locked;
        };
        $asTenant=static function(
            \Illuminate\Database\Connection $conn,int $companyId
        ):void{
            $conn->beginTransaction();
            $conn->selectOne(
                "SELECT set_config('app.current_company_id',?,true)",[(string)$companyId]
            );
            $conn->selectOne("SELECT set_config('lock_timeout','2500',true)");
        };

        // Race 1: CLOSE holds the row, POST B must wait, then observes CLOSED.
        $periodPhase='A closes / B cannot lock during closing';
        $asTenant($dbA,$company);$start=true;
        $databaseManager->setDefaultConnection('pgsql');
        $closed=$periodLifecycle->change($period,'close',$reason,$request);
        $assertPeriod($closed->status==='CLOSED','A did not close OPEN period');
        $asTenant($dbB,$company);
        $assertPeriod($tryNowait($dbB,$company,$period),
            'B acquired row lock while closing transaction A still held it');
        $dbA->commit();$start=false;

        $periodPhase='B rejects POST after A commits close';
        $databaseManager->setDefaultConnection('pgsql_f114_b');
        $view=$dbB->selectOne(
            'SELECT status FROM public.fiscal_periods WHERE company_id=? AND id=?',
            [$company,$period]
        );
        $assertPeriod(($view->status??'')==='CLOSED',
            'B did not observe CLOSED after A committed');
        $blocked=false;
        try{
            $postService->post([
                'fiscal_period_id'=>$period,'entry_number'=>'F23-BLOCKED',
                'entry_date'=>'2026-10-22','entry_type'=>'STANDARD',
                'currency'=>'PEN','exchange_rate'=>'1.000000',
                'exchange_rate_type'=>'SELL','description'=>'Denied by closed period',
                'created_by_user_id'=>$user,
                'lines'=>[
                    ['accounting_account_id'=>$accountIds[0],
                     'debit_local'=>'15.0000','credit_local'=>'0.0000',
                     'debit_foreign'=>'15.0000','credit_foreign'=>'0.0000'],
                    ['accounting_account_id'=>$accountIds[1],
                     'debit_local'=>'0.0000','credit_local'=>'15.0000',
                     'debit_foreign'=>'0.0000','credit_foreign'=>'15.0000'],
                ],
            ]);
        }catch(\DomainException $e){
            $blocked=str_contains($e->getMessage(),'cerrado');
        }
        $assertPeriod($blocked,'POST accepted or failed for wrong reason in CLOSED');
        $reopened=$periodLifecycle->change($period,'reopen',
            'Reapertura controlada para la segunda carrera concurrente',$request);
        $assertPeriod($reopened->status==='OPEN','B could not reopen CLOSED');
        $dbB->commit();
        echo "[PASS] F2.3 race CLOSE-first: B 55P03; after commit POST rejected in CLOSED, authorized reopen OK.\n";

        // Race 2: POST holds the row before CLOSE. The posting transaction
        // publishes another balanced voucher; then CLOSE gets the lock.
        $periodPhase='A posts / B cannot close while post lock is held';
        $asTenant($dbA,$company);$start=true;
        $databaseManager->setDefaultConnection('pgsql');
        $dbA->selectOne(
            'SELECT id FROM public.fiscal_periods WHERE company_id=? AND id=? FOR UPDATE',
            [$company,$period]
        );
        $asTenant($dbB,$company);
        $assertPeriod($tryNowait($dbB,$company,$period),
            'B acquired row lock during an active POST transaction');
        $n3=$numbers->nextForPeriod($company,$period,10);
        $assertPeriod($n3==='10-00003',
            'POST under lock did not obtain next unique entry number');
        $insertBalanced($dbA,$entryBase+2,4,$n3);
        $dbA->statement('SET CONSTRAINTS ALL IMMEDIATE');
        $dbA->commit();$start=false;

        $periodPhase='B closes after post commits';
        $databaseManager->setDefaultConnection('pgsql_f114_b');
        $afterPostClose=$periodLifecycle->change($period,'close',
            'Cierre posterior a asiento concurrente confirmado',$request);
        $assertPeriod($afterPostClose->status==='CLOSED',
            'CLOSE did not serialize after POST commit');
        $dbB->commit();

        $periodPhase='verify final committed Docker state';
        $asTenant($dbA,$company);$start=true;
        $counts=$dbA->selectOne(<<<'SQL'
            SELECT
              (SELECT count(*) FROM public.journal_entries WHERE company_id=?) AS journals,
              (SELECT count(*) FROM public.journal_entry_lines WHERE company_id=?) AS lines,
              (SELECT count(*) FROM public.audit_logs
                WHERE company_id=? AND event_type='FISCAL_PERIOD_CLOSED') AS closes,
              (SELECT count(*) FROM public.audit_logs
                WHERE company_id=? AND event_type='FISCAL_PERIOD_REOPENED') AS reopens,
              (SELECT status FROM public.fiscal_periods WHERE company_id=? AND id=?) AS state
            SQL,[$company,$company,$company,$company,$company,$period]);
        $assertPeriod($counts && (int)$counts->journals===3
            &&(int)$counts->lines===6 &&(int)$counts->closes===2
            &&(int)$counts->reopens===1 &&$counts->state==='CLOSED',
            'Concurrent close/post/reopen left wrong vouchers or audit events');
        $dbA->rollBack();$start=false;
        echo "[PASS] F2.3 race POST-first: CLOSE waits, journal 10-00003 posted, then CLOSED.\n";
        echo "[PASS] F2.3 verified: 3 immutable vouchers / 6 lines / 2 closes / 1 reopen, Docker-only.\n";
    }
    $passed=true;
    echo "[PASS] Solo Docker efimero: el contenedor sera destruido por el wrapper.\n";
} catch (\Throwable $error) {
    // No imprimir payload SQL, credenciales o mensajes completos.
    $state=(string)($error->errorInfo[0] ?? $error->getCode());
    $state=preg_match('/^[A-Z0-9]{5}$/',$state) ? $state : 'N/A';
    $stage=isset($periodPhase) ? preg_replace('/[^A-Za-z0-9 _-]/','',(string)$periodPhase) : 'F1.14 baseline';
    fwrite(STDERR,'[FAIL] Concurrencia Docker fase='.$stage.', SQLSTATE='.$state.PHP_EOL);
} finally {
    $databaseManager->setDefaultConnection($previousDefault);
    foreach ([$dbB,$dbA] as $db) {
        if ($db===null) { continue; }
        try {
            while ($db->transactionLevel()>0) { $db->rollBack(); }
            $db->disconnect();
        } catch (\Throwable $ignored) {
            $passed=false;
        }
    }
}
if (! $passed) { exit(1); }
