<?php

declare(strict_types=1);

/**
 * F2.3h: real HTTP ACTIVE + FORCE RLS in ONE postgres transaction with SET LOCAL ROLE.
 * Only the PowerShell wrapper creates the guarded, disposable PostgreSQL 18 container.
 * Nothing here may point at Neon or a persistent database.
 */
$mode=$argv[1]??'--plan';
if($argc!==2||!in_array($mode,['--plan','--probe=ephemeral-docker'],true)){
    fwrite(STDERR,"[FAIL] Uso: --plan | --probe=ephemeral-docker\n");exit(2);
}
if($mode==='--plan'){
    echo "[PLAN] F2.3h: SOLO Docker desechable PostgreSQL 18 (localhost puerto aleatorio).\n";
    echo "[PLAN] OWNER crea fixtures A/B y ACTIVE sin COMMIT; SET LOCAL ROLE app_backend en MISMA transaccion.\n";
    echo "[PLAN] HTTP Laravel/Sanctum comprueba 94/95/79, RLS real, autorizacion, extorno y auditoria.\n";
    echo "[PLAN] ROLLBACK y eliminacion de contenedor por wrapper PowerShell.\n";
    echo "[DRY-RUN] No conecta.\n";exit(0);
}
$root=dirname(__DIR__);
if(is_file($root.'/bootstrap/cache/config.php')
    ||getenv('APP_ENV')!=='testing'||getenv('DB_HOST')!=='127.0.0.1'
    ||getenv('DB_MIGRATIONS_HOST')!=='127.0.0.1'
    ||getenv('DB_DATABASE')!=='contabilidad_ci'
    ||getenv('DB_MIGRATIONS_DATABASE')!=='contabilidad_ci'
    ||getenv('DB_USERNAME')!=='postgres'
    ||getenv('DB_MIGRATIONS_USERNAME')!=='postgres'
    ||strlen((string)getenv('F23H_GUARD_TOKEN'))!==32
    ||!ctype_xdigit((string)getenv('F23H_GUARD_TOKEN'))){
    fwrite(STDERR,"[BLOQUEO] F2.3h exclusivo wrapper Docker testing con guardia aleatoria.\n");exit(1);
}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$manager=app('db');
$previousDefault=$manager->getDefaultConnection();
$manager->setDefaultConnection('pgsql_migrations');
$db=\Illuminate\Support\Facades\DB::connection('pgsql_migrations');
$kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);
$begun=false;$passed=false;
try{
    $cfg=(array)config('database.connections.pgsql_migrations',[]);
    $port=(string)getenv('DB_PORT');
    if(!$app->environment('testing')
        ||($cfg['host']??'')!=='127.0.0.1'
        ||(string)($cfg['port']??'')!==$port||$port==='5432'
        ||!preg_match('/^\d{2,5}$/D',$port)
        ||($cfg['database']??'')!=='contabilidad_ci'
        ||($cfg['username']??'')!=='postgres'
        ||(string)($cfg['sslmode']??'')!=='disable'
        ||trim((string)($cfg['url']??''))!==''){
        throw new RuntimeException('Laravel está apuntando fuera del Docker desechable.');
    }
    $pre=$db->selectOne(<<<'SQL'
        SELECT current_user AS role_name, current_database() AS database_name,
          current_setting('server_version_num')::integer AS version,
          (SELECT count(*) FROM public.f23h_guard WHERE token=?)::integer AS guard,
          (SELECT count(*) FROM public.migrations)::integer AS migrations,
          (SELECT count(*) FROM public.companies)::integer AS companies,
          (SELECT count(*) FROM public.journal_entries)::integer AS entries,
          (SELECT count(*) FROM public.accounting_destination_rule_versions)::integer AS versions
        SQL,[(string)getenv('F23H_GUARD_TOKEN')]);
    if(!$pre||$pre->role_name!=='postgres'||$pre->database_name!=='contabilidad_ci'
        ||intdiv((int)$pre->version,10000)!==18||(int)$pre->guard!==1
        ||(int)$pre->migrations!==20||(int)$pre->companies!==0
        ||(int)$pre->entries!==0||(int)$pre->versions!==0){
        throw new RuntimeException('Migraciones/guardia Docker no corresponden al escenario 20 migraciones.');
    }
    $db->beginTransaction();$begun=true;
    $db->selectOne("SELECT set_config('statement_timeout','25000',true)");
    $now=now();
    $A=4100000000101;$B=4100000000102;
    $adminId=6100000000201;$auditorId=6100000000202;
    foreach([[$A,'20999991011'],[$B,'20999991012']] as [$companyId,$taxId]){
        $db->table('companies')->insert([
            'id'=>$companyId,'tax_id'=>$taxId,
            'business_name'=>'F23H DOCKER '.$taxId,'legal_address'=>'Ephemeral test',
            'is_active'=>true,'created_at'=>$now,'updated_at'=>$now,
        ]);
    }
    foreach([[$adminId,'Administrador F23H'],[$auditorId,'Auditor F23H']] as [$id,$name]){
        $db->table('users')->insert([
            'id'=>$id,'name'=>$name,
            'email'=>'f23h-'.$id.'@example.test','password'=>'unused-test-only',
            'created_at'=>$now,'updated_at'=>$now,
        ]);
    }
    foreach([
        [$A,$adminId,'ADMIN'],[$B,$adminId,'ADMIN'],[$B,$auditorId,'AUDITOR'],
    ] as [$companyId,$userId,$role]){
        $db->table('company_user')->insert([
            'company_id'=>$companyId,'user_id'=>$userId,'role'=>$role,
            'is_active'=>true,'created_at'=>$now,'updated_at'=>$now,
        ]);
    }
    $db->table('users')->where('id',$adminId)->update(['current_company_id'=>$A]);
    $db->table('users')->where('id',$auditorId)->update(['current_company_id'=>$B]);

    $currencies=[];
    foreach([
        ['PEN','01','Soles','S/'],['USD','02','Dólares','$'],
    ] as [$code,$sunat,$name,$symbol]){
        $currencies[$code]=$db->table('currencies')->insertGetId([
            'code'=>$code,'sunat_code'=>$sunat,'name'=>$name,'symbol'=>$symbol,
            'is_base_currency'=>$code==='PEN',
            'created_at'=>$now,'updated_at'=>$now,
        ]);
    }
    foreach([$A,$B] as $companyId){
        foreach(['PEN','USD'] as $code){
            $db->table('company_currencies')->insert([
                'company_id'=>$companyId,'currency_id'=>$currencies[$code],
                'is_base'=>$code==='PEN','is_active'=>true,
                'created_at'=>$now,'updated_at'=>$now,
            ]);
        }
    }

    // Fixture includes real, hierarchical 6-digit PCGE account structure,
    // with only leaf accounts operational. The underlying 19 migrations
    // remain the unmodified migrations from the application.
    $accounts=[];
    foreach([$A,$B] as $companyId){
        $accounts[$companyId]=[];
        foreach([
            [['6','62','621','621100'],'EXPENSE','DEBIT'],
            [['9','94','941','941100'],'COST','DEBIT'],
            [['9','95','951','951100'],'COST','DEBIT'],
            [['7','79','791','791000'],'REVENUE','CREDIT'],
            [['4','40','401','401100'],'LIABILITY','CREDIT'],
        ] as [$codes,$type,$balance]){
            $parentId=null;
            foreach($codes as $idx=>$code){
                if(isset($accounts[$companyId][$code])){
                    $parentId=$accounts[$companyId][$code];
                    continue;
                }
                $auto=$code==='621100';
                $id=$db->table('accounting_accounts')->insertGetId([
                    'company_id'=>$companyId,'parent_id'=>$parentId,
                    'code'=>$code,'name'=>'F23H '.$code,
                    'level'=>$idx+1,'path'=>$code,
                    'account_type'=>$type,'balance_type'=>$balance,
                    'is_operational'=>$idx===3,'is_active'=>true,
                    'has_automatic_destination'=>$auto,
                    'requires_cost_center'=>$auto,
                    'destination_debit_code'=>$auto?'941100':null,
                    'destination_credit_code'=>$auto?'791000':null,
                    'created_at'=>$now,'updated_at'=>$now,
                ]);
                $accounts[$companyId][$code]=(int)$id;
                $parentId=(int)$id;
            }
        }
    }
    $centerIds=[];
    foreach([$A,$B] as $companyId){
        $centerIds[$companyId]=[];
        foreach([['CC-ADM','941100'],['CC-VTA','951100']] as [$code,$debit]){
            $centerIds[$companyId][$code]=(int)$db->table('cost_centers')->insertGetId([
                'company_id'=>$companyId,'code'=>$code,'name'=>'F23H '.$code,
                'destination_debit_account_id'=>$accounts[$companyId][$debit],
                'destination_credit_account_id'=>$accounts[$companyId]['791000'],
                'is_active'=>true,'created_at'=>$now,'updated_at'=>$now,
            ]);
        }
        $snapshot=[
            '621100|CC-ADM|941100|791000',
            '621100|CC-VTA|951100|791000',
        ];
        $versionId=$db->table('accounting_destination_rule_versions')->insertGetId([
            'company_id'=>$companyId,'version_key'=>'PCGE_2019_CC_V2',
            'strategy'=>'COST_CENTER','status'=>$companyId===$A?'ACTIVE':'DRAFT',
            'source_count'=>1,'route_count'=>2,
            'matrix_sha256'=>hash('sha256',implode("\n",$snapshot)),
        ]);
        foreach([['CC-ADM','941100'],['CC-VTA','951100']] as [$cc,$debit]){
            $db->table('accounting_destination_routes')->insert([
                'company_id'=>$companyId,'rule_version_id'=>$versionId,
                'source_account_id'=>$accounts[$companyId]['621100'],
                'cost_center_id'=>$centerIds[$companyId][$cc],
                'destination_debit_account_id'=>$accounts[$companyId][$debit],
                'destination_credit_account_id'=>$accounts[$companyId]['791000'],
            ]);
        }
    }
    echo "[PASS] Dos empresas Docker; matriz A ACTIVE NO COMMIT, B DRAFT, roles/PCGE y centros preparados.\n";

    // SESSION USER stays postgres (for reversibility); CURRENT USER becomes
    // app_backend with all actual FORCE RLS and PostgreSQL ACL restrictions.
    $db->statement('SET LOCAL ROLE app_backend');
    $role=$db->selectOne(<<<'SQL'
        SELECT current_user AS current_role,session_user AS session_role,
          (SELECT CASE WHEN rolbypassrls THEN 1 ELSE 0 END FROM pg_roles WHERE rolname=current_user) AS bypass_rls
        SQL);
    if(!$role||$role->current_role!=='app_backend'
        ||$role->session_role!=='postgres'||(int)$role->bypass_rls!==0){
        throw new RuntimeException('SET LOCAL ROLE no aplicó restricciones reales.');
    }
    echo "[PASS] HTTP bajo current_user=app_backend, session_user=postgres, sin BYPASSRLS.\n";

    $actor=\App\Models\User::query()->findOrFail($adminId);
    $auditor=\App\Models\User::query()->findOrFail($auditorId);
    \Laravel\Sanctum\Sanctum::actingAs($actor,['*'],'sanctum');
    $http=static function(
        string $verb,string $uri,int $tenant,?array $payload=null,?string $idempotencyKey=null
    )use($kernel):array{
        $headers=[
            'HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json',
            'HTTP_X_COMPANY_ID'=>(string)$tenant,
        ];
        if ($idempotencyKey !== null) {
            $headers['HTTP_IDEMPOTENCY_KEY']=$idempotencyKey;
        }
        $request=\Illuminate\Http\Request::create(
            $uri,$verb,[],[],[],$headers,
            $payload===null?null:json_encode($payload,JSON_THROW_ON_ERROR)
        );
        $response=$kernel->handle($request);
        return [$response->getStatusCode(),
            json_decode((string)$response->getContent(),true,512,JSON_THROW_ON_ERROR)];
    };
    $assert=static function(bool $ok,string $message):void{
        if(!$ok)throw new RuntimeException($message);
    };
    $payload=static function(string $cc,string $number,bool $credit=false)use($A,$accounts,$centerIds):array{
        $line=[
            'accounting_account_id'=>$accounts[$A]['621100'],
            'cost_center_id'=>$centerIds[$A][$cc],
            'debit_local'=>$credit?'0.0000':'150.0000',
            'credit_local'=>$credit?'150.0000':'0.0000',
            'debit_foreign'=>$credit?'0.0000':'40.0000',
            'credit_foreign'=>$credit?'40.0000':'0.0000',
        ];
        return [
            'entry_date'=>'2026-10-22','entry_number'=>$number,
            'entry_type'=>$credit?'ADJUSTMENT':'STANDARD',
            'currency'=>'USD','exchange_rate'=>'3.750000',
            'exchange_rate_type'=>'SELL',
            'exchange_rates'=>[[
                'currency'=>'USD','buy_rate'=>'3.720000',
                'sell_rate'=>'3.750000','rate'=>'3.750000',
            ]],
            'description'=>'F23H HTTP ACTIVE with FORCE RLS',
            'lines'=>[$line,[
                'accounting_account_id'=>$accounts[$A]['401100'],
                'cost_center_id'=>null,
                'debit_local'=>$line['credit_local'],
                'credit_local'=>$line['debit_local'],
                'debit_foreign'=>$line['credit_foreign'],
                'credit_foreign'=>$line['debit_foreign'],
            ]],
        ];
    };

    $created=[];
    $idempotencyKey='f23h-idempotency-retry-0001';
    foreach(['CC-ADM','CC-VTA'] as $cc){
        [$status,$body]=$http('POST','/api/v1/accounting/journal-entries',$A,
            $payload($cc,'F23H-'.$cc),$cc==='CC-ADM'?$idempotencyKey:null);
        $lines=$body['data']['lines']??[];
        $assert($status===201 && count($lines)===4,
            'HTTP ACTIVE '.$cc.' esperado 201/4 líneas; status '.$status.' '.substr((string)($body['message']??''),0,200));
        $db->selectOne("SELECT set_config('app.current_company_id',?,true)",[(string)$A]);
        $actual=$db->selectOne(<<<'SQL'
            SELECT count(*)::integer AS count
            FROM public.journal_analytic_allocations
            WHERE company_id=? AND journal_entry_id=?
            SQL,[$A,$body['data']['id']]);
        $assert((int)$actual->count===1,'app_backend no pudo registrar trazabilidad dentro de RLS.');
        $db->selectOne("SELECT set_config('app.current_company_id','',true)");
        $expectedCode=$cc==='CC-ADM'?'941100':'951100';
        $assert((int)$lines[2]['accounting_account_id']===$accounts[$A][$expectedCode]
            &&(int)$lines[3]['accounting_account_id']===$accounts[$A]['791000']
            &&(string)$lines[2]['debit_local']==='150.0000'
            &&(string)$lines[3]['credit_foreign']==='40.0000',
            'Destino o bimoneda ACTIVE incorrecto.');
        $created[$cc]=$body['data'];
        echo "[PASS] HTTP app_backend ACTIVE ".$cc.": 6xx -> ".$expectedCode."/791000, 4 lineas y 1 enlace RLS.\n";
    }

    // Replaying must return the same voucher without new FX, lines or audit.
    $samePayload=$payload('CC-ADM','F23H-CC-ADM');
    [$replayStatus,$replay]=$http('POST','/api/v1/accounting/journal-entries',
        $A,$samePayload,$idempotencyKey);
    $assert($replayStatus===200
        &&(int)($replay['data']['id']??0)===(int)$created['CC-ADM']['id'],
        'Reintento HTTP no recupero el asiento original.');
    $different=$samePayload;
    $different['description']='CONTENIDO DIFERENTE CON MISMA CLAVE';
    [$conflictStatus]=$http('POST','/api/v1/accounting/journal-entries',
        $A,$different,$idempotencyKey);
    $assert($conflictStatus===409,'Payload distinto con misma clave no respondió 409.');
    $db->selectOne("SELECT set_config('app.current_company_id',?,true)",[(string)$A]);
    $idemCounts=$db->selectOne(<<<'SQL'
        SELECT (SELECT count(*) FROM public.journal_post_idempotency WHERE company_id=?)::integer AS receipts,
          (SELECT count(*) FROM public.journal_entries WHERE company_id=?)::integer AS entries,
          (SELECT count(*) FROM public.audit_logs WHERE company_id=? AND event_type='JOURNAL_ENTRY_CREATED')::integer AS audits
        SQL,[$A,$A,$A]);
    $assert((int)$idemCounts->receipts===1&&(int)$idemCounts->entries===2
        &&(int)$idemCounts->audits===2,'Reintentos duplicaron asientos o auditoria.');
    $db->selectOne("SELECT set_config('app.current_company_id','',true)");

    // Explicit negative tenant isolation: company B sees neither receipt nor
    // journal that was generated under company A.
    $db->selectOne("SELECT set_config('app.current_company_id',?,true)",[(string)$B]);
    $visibleB=$db->selectOne(
        'SELECT count(*)::integer AS n FROM public.journal_post_idempotency'
    );
    $assert((int)$visibleB->n===0,'RLS revela recibos idempotentes entre empresas.');
    $db->selectOne("SELECT set_config('app.current_company_id',?,true)",[(string)$A]);

    // Receipts are append-only to the app role. Test against actual SQL ACL
    // without causing a broken outer transaction.
    $immutabilityDenied=false;
    $db->beginTransaction(); // Laravel SAVEPOINT
    try {
        $db->table('journal_post_idempotency')
            ->where('company_id',$A)
            ->where('idempotency_key',$idempotencyKey)
            ->update(['request_sha256'=>str_repeat('0',64)]);
    }catch(\Illuminate\Database\QueryException $e){
        $immutabilityDenied=($e->errorInfo[0]??'')==='42501';
    }finally{
        $db->rollBack(); // ROLLBACK TO SAVEPOINT
    }
    $assert($immutabilityDenied,'app_backend pudo modificar un recibo idempotente inmutable.');
    $db->selectOne("SELECT set_config('app.current_company_id','',true)");
    echo "[PASS] F2.2 HTTP idempotencia RLS: 201 -> 200 replay, 409 conflicto, 2 asientos/2 auditorias/1 recibo; RLS B aislado, UPDATE denegado.\n";

    [$listA,$a]=$http('GET','/api/v1/accounting/journal-entries',$A);
    [$listB,$b]=$http('GET','/api/v1/accounting/journal-entries',$B);
    $assert($listA===200 && count($a['data']??[])===2
        &&$listB===200 && count($b['data']??[])===0,
        'RLS falló: empresa B ve asientos ACTIVE de A.');
    \Laravel\Sanctum\Sanctum::actingAs($auditor,['*'],'sanctum');
    [$notAllowed]=$http('POST','/api/v1/accounting/journal-entries',$B,
        $payload('CC-ADM','F23H-DENIED'));
    [$notMember]=$http('GET','/api/v1/accounting/journal-entries',$A);
    $assert($notAllowed===403 && $notMember===403,
        'Guardas de rol o membresía en HTTP ACTIVE omitidas.');
    \Laravel\Sanctum\Sanctum::actingAs($actor,['*'],'sanctum');
    echo "[PASS] HTTP RLS ACTIVE: B no puede leer A; auditor recibe 403 POST y GET sin membresía.\n";

    [$adjustStatus,$adjust]=$http('POST','/api/v1/accounting/journal-entries',$A,
        $payload('CC-VTA','F23H-ADJUST',true));
    $adjustLines=$adjust['data']['lines']??[];
    $assert($adjustStatus===201 && count($adjustLines)===4
        &&(int)$adjustLines[2]['accounting_account_id']===$accounts[$A]['791000']
        &&(int)$adjustLines[3]['accounting_account_id']===$accounts[$A]['951100'],
        'Adjustment Haber 6xx no invierte 79/95.');
    echo "[PASS] HTTP ACTIVE RLS ajuste Haber 6xx: Debe 791000 / Haber 951100.\n";

    $original=$created['CC-ADM'];
    [$reversalStatus,$reverse]=$http('POST',
        '/api/v1/accounting/journal-entries/'.$original['id'].'/reverse',$A,[
            'fiscal_period_id'=>(int)$original['fiscal_period_id'],
            'reversal_date'=>'2026-10-22','entry_number'=>'F23H-REVERSE',
            'reason'=>'Prueba combinada ACTIVE y FORCE RLS',
        ]);
    $reversedLines=$reverse['data']['lines']??[];
    $assert($reversalStatus===201 && count($reversedLines)===4,
        'Extorno RLS ACTIVE esperado HTTP 201 con 4 líneas.');
    foreach($original['lines'] as $index=>$line){
        $inv=$reversedLines[$index];
        foreach(['local','foreign'] as $currency){
            $assert((string)$inv['debit_'.$currency]===(string)$line['credit_'.$currency]
                &&(string)$inv['credit_'.$currency]===(string)$line['debit_'.$currency],
                'Extorno no preservó bimoneda exacta.');
        }
    }
    $db->selectOne("SELECT set_config('app.current_company_id',?,true)",[(string)$A]);
    $links=$db->selectOne("SELECT count(*)::integer AS n FROM public.journal_analytic_allocations WHERE company_id=?",[$A]);
    $audits=$db->selectOne(<<<'SQL'
        SELECT
          (SELECT count(*) FROM public.audit_logs WHERE company_id=? AND event_type='JOURNAL_ENTRY_CREATED')::integer AS creations,
          (SELECT count(*) FROM public.audit_logs WHERE company_id=? AND event_type='JOURNAL_ENTRY_REVERSED')::integer AS reversals
        SQL,[$A,$A]);
    $assert((int)$links->n===4&&(int)$audits->creations===3&&(int)$audits->reversals===1,
        'Auditoría o enlaces incompletos con ACTIVE y RLS.');
    $db->statement('SET CONSTRAINTS ALL IMMEDIATE');
    $totals=$db->select(<<<'SQL'
        SELECT journal_entry_id,sum(debit_local)-sum(credit_local) AS local_diff,
          sum(debit_foreign)-sum(credit_foreign) AS foreign_diff
        FROM public.journal_entry_lines WHERE company_id=? GROUP BY journal_entry_id
        SQL,[$A]);
    $assert(count($totals)===4,'Se esperaban cuatro vouchers validados.');
    foreach($totals as $row){
        $assert((string)$row->local_diff==='0.0000'
            &&(string)$row->foreign_diff==='0.0000','Voucher descuadrado.');
    }
    echo "[PASS] HTTP ACTIVE + FORCE RLS: 4 asientos balanceados, 4 enlaces, extorno exacto y auditoría.\n";
    $passed=true;
}catch(\Throwable $e){
    $msg=$e instanceof \Illuminate\Database\QueryException
        ?'SQLSTATE='.($e->errorInfo[0]??$e->getCode())
        :substr($e->getMessage(),0,380);
    fwrite(STDERR,"[FAIL] F2.3h: ".$msg."\n");
}finally{
    if($begun){
        try{$db->rollBack();echo "[ROLLBACK] PostgreSQL Docker (fixtures, ACTIVE y asientos) revertido.\n";}
        catch(\Throwable){$passed=false;fwrite(STDERR,"[BLOQUEO] Rollback falló.\n");}
    }
    $manager->setDefaultConnection($previousDefault);
    $db->disconnect();
}
exit($passed?0:1);
