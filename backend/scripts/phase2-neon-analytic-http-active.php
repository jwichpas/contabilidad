<?php

declare(strict_types=1);

/**
 * F2.3e: HTTP integration with a TEMPORARY ACTIVE destination matrix.
 *
 * One and ONLY ONE administrative PostgreSQL transaction:
 *   DRAFT -> ACTIVE (uncommitted), dispatch Laravel Kernel/Sanctum HTTP,
 *   prove 6xx/94-95/79 links, reversal, audit, deferred triggers,
 *   ROLLBACK original transaction and verify 3 DRAFT / 0 vouchers.
 *
 * Because the ordinary app_backend connection cannot see uncommitted owner
 * status, HTTP is intentionally executed on neondb_owner. This exercises
 * REAL Laravel HTTP/controller/service/auth/role logic but NOT RLS enforcement.
 * RLS app_backend is independently covered by F2.3c/d.
 */
$root=dirname(__DIR__);
$host='ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$mode=$argv[1]??'--plan';
if($argc!==2||!in_array($mode,['--plan','--probe=contabilidad-dev'],true)){
    fwrite(STDERR,"[FAIL] Uso: --plan|--probe=contabilidad-dev\n");exit(2);
}
if($mode==='--plan'){
    echo "[PLAN] F2.3e: transaccion unica neondb_owner; matriz A ACTIVE temporal no confirmada.\n";
    echo "[PLAN] Laravel HTTP/Sanctum: 201 CC-ADM 94/79, CC-VTA 95/79, ajuste Haber 6, extorno exacto, auditoria.\n";
    echo "[PLAN] Bloquear mezcla manual 94/95/79; validar 2 monedas, linkage y triggers diferidos.\n";
    echo "[PLAN] ROLLBACK global, 3 DRAFT, 0 asientos/periodos/TC/auditoria.\n";
    echo "[AVISO] El HTTP de este probe usa OWNER, no certifica enforcement RLS de app_backend.\n";
    echo "[DRY-RUN] Ninguna conexion a Neon.\n";exit(0);
}
if(!is_file($root.'/.env')||is_file($root.'/bootstrap/cache/config.php')){
    fwrite(STDERR,"[BLOQUEO] Falta .env o existe cache Laravel.\n");exit(1);
}
$seen=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES)?:[] as $line){
    if(!preg_match('/^[ \t]*(?:export[ \t]+)?([A-Z][A-Z0-9_]*)[ \t]*=/',$line,$m))continue;
    $key=$m[1];
    if(!str_starts_with($key,'DB_')&&!str_starts_with($key,'NEON_')
        &&!in_array($key,['APP_ENV','APP_KEY','DATABASE_URL'],true))continue;
    if(isset($seen[$key])){fwrite(STDERR,"[BLOQUEO] Variable .env duplicada.\n");exit(1);}
    $seen[$key]=true;
}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$admin=(array)config('database.connections.pgsql_migrations',[]);
$runtime=(array)config('database.connections.pgsql',[]);
$key=(string)env('APP_KEY','');
$keyBytes=str_starts_with($key,'base64:')?base64_decode(substr($key,7),true):$key;
if(!$app->environment('local')||config('database.default')!=='pgsql'
    ||env('NEON_TEST_BRANCH_NAME','')!=='contabilidad-dev'
    ||env('NEON_TEST_BRANCH_HOST','')!==$host
    ||($admin['host']??'')!==$host||($runtime['host']??'')!==$host
    ||(string)($admin['port']??'')!=='5432'||(string)($runtime['port']??'')!=='5432'
    ||($admin['database']??'')!=='neondb'||($runtime['database']??'')!=='neondb'
    ||($admin['username']??'')!=='neondb_owner'||($runtime['username']??'')!=='app_backend'
    ||trim((string)($admin['url']??''))!==''||trim((string)($runtime['url']??''))!==''
    ||!in_array((string)($admin['sslmode']??''),['require','verify-ca','verify-full'],true)
    ||!in_array((string)($runtime['sslmode']??''),['require','verify-ca','verify-full'],true)
    ||filter_var(env('NEON_USE_POOLING',false),FILTER_VALIDATE_BOOLEAN)
    ||$keyBytes===false||strlen($keyBytes)!==32){
    fwrite(STDERR,"[BLOQUEO] Exclusivo contabilidad-dev PG18 con SSL directo.\n");exit(1);
}
unset($key,$keyBytes);

use App\Models\User;
use Illuminate\Support\Facades\DB;
$manager=app('db');
$oldDefault=$manager->getDefaultConnection();
$manager->setDefaultConnection('pgsql_migrations');
$db=DB::connection('pgsql_migrations');
$kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);
$inTransaction=false;$passed=false;
try{
    $db->beginTransaction();$inTransaction=true;
    $db->selectOne("SELECT set_config('statement_timeout','20000',true)");
    $state=$db->selectOne(<<<'SQL'
        SELECT current_user AS actor,current_database() AS db,
            current_setting('server_version_num')::integer AS server_version,
            (SELECT count(*) FROM public.migrations)::integer AS migrations,
            (SELECT count(*) FROM public.companies)::integer AS companies,
            (SELECT count(*) FROM public.company_user)::integer AS memberships,
            (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='DRAFT')::integer AS drafts,
            (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='ACTIVE')::integer AS active,
            (SELECT count(*) FROM public.accounting_destination_routes)::integer AS routes,
            (SELECT count(*) FROM public.journal_entries)::integer AS entries,
            (SELECT count(*) FROM public.journal_entry_lines)::integer AS lines,
            (SELECT count(*) FROM public.journal_analytic_allocations)::integer AS allocations,
            (SELECT count(*) FROM public.fiscal_periods)::integer AS periods,
            (SELECT count(*) FROM public.exchange_rates)::integer AS rates,
            (SELECT count(*) FROM public.audit_logs)::integer AS audits
        SQL);
    if(!$state||$state->actor!=='neondb_owner'||$state->db!=='neondb'
        ||intdiv((int)$state->server_version,10000)!==18
        ||!in_array((int)$state->migrations,[21,22],true)||(int)$state->companies!==3
        ||(int)$state->memberships!==8||(int)$state->drafts!==3
        ||(int)$state->active!==0||(int)$state->routes!==1362
        ||(int)$state->entries!==0||(int)$state->lines!==0
        ||(int)$state->allocations!==0||(int)$state->periods!==0
        ||(int)$state->rates!==0||(int)$state->audits!==0){
        throw new RuntimeException('Baseline inesperado; solo DEMO virgen con 3 matrices DRAFT.');
    }
    $co=$db->select('SELECT id,tax_id FROM public.companies ORDER BY tax_id');
    if(array_column($co,'tax_id')!==['20999990011','20999990012','20999990013']){
        throw new RuntimeException('Empresas demo no coinciden.');
    }
    $A=(int)$co[0]->id;
    $fixtures=$db->select(<<<'SQL'
        SELECT r.rule_version_id,r.source_account_id,r.cost_center_id,
            cc.code AS center_code,src.code AS src_code,
            debit.code AS debit_code,credit.code AS credit_code
        FROM public.accounting_destination_routes r
        JOIN public.accounting_destination_rule_versions v
          ON v.company_id=r.company_id AND v.id=r.rule_version_id
          AND v.status='DRAFT' AND v.version_key='PCGE_2019_CC_V2'
        JOIN public.cost_centers cc
          ON cc.company_id=r.company_id AND cc.id=r.cost_center_id
          AND cc.is_active AND cc.code IN ('CC-ADM','CC-VTA')
        JOIN public.accounting_accounts src
          ON src.company_id=r.company_id AND src.id=r.source_account_id
          AND src.account_type='EXPENSE' AND src.is_operational
          AND src.is_active AND src.has_automatic_destination AND src.requires_cost_center
        JOIN public.accounting_accounts debit
          ON debit.company_id=r.company_id AND debit.id=r.destination_debit_account_id
        JOIN public.accounting_accounts credit
          ON credit.company_id=r.company_id AND credit.id=r.destination_credit_account_id
        WHERE r.company_id=? AND length(src.code)=6 AND left(src.code,1)='6'
        ORDER BY cc.code,src.code
        SQL,[$A]);
    $routes=[];
    foreach($fixtures as $row){$routes[$row->center_code]??=$row;}
    if(count($routes)!==2||$routes['CC-ADM']->debit_code!=='941100'
        ||$routes['CC-VTA']->debit_code!=='951100'
        ||$routes['CC-ADM']->credit_code!=='791000'
        ||$routes['CC-VTA']->credit_code!=='791000'
        ||(int)$routes['CC-ADM']->rule_version_id!==(int)$routes['CC-VTA']->rule_version_id){
        throw new RuntimeException('Matriz contable demo sin rutas de administración/ventas.');
    }
    $version=(int)$routes['CC-ADM']->rule_version_id;
    $offset=$db->selectOne(<<<'SQL'
        SELECT id FROM public.accounting_accounts WHERE company_id=?
            AND is_operational AND is_active AND length(code)=6
            AND code LIKE '4%' AND NOT requires_cost_center
        ORDER BY code LIMIT 1
        SQL,[$A]);
    $dest94=$db->selectOne("SELECT id FROM public.accounting_accounts WHERE company_id=? AND code='941100'",[$A]);
    if(!$offset||!$dest94)throw new RuntimeException('Faltan cuentas operativas demo.');
    $db->update(
        "UPDATE public.accounting_destination_rule_versions SET status='ACTIVE' WHERE company_id=? AND id=? AND status='DRAFT'",
        [$A,$version]
    );
    $activeCount=(int)$db->table('accounting_destination_rule_versions')
        ->where('company_id',$A)->where('status','ACTIVE')->count();
    if($activeCount!==1)throw new RuntimeException('No se activó una sola versión reversible.');
    echo "[PASS] Matriz A ACTIVE solo en transaccion OWNER sin COMMIT.\n";

    $user=User::query()->findOrFail(3000000000201);
    \Laravel\Sanctum\Sanctum::actingAs($user,['*'],'sanctum');
    $http=static function(string $method,string $uri,array $payload)use($kernel,$A):array{
        $req=\Illuminate\Http\Request::create($uri,$method,[],[],[],[
            'HTTP_ACCEPT'=>'application/json',
            'CONTENT_TYPE'=>'application/json',
            'HTTP_X_COMPANY_ID'=>(string)$A,
        ],json_encode($payload,JSON_THROW_ON_ERROR));
        $res=$kernel->handle($req);
        return [$res->getStatusCode(),json_decode((string)$res->getContent(),true,512,JSON_THROW_ON_ERROR)];
    };
    $assert=static function(bool $condition,string $message):void{
        if(!$condition)throw new RuntimeException($message);
    };
    $payload=static function(object $route,string $number,bool $sourceCredit=false)use($offset):array{
        $line=[
            'accounting_account_id'=>(int)$route->source_account_id,
            'cost_center_id'=>(int)$route->cost_center_id,
            'debit_local'=>$sourceCredit?'0.0000':'150.0000',
            'credit_local'=>$sourceCredit?'150.0000':'0.0000',
            'debit_foreign'=>$sourceCredit?'0.0000':'40.0000',
            'credit_foreign'=>$sourceCredit?'40.0000':'0.0000',
        ];
        return [
            'entry_date'=>'2026-10-22','entry_number'=>$number,
            'entry_type'=>$sourceCredit?'ADJUSTMENT':'STANDARD',
            'currency'=>'USD','exchange_rate'=>'3.750000',
            'exchange_rate_type'=>'SELL',
            'exchange_rates'=>[['currency'=>'USD','buy_rate'=>'3.720000',
                'sell_rate'=>'3.750000','rate'=>'3.750000']],
            'description'=>'F2.3e ACTIVE HTTP rollback only',
            'lines'=>[
                $line,
                [
                    'accounting_account_id'=>(int)$offset->id,
                    'cost_center_id'=>null,
                    'debit_local'=>$line['credit_local'],
                    'credit_local'=>$line['debit_local'],
                    'debit_foreign'=>$line['credit_foreign'],
                    'credit_foreign'=>$line['debit_foreign'],
                ],
            ],
        ];
    };

    $entries=[];
    foreach(['CC-ADM','CC-VTA'] as $center){
        [$status,$body]=$http('POST','/api/v1/accounting/journal-entries',
            $payload($routes[$center],'F23E-'.$center));
        $assert($status===201,'HTTP ACTIVE POST '.$center.' esperado 201, obtuvo '.$status);
        $entry=$body['data']??[];
        $lines=$entry['lines']??[];
        $assert(count($lines)===4,'HTTP ACTIVE '.$center.' no creó 4 líneas.');
        $codes=[];
        $db->selectOne("SELECT set_config('app.current_company_id',?,true)",[(string)$A]);
        foreach($lines as $line){
            $codes[]=$db->table('accounting_accounts')->where('id',$line['accounting_account_id'])->value('code');
        }
        $assert($codes[0]===$routes[$center]->src_code
            &&$codes[2]===$routes[$center]->debit_code
            &&$codes[3]==='791000','Destino incorrecto de '.$center);
        $assert((string)$lines[2]['debit_local']==='150.0000'
            &&(string)$lines[2]['debit_foreign']==='40.0000'
            &&(string)$lines[3]['credit_local']==='150.0000'
            &&(string)$lines[3]['credit_foreign']==='40.0000',
            'Montos analiticos bimoneda distintos.');
        $link=$db->table('journal_analytic_allocations')
            ->where('journal_entry_id',(int)$entry['id'])->sole();
        $assert((int)$link->source_line_id===(int)$lines[0]['id']
            &&(int)$link->debit_line_id===(int)$lines[2]['id']
            &&(int)$link->credit_line_id===(int)$lines[3]['id']
            &&(int)$link->rule_version_id===$version,
            'Linkage 6xx/94/95/79 no coincide.');
        $entries[$center]=$entry;
        echo "[PASS] HTTP 201 ACTIVE ".$center.": 4 líneas, ".$codes[2]."/791000, montos y linkage bimoneda.\n";
        $db->selectOne("SELECT set_config('app.current_company_id','',true)");
    }

    [$creditStatus,$creditBody]=$http('POST','/api/v1/accounting/journal-entries',
        $payload($routes['CC-VTA'],'F23E-CREDIT',true));
    $creditLines=$creditBody['data']['lines']??[];
    $assert($creditStatus===201&&count($creditLines)===4,
        'HTTP ajuste Haber 6xx esperado 201 con 4 líneas.');
    $db->selectOne("SELECT set_config('app.current_company_id',?,true)",[(string)$A]);
    $debitCode=$db->table('accounting_accounts')->where('id',$creditLines[2]['accounting_account_id'])->value('code');
    $creditCode=$db->table('accounting_accounts')->where('id',$creditLines[3]['accounting_account_id'])->value('code');
    $assert($debitCode==='791000'&&$creditCode==='951100',
        'Ajuste Haber 6 no invierte destino analitico.');
    echo "[PASS] HTTP ADJUSTMENT Haber 6xx: Debe 791000 / Haber 951100.\n";
    $db->selectOne("SELECT set_config('app.current_company_id','',true)");

    $bad=$payload($routes['CC-ADM'],'F23E-MIXED');
    $bad['lines'][1]['accounting_account_id']=(int)$dest94->id;
    [$badStatus]=$http('POST','/api/v1/accounting/journal-entries',$bad);
    $assert($badStatus===422,'Mezcla manual 94 con distribución automática no fue rechazada 422.');
    echo "[PASS] HTTP 422 bloquea mezclar destino manual 94/95/79 con origen automatico.\n";

    $original=$entries['CC-ADM'];
    [$reversalStatus,$reverseBody]=$http('POST',
        '/api/v1/accounting/journal-entries/'.$original['id'].'/reverse',[
            'fiscal_period_id'=>(int)$original['fiscal_period_id'],
            'reversal_date'=>'2026-10-22',
            'entry_number'=>'F23E-REVERSE',
            'reason'=>'Extorno analitico bimoneda en rollback',
        ]);
    $reverse=$reverseBody['data']??[];
    $revLines=$reverse['lines']??[];
    $assert($reversalStatus===201&&count($revLines)===4,
        'HTTP extorno ACTIVE esperado 201 con 4 partidas.');
    foreach($original['lines'] as $i=>$line){
        $inverted=$revLines[$i];
        foreach(['local','foreign'] as $currency){
            $assert((string)$inverted['debit_'.$currency]===(string)$line['credit_'.$currency]
                &&(string)$inverted['credit_'.$currency]===(string)$line['debit_'.$currency],
                'Extorno HTTP no invirtio importes originales.');
        }
    }
    $db->selectOne("SELECT set_config('app.current_company_id',?,true)",[(string)$A]);
    $originalLink=$db->table('journal_analytic_allocations')
        ->where('journal_entry_id',(int)$original['id'])->sole();
    $reversalLink=$db->table('journal_analytic_allocations')
        ->where('journal_entry_id',(int)$reverse['id'])->sole();
    $assert((int)$reversalLink->source_line_id===(int)$revLines[0]['id']
        &&(int)$reversalLink->debit_line_id===(int)$revLines[3]['id']
        &&(int)$reversalLink->credit_line_id===(int)$revLines[2]['id']
        &&(int)$reversalLink->rule_version_id===(int)$originalLink->rule_version_id,
        'Extorno HTTP no preserva trazabilidad versionada invertida.');
    $db->statement('SET CONSTRAINTS ALL IMMEDIATE');
    $t=$db->select(<<<'SQL'
        SELECT journal_entry_id,sum(debit_local)-sum(credit_local) AS local_diff,
            sum(debit_foreign)-sum(credit_foreign) AS foreign_diff
        FROM public.journal_entry_lines WHERE company_id=? GROUP BY journal_entry_id
        SQL,[$A]);
    $assert(count($t)===4,'Se esperaban 4 vouchers: CC-ADM, CC-VTA, ajuste y extorno.');
    foreach($t as $row){
        $assert((string)$row->local_diff==='0.0000'
            &&(string)$row->foreign_diff==='0.0000','Voucher ACTIVE desbalanceado.');
    }
    $audit=$db->selectOne(<<<'SQL'
        SELECT (SELECT count(*) FROM public.audit_logs
            WHERE company_id=? AND event_type='JOURNAL_ENTRY_CREATED') AS creations,
          (SELECT count(*) FROM public.audit_logs
            WHERE company_id=? AND event_type='JOURNAL_ENTRY_REVERSED') AS reversals,
          (SELECT count(*) FROM public.journal_analytic_allocations
            WHERE company_id=?) AS links
        SQL,[$A,$A,$A]);
    $assert((int)$audit->creations===3&&(int)$audit->reversals===1&&(int)$audit->links===4,
        'Trazabilidad de auditoria HTTP ACTIVE incorrecta.');
    echo "[PASS] HTTP 201 extorno analitico exacto; 4 links, 4 vouchers balanceados y auditoria.\n";
    $passed=true;
}catch(\Throwable $e){
    fwrite(STDERR,"[FAIL] F2.3e HTTP ACTIVE: ".substr($e->getMessage(),0,450)."\n");
}finally{
    if($inTransaction){
        try{$db->rollBack();echo "[ROLLBACK] ACTIVE transitorio y todas las transacciones HTTP revertidas.\n";}
        catch(\Throwable){$passed=false;fwrite(STDERR,"[BLOQUEO] No se pudo revertir la transaccion OWNER.\n");}
    }
    $manager->setDefaultConnection($oldDefault);
    $db->disconnect();
}
if($passed){
    try{
        $after=DB::connection('pgsql_migrations')->selectOne(<<<'SQL'
            SELECT (SELECT count(*) FROM public.journal_entries)::integer AS entries,
              (SELECT count(*) FROM public.journal_entry_lines)::integer AS lines,
              (SELECT count(*) FROM public.journal_analytic_allocations)::integer AS links,
              (SELECT count(*) FROM public.fiscal_periods)::integer AS periods,
              (SELECT count(*) FROM public.exchange_rates)::integer AS rates,
              (SELECT count(*) FROM public.audit_logs)::integer AS audits,
              (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='DRAFT')::integer AS drafts,
              (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='ACTIVE')::integer AS active
            SQL);
        $passed=$after&&(int)$after->entries===0&&(int)$after->lines===0
            &&(int)$after->links===0&&(int)$after->periods===0
            &&(int)$after->rates===0&&(int)$after->audits===0
            &&(int)$after->drafts===3&&(int)$after->active===0;
        echo $passed
            ? "[PASS] POST-ROLLBACK: 0 asientos/partidas/links/periodos/TC/auditoria; 3 DRAFT, 0 ACTIVE.\n"
            : "[BLOQUEO] Baseline POST-ROLLBACK no restaurado.\n";
    }catch(\Throwable){$passed=false;fwrite(STDERR,"[BLOQUEO] No se pudo verificar baseline despues del rollback.\n");}
}
exit($passed?0:1);
