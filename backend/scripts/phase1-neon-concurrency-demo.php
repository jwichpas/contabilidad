<?php

declare(strict_types=1);

/**
 * F1.14 Neon sin Docker: 2 conexiones fisicas app_backend compiten por
 * FOR UPDATE del MISMO periodo. La variante --apply=contabilidad-dev
 * PERSISTE 1 periodo, 8 cuentas PCGE y 2 asientos de ejemplo en DEMO A.
 *
 * No se usa ni se escribe en Neon production / Supabase.
 * Por defecto --plan no carga Laravel ni abre ninguna conexion.
 */
$mode = $argv[1] ?? '--plan';
if ($argc > 2 || ! in_array($mode,['--plan','--apply=contabilidad-dev'],true)) {
    fwrite(STDERR,"[FAIL] Uso: php scripts/phase1-neon-concurrency-demo.php [--plan|--apply=contabilidad-dev]\n");
    exit(2);
}
if ($mode === '--plan') {
    echo "[PLAN] Requiere 3 empresas y 3 usuarios DEMO existentes, 17 migraciones, sin asientos.\n";
    echo "[PLAN] Solo app_backend de Neon contabilidad-dev, dos conexiones fisicas reales.\n";
    echo "[PLAN] Crear periodo 2026-10, jerarquia de 8 cuentas y dos asientos DEMO A.\n";
    echo "[PLAN] A retiene FOR UPDATE; B exige 55P03 con NOWAIT y espera liberar el bloqueo.\n";
    echo "[PLAN] Tras COMMIT A, B toma el bloqueo y genera 10-00002 despues de 10-00001.\n";
    echo "[AVISO] Esta prueba PERSISTE periodo, cuentas, partidas y asientos ficticios en Neon dev.\n";
    echo "[AVISO] No ejecutarla por segunda vez. No modifica production ni Supabase.\n";
    echo "[DRY-RUN] Ninguna conexion ni SQL ejecutado.\n";
    exit(0);
}
$backend = dirname(__DIR__);
$host = 'ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$companyId=3000000000101;
$userId=3000000000201;
$periodId=3000000000401;
$accountBase=3000000000500;
$entryBase=3000000000601;
$lineBase=3000000000701;
if (! is_file($backend.'/.env') || is_file($backend.'/bootstrap/cache/config.php')) {
    fwrite(STDERR,"[BLOQUEO] .env ausente o config Laravel cacheada.\n");
    exit(1);
}
$seen=[];$duplicates=[];
$lines=file($backend.'/.env',FILE_IGNORE_NEW_LINES);
if ($lines===false) { fwrite(STDERR,"[BLOQUEO] No se pudo leer .env.\n");exit(1); }
foreach ($lines as $line) {
    if (! preg_match('/^[ \t]*(?:export[ \t]+)?([A-Z][A-Z0-9_]*)[ \t]*=/',$line,$m)) continue;
    $key=$m[1];
    if (!str_starts_with($key,'DB_') && !str_starts_with($key,'NEON_')
        && !in_array($key,['APP_ENV','APP_KEY','DATABASE_URL'],true)) continue;
    if(isset($seen[$key])) $duplicates[]=$key;
    $seen[$key]=true;
}
unset($lines);
if($duplicates!==[]) {
    fwrite(STDERR,'[BLOQUEO] Variables duplicadas '.implode(',',array_unique($duplicates)).PHP_EOL);
    exit(1);
}
require $backend.'/vendor/autoload.php';
$app=require $backend.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$cfg=(array)config('database.connections.pgsql',[]);
$admin=(array)config('database.connections.pgsql_migrations',[]);
$pass=(string)($cfg['password']??'');
$rolePass=(string)env('DB_APP_BACKEND_ROLE_PASSWORD','');
if(! $app->environment('local')
    || config('database.default')!=='pgsql'
    || env('NEON_TEST_BRANCH_NAME')!=='contabilidad-dev'
    || env('NEON_TEST_BRANCH_HOST')!==$host
    || (string)($cfg['host']??'')!==$host
    || (string)($admin['host']??'')!==$host
    || (string)($cfg['database']??'')!=='neondb'
    || (string)($admin['database']??'')!=='neondb'
    || (string)($cfg['port']??'')!=='5432'
    || (string)($admin['port']??'')!=='5432'
    || (string)($cfg['username']??'')!=='app_backend'
    || (string)($admin['username']??'')!=='neondb_owner'
    || trim((string)($cfg['url']??''))!==''
    || trim((string)($admin['url']??''))!==''
    || !in_array((string)($cfg['sslmode']??''),['require','verify-ca','verify-full'],true)
    || filter_var(env('NEON_USE_POOLING',false),FILTER_VALIDATE_BOOLEAN)
    || strlen($rolePass)<20 || $pass==='' || !hash_equals($pass,$rolePass)) {
    fwrite(STDERR,"[BLOQUEO] Conexión diferente de Neon dev con app_backend restringido.\n");
    exit(1);
}
unset($pass,$rolePass);

$dbA=\Illuminate\Support\Facades\DB::connection('pgsql');
$manager=app('db');
$defaultName=$manager->getDefaultConnection();
$dbB=null;
$step='preflight';
$passed=false;
try {
    $identity=$dbA->selectOne(<<<'SQL'
        SELECT current_user AS actor,current_database() AS db,
               current_setting('server_version_num')::integer AS version,
               (SELECT count(*) FROM public.migrations)::integer AS migrations,
               (SELECT count(*) FROM public.companies)::integer AS companies,
               (SELECT count(*) FROM public.users)::integer AS users,
               (SELECT count(*) FROM public.company_user)::integer AS memberships,
               (SELECT count(*) FROM public.journal_entries)::integer AS entries,
               (SELECT count(*) FROM public.accounting_accounts)::integer AS accounts,
               (SELECT count(*) FROM public.fiscal_periods)::integer AS periods,
               (SELECT count(*) FROM pg_catalog.pg_roles
                WHERE rolname=current_user AND rolcanlogin AND NOT rolsuper
                  AND NOT rolbypassrls AND NOT rolcreaterole AND NOT rolcreatedb)::integer AS safe_role,
               (SELECT count(*) FROM public.company_user
                WHERE company_id=? AND user_id=? AND role='ADMIN' AND is_active)::integer AS admin_member
        SQL,[$companyId,$userId]);
    if($identity===null || $identity->actor!=='app_backend'
       || $identity->db!=='neondb'
       || intdiv((int)$identity->version,10000)!==18
       || (int)$identity->migrations!==17
       || (int)$identity->companies!==3
       || (int)$identity->users!==3
       || (int)$identity->memberships!==8
       || (int)$identity->entries!==0
       || (int)$identity->accounts!==0
       || (int)$identity->periods!==0
       || (int)$identity->safe_role!==1
       || (int)$identity->admin_member!==0) {
        // RLS correctly hides memberships before tenant is active;
        // require the grant via a scoped transaction below instead.
        if ($identity===null || $identity->actor!=='app_backend'
            || $identity->db!=='neondb' || (int)($identity->entries??-1)!==0
            || (int)($identity->accounts??-1)!==0
            || (int)($identity->periods??-1)!==0
            || (int)($identity->companies??-1)!==3
            || (int)($identity->users??-1)!==3
            || (int)($identity->migrations??-1)!==17
            || (int)($identity->safe_role??0)!==1) {
            throw new \RuntimeException('Estado de desarrollo inesperado.');
        }
    }
    echo '[PASS] Neon contabilidad-dev: 3 empresas, 3 usuarios, 17 migraciones, sin asientos.'.PHP_EOL;
    config(['database.connections.pgsql_f114_neon_b'=>$cfg]);
    $dbB=\Illuminate\Support\Facades\DB::connection('pgsql_f114_neon_b');
    if((int)($dbA->selectOne('SELECT pg_backend_pid() AS pid')->pid??0)
       ===(int)($dbB->selectOne('SELECT pg_backend_pid() AS pid')->pid??0)) {
        throw new \RuntimeException('Las conexiones deben ser fisicamente distintas.');
    }
    $step='fixture de periodo y cuentas';
    $dbA->beginTransaction();
    $dbA->selectOne("SELECT set_config('app.current_company_id',?,true) AS val",[(string)$companyId]);
    if((int)$dbA->table('company_user')->where('company_id',$companyId)
            ->where('user_id',$userId)->where('role','ADMIN')->count()!==1) {
        throw new \RuntimeException('ADMIN demo sin membresia en DEMO A.');
    }
    $dbA->table('fiscal_periods')->insert([
        'id'=>$periodId,'company_id'=>$companyId,'year'=>2026,'month'=>10,
        'start_date'=>'2026-10-01','end_date'=>'2026-10-31','status'=>'OPEN',
        'created_at'=>now(),'updated_at'=>now(),
    ]);
    $operational=[];
    foreach([
        [['1','10','101','1011'],'ASSET','DEBIT'],
        [['4','40','401','4011'],'LIABILITY','CREDIT'],
    ] as $family=>[$codes,$type,$balance]) {
        $parent=null;
        foreach($codes as $level=>$code) {
            $id=$accountBase+$family*4+$level;
            $dbA->table('accounting_accounts')->insert([
                'id'=>$id,'company_id'=>$companyId,'parent_id'=>$parent,
                'code'=>$code,'name'=>'DEMO PCGE '.$code,'path'=>$code,
                'level'=>$level+1,'account_type'=>$type,'balance_type'=>$balance,
                'is_operational'=>$level===3,'is_active'=>true,
                'created_at'=>now(),'updated_at'=>now(),
            ]);
            if($level===3) $operational[]=$id;
            $parent=$id;
        }
    }
    if(count($operational)!==2)throw new \RuntimeException('Cuentas operativas incompletas.');
    $dbA->commit();
    echo '[PASS] DEMO A: periodo octubre 2026 y 8 cuentas PCGE de prueba persistidos.'.PHP_EOL;

    $insert = static function(
        \Illuminate\Database\Connection $db,int $n,string $number
    ) use($companyId,$userId,$periodId,$entryBase,$lineBase,$operational):void {
        $id=$entryBase+$n;
        $db->table('journal_entries')->insert([
            'id'=>$id,'company_id'=>$companyId,'fiscal_period_id'=>$periodId,
            'entry_number'=>$number,'entry_date'=>'2026-10-21',
            'entry_type'=>'STANDARD','status'=>'POSTED',
            'currency'=>'PEN','exchange_rate'=>'1.000000','exchange_rate_type'=>'SELL',
            'description'=>'DEMO F1.14 - verificacion de concurrencia',
            'created_by_user_id'=>$userId,'created_at'=>now(),'updated_at'=>now(),
        ]);
        foreach([
            [$operational[0],'120.0000','0.0000','120.0000','0.0000'],
            [$operational[1],'0.0000','120.0000','0.0000','120.0000']
        ] as $i=>[$account,$dr,$cr,$fdr,$fcr]) {
            $db->table('journal_entry_lines')->insert([
                'id'=>$lineBase+$n*2+$i,
                'company_id'=>$companyId,'journal_entry_id'=>$id,
                'line_number'=>$i+1,'accounting_account_id'=>$account,
                'debit_local'=>$dr,'credit_local'=>$cr,
                'debit_foreign'=>$fdr,'credit_foreign'=>$fcr,
                'exchange_rate'=>'1.000000','created_at'=>now(),'updated_at'=>now(),
            ]);
        }
        $db->statement('SET CONSTRAINTS ALL IMMEDIATE');
    };

    $step='competencia FOR UPDATE';
    $dbA->beginTransaction();
    $dbA->selectOne("SELECT set_config('app.current_company_id',?,true) AS val",[(string)$companyId]);
    $dbA->selectOne('SELECT id FROM public.fiscal_periods WHERE id=? FOR UPDATE',[$periodId]);
    $dbB->beginTransaction();
    $dbB->selectOne("SELECT set_config('app.current_company_id',?,true) AS val",[(string)$companyId]);
    $dbB->beginTransaction();
    $blocked=false;
    try {
        $dbB->selectOne('SELECT id FROM public.fiscal_periods WHERE id=? FOR UPDATE NOWAIT',[$periodId]);
    } catch(\Throwable $error) {
        $blocked=(string)($error->errorInfo[0]??$error->getCode())==='55P03';
    } finally {
        $dbB->rollBack();
    }
    if(!$blocked)throw new \RuntimeException('NOWAIT no demostro bloqueo 55P03.');
    echo '[PASS] DOS conexiones: la segunda recibe 55P03 mientras la primera bloquea el periodo.'.PHP_EOL;

    $numbers=new \App\Services\Accounting\JournalEntryNumberService();
    $manager->setDefaultConnection('pgsql');
    $numberA=$numbers->nextForPeriod($companyId,$periodId,10);
    if($numberA!=='10-00001')throw new \RuntimeException('Primer correlativo no es 10-00001.');
    $insert($dbA,0,$numberA);
    $dbA->commit();
    $step='segundo correlativo despues de COMMIT';
    $dbB->selectOne('SELECT id FROM public.fiscal_periods WHERE id=? FOR UPDATE',[$periodId]);
    $manager->setDefaultConnection('pgsql_f114_neon_b');
    $numberB=$numbers->nextForPeriod($companyId,$periodId,10);
    if($numberB!=='10-00002')throw new \RuntimeException('Segundo correlativo no es 10-00002.');
    $insert($dbB,1,$numberB);
    $dbB->commit();
    $manager->setDefaultConnection('pgsql');

    $dbA->beginTransaction();
    $dbA->selectOne("SELECT set_config('app.current_company_id',?,true) AS val",[(string)$companyId]);
    $rows=$dbA->selectOne(<<<'SQL'
        SELECT count(*)::integer AS total,
          count(DISTINCT entry_number)::integer AS distinct_numbers,
          min(entry_number) AS first_number,
          max(entry_number) AS second_number,
          (SELECT count(*) FROM public.journal_entry_lines
           WHERE company_id=? AND journal_entry_id IN (?,?))::integer AS lines
        FROM public.journal_entries
        WHERE company_id=? AND id IN (?,?)
        SQL,[$companyId,$entryBase,$entryBase+1,$companyId,$entryBase,$entryBase+1]);
    if((int)($rows->total??0)!==2 || (int)($rows->distinct_numbers??0)!==2
       || (int)($rows->lines??0)!==4 || ($rows->first_number??'')!=='10-00001'
       || ($rows->second_number??'')!=='10-00002') {
        throw new \RuntimeException('Dos asientos distintos y cuatro partidas no verificados.');
    }
    $dbA->rollBack();
    echo '[PASS] F1.14: 2 asientos DEMO permanentes 10-00001/10-00002, 4 partidas, sin duplicados.'.PHP_EOL;
    echo '[INFO] La prueba NO usa Docker; los asientos persisten en DEMO A hasta su limpieza de desarrollo.'.PHP_EOL;
    $passed=true;
} catch(\Throwable $error) {
    $sqlState=(string)($error->errorInfo[0]??$error->getCode());
    $sqlState=preg_match('/^[A-Z0-9]{5}$/',$sqlState)?$sqlState:'N/A';
    fwrite(STDERR,'[FAIL] F1.14 Neon en etapa '.$step.' | SQLSTATE '.$sqlState.'. Puede haber datos de prueba parcialmente guardados; no reintentar a ciegas.'.PHP_EOL);
} finally {
    $manager->setDefaultConnection($defaultName);
    foreach([$dbB,$dbA] as $db) {
        if($db===null)continue;
        try {
            while($db->transactionLevel()>0)$db->rollBack();
            $db->disconnect();
        } catch(\Throwable $ignored) {
            $passed=false;
        }
    }
}
if(!$passed)exit(1);
