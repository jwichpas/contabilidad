<?php

declare(strict_types=1);

/**
 * F2.3d: actual HTTP kernel + Sanctum + app_backend + demo tenants.
 * No ACTIVE rules: tests manual posting while destination matrix is DRAFT,
 * including request validation, audit, reversal and cross-company roles.
 * Outer transaction always ROLLBACK (no committed vouchers/periods/rates).
 */
$root=dirname(__DIR__);
$host='ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$mode=$argv[1]??'--plan';
$periodOnly=$mode==='--period-only=contabilidad-dev';
if ($argc!==2||!in_array($mode,['--plan','--probe=contabilidad-dev','--period-only=contabilidad-dev'],true)) {
    fwrite(STDERR,"[FAIL] Uso: --plan|--probe=contabilidad-dev|--period-only=contabilidad-dev\n");exit(2);
}
if ($mode==='--plan') {
    echo "[PLAN] F2.3d: HTTP Kernel Sanctum real; ADMIN/ACCOUNTANT; 201 POST+extorno, 422 errores, 403 rol ajeno.\n";
    echo "[PLAN] 2 monedas PEN/USD, centro de costos, GET tenant-local, auditoria, triggers diferidos.\n";
    echo "[PLAN] Solo matriz DRAFT: vouchers deben mantener 2 lineas, 0 imputaciones automaticas.\n";
    echo "[PLAN] Todas las inserciones quedan bajo transaccion app_backend y ROLLBACK.\n";
    echo "[DRY-RUN] Sin base de datos.\n";exit(0);
}
if (!is_file($root.'/.env')||is_file($root.'/bootstrap/cache/config.php')) {
    fwrite(STDERR,"[BLOQUEO] Falta .env o existe cache Laravel.\n");exit(1);
}
$seen=[];
foreach (file($root.'/.env',FILE_IGNORE_NEW_LINES)?:[] as $line) {
    if(!preg_match('/^[ \t]*(?:export[ \t]+)?([A-Z][A-Z0-9_]*)[ \t]*=/',$line,$m))continue;
    $key=$m[1];
    if(!str_starts_with($key,'DB_')&&!str_starts_with($key,'NEON_')
        &&!in_array($key,['APP_ENV','APP_KEY','DATABASE_URL'],true))continue;
    if(isset($seen[$key])){fwrite(STDERR,"[BLOQUEO] Clave .env duplicada.\n");exit(1);}
    $seen[$key]=true;
}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// This standalone HTTP harness boots the real Laravel kernel but must not
// depend on the operator's cache/session drivers, which may be empty or
// point to unavailable database-backed Laravel cache/session tables.
// Docker F2.3h passes with these same IN-MEMORY test-only drivers.
// Do not edit .env or persist any of these temporary overrides.
$previousProbeDrivers=[
    'cache.default'=>config('cache.default'),
    'session.driver'=>config('session.driver'),
    'queue.default'=>config('queue.default'),
];
config([
    'cache.default'=>'array',
    'session.driver'=>'array',
    'queue.default'=>'sync',
]);
$probePhase='configuration gate';
$admin=(array)config('database.connections.pgsql_migrations',[]);
$runtime=(array)config('database.connections.pgsql',[]);
$key=(string)env('APP_KEY','');
$keyBytes=str_starts_with($key,'base64:')?base64_decode(substr($key,7),true):$key;
$pass=(string)($runtime['password']??'');
$expected=(string)env('DB_APP_BACKEND_ROLE_PASSWORD','');
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
    ||$keyBytes===false||strlen($keyBytes)!==32
    ||strlen($expected)<20||$pass===''||!hash_equals($expected,$pass)) {
    fwrite(STDERR,"[BLOQUEO] Solo Neon PG18 DEV app_backend + SSL, credenciales coincidentes.\n");exit(1);
}
unset($key,$keyBytes,$pass,$expected);

use Illuminate\Support\Facades\DB;
use App\Models\User;
$owner=DB::connection('pgsql_migrations');
$db=DB::connection('pgsql');
$kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);
$inTransaction=false;$successful=false;
try{
    $probePhase='baseline owner SELECT';
    $pre=$owner->selectOne(<<<'SQL'
        SELECT current_database() AS database_name,
          current_setting('server_version_num')::integer AS version,
          (SELECT count(*) FROM public.migrations)::integer AS migrations,
          (SELECT count(*) FROM public.companies)::integer AS companies,
          (SELECT count(*) FROM public.journal_entries)::integer AS entries,
          (SELECT count(*) FROM public.journal_entry_lines)::integer AS lines,
          (SELECT count(*) FROM public.journal_analytic_allocations)::integer AS analytic,
          (SELECT count(*) FROM public.fiscal_periods)::integer AS periods,
          (SELECT count(*) FROM public.exchange_rates)::integer AS rates,
          (SELECT count(*) FROM public.audit_logs)::integer AS audit,
          (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='DRAFT')::integer AS drafts,
          (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='ACTIVE')::integer AS active
        SQL);
    if(!$pre||$pre->database_name!=='neondb'
        ||intdiv((int)$pre->version,10000)!==18
        ||!in_array((int)$pre->migrations,[21,22],true)||(int)$pre->companies!==3
        ||(int)$pre->entries!==0||(int)$pre->lines!==0||(int)$pre->analytic!==0
        ||(int)$pre->periods!==0||(int)$pre->rates!==0||(int)$pre->audit!==0
        ||(int)$pre->drafts!==3||(int)$pre->active!==0){
        throw new RuntimeException('Precondiciones demo de Neon incumplidas (21 migraciones, 0 movimientos, 3 DRAFT).');
    }
    $all=$owner->select('SELECT id,tax_id FROM public.companies ORDER BY tax_id');
    if(array_column($all,'tax_id')!==['20999990011','20999990012','20999990013']) {
        throw new RuntimeException('Las tres empresas ficticias no coinciden.');
    }
    $companyA=(int)$all[0]->id;$companyB=(int)$all[1]->id;
    $probePhase='app_backend transaction';
    $db->beginTransaction();$inTransaction=true;
    $db->selectOne("SELECT set_config('statement_timeout','20000',true)");
    $who=$db->selectOne('SELECT current_user AS actor');
    if(!$who||$who->actor!=='app_backend')throw new RuntimeException('No se conecto con app_backend.');

    $probePhase='Sanctum DEMO actors';
    $adminUser=User::query()->findOrFail(3000000000201);
    $auditorUser=User::query()->findOrFail(3000000000203);
    \Laravel\Sanctum\Sanctum::actingAs($adminUser,['*'],'sanctum');

    // app_backend is protected by FORCE RLS. Even fixture discovery must
    // happen under a tenant-local GUC (without it, every row is invisible).
    $db->selectOne("SELECT set_config('app.current_company_id',?,true)",[(string)$companyA]);
    $route=$db->selectOne(<<<'SQL'
        SELECT r.source_account_id,r.cost_center_id
        FROM public.accounting_destination_routes r
        JOIN public.accounting_accounts a
          ON a.company_id=r.company_id AND a.id=r.source_account_id
          AND a.is_operational AND a.is_active AND a.has_automatic_destination
          AND a.requires_cost_center AND a.account_type='EXPENSE'
        JOIN public.cost_centers cc
          ON cc.company_id=r.company_id AND cc.id=r.cost_center_id
          AND cc.code='CC-ADM' AND cc.is_active
        WHERE r.company_id=?
        ORDER BY a.code LIMIT 1
        SQL,[$companyA]);
    $offset=$db->selectOne(<<<'SQL'
        SELECT id FROM public.accounting_accounts
        WHERE company_id=? AND is_active AND is_operational
          AND length(code)=6 AND code LIKE '4%' AND NOT requires_cost_center
        ORDER BY code LIMIT 1
        SQL,[$companyA]);
    $db->selectOne("SELECT set_config('app.current_company_id',?,true)",[(string)$companyB]);
    $foreignCenter=$db->selectOne(
        'SELECT id FROM public.cost_centers WHERE company_id=? ORDER BY id LIMIT 1',
        [$companyB]);
    $db->selectOne("SELECT set_config('app.current_company_id','',true)");
    if(!$route||!$offset||!$foreignCenter)throw new RuntimeException('Faltan fixtures contables demo.');

    $request=static function(
        string $method,string $path,int $companyId,?array $payload=null,?string $key=null
    ) use($kernel):\Symfony\Component\HttpFoundation\Response {
        $headers=[
            'HTTP_ACCEPT'=>'application/json',
            'CONTENT_TYPE'=>'application/json',
            'HTTP_X_COMPANY_ID'=>(string)$companyId,
        ];
        if($key!==null)$headers['HTTP_IDEMPOTENCY_KEY']=$key;
        $req=\Illuminate\Http\Request::create(
            $path,$method,[],[],[],$headers,
            $payload!==null?json_encode($payload,JSON_THROW_ON_ERROR):null
        );
        return $kernel->handle($req);
    };
    $check=static function(bool $ok,string $error):void {
        if(!$ok)throw new RuntimeException($error);
    };
    $post=[
        'entry_date'=>'2026-10-22','entry_number'=>'F23D-HTTP-A',
        'entry_type'=>'STANDARD','currency'=>'USD','exchange_rate'=>'3.750000',
        'exchange_rate_type'=>'SELL','description'=>'F2.3d HTTP temporal',
        'exchange_rates'=>[
            ['currency'=>'USD','buy_rate'=>'3.720000','sell_rate'=>'3.750000','rate'=>'3.750000']
        ],
        'lines'=>[
            [
                'accounting_account_id'=>(int)$route->source_account_id,
                'cost_center_id'=>(int)$route->cost_center_id,
                'debit_local'=>'150.0000','credit_local'=>'0.0000',
                'debit_foreign'=>'40.0000','credit_foreign'=>'0.0000',
            ],
            [
                'accounting_account_id'=>(int)$offset->id,
                'cost_center_id'=>null,
                'debit_local'=>'0.0000','credit_local'=>'150.0000',
                'debit_foreign'=>'0.0000','credit_foreign'=>'40.0000',
            ],
        ],
    ];
    // Schema 21: original DRAFT POST test; schema 22: F2.2 Idempotency-Key
    // exercises real Neon app_backend, same transaction and ROLLBACK.
    $idempotencyKey=(int)$pre->migrations===22?'f22-neon-draft-lookup-key-001':null;
    $probePhase='HTTP journal POST with Sanctum';
    $createdResponse=$request('POST','/api/v1/accounting/journal-entries',$companyA,$post,$idempotencyKey);
    $created=json_decode((string)$createdResponse->getContent(),true,512,JSON_THROW_ON_ERROR);
    $check($createdResponse->getStatusCode()===201,
        'HTTP POST esperado 201: '.(string)$createdResponse->getStatusCode()
        .' '.substr((string)($created['message']??''),0,250));
    $entry=$created['data']??[];
    $entryId=(int)($entry['id']??0);
    $periodId=(int)($entry['fiscal_period_id']??0);
    $check($entryId>0&&$periodId>0&&count($entry['lines']??[])===2,
        'HTTP DRAFT debe devolver asiento con 2 lineas, sin automaticas.');

    // Faster focused repeat: retain only the initial guarded POST fixture,
    // skip earlier full F2.2/legacy F2.3d HTTP checks (already independently
    // passed) and execute the new F2.3 lifecycle cases below.
    if(!$periodOnly){
    if($idempotencyKey!==null){
        $again=$request('POST','/api/v1/accounting/journal-entries',$companyA,$post,$idempotencyKey);
        $againJson=json_decode((string)$again->getContent(),true,512,JSON_THROW_ON_ERROR);
        $check($again->getStatusCode()===200
            &&(int)($againJson['data']['id']??0)===$entryId
            &&$again->headers->get('Idempotency-Replayed')==='true',
            'HTTP Neon idempotencia no devolvio mismo asiento con 200.');
            $probePhase='HTTP idempotency conflict';
        $different=$post;
        $different['description']='F22 CLAVE REPETIDA CONTENIDO DISTINTO';
        $conflict=$request('POST','/api/v1/accounting/journal-entries',
            $companyA,$different,$idempotencyKey);
        $check($conflict->getStatusCode()===409,
            'HTTP Neon misma clave con diferente contenido no devolvio 409.');
        $db->selectOne("SELECT set_config('app.current_company_id',?,true)",[(string)$companyA]);
        $idem=$db->selectOne(<<<'SQL'
            SELECT (SELECT count(*) FROM public.journal_post_idempotency WHERE company_id=?) AS receipts,
              (SELECT count(*) FROM public.journal_entries WHERE company_id=?) AS entries,
              (SELECT count(*) FROM public.audit_logs WHERE company_id=? AND event_type='JOURNAL_ENTRY_CREATED') AS audits
            SQL,[$companyA,$companyA,$companyA]);
        $check((int)$idem->receipts===1&&(int)$idem->entries===1&&(int)$idem->audits===1,
            'F2.2 Neon duplico recibos, asientos o auditoria.');
        $db->selectOne("SELECT set_config('app.current_company_id','',true)");
        echo "[PASS] F2.2 Neon app_backend: HTTP 201/200/409, 1 recibo, 1 asiento, 1 auditoria.\n";
    }

    $db->selectOne("SELECT set_config('app.current_company_id',?,true)",[(string)$companyA]);
    $counts=$db->selectOne(<<<'SQL'
        SELECT (SELECT count(*) FROM public.journal_entries WHERE company_id=?) AS entries,
          (SELECT count(*) FROM public.journal_entry_lines WHERE journal_entry_id=?) AS lines,
          (SELECT count(*) FROM public.journal_analytic_allocations) AS links,
          (SELECT count(*) FROM public.audit_logs WHERE company_id=? AND event_type='JOURNAL_ENTRY_CREATED') AS audit,
          (SELECT count(*) FROM public.exchange_rates WHERE company_id=?) AS rates
        SQL,[$companyA,$entryId,$companyA,$companyA]);
    $check((int)$counts->entries===1&&(int)$counts->lines===2
        &&(int)$counts->links===0&&(int)$counts->audit===1&&(int)$counts->rates===1,
        'HTTP no creo voucher manual con auditoria y tipo de cambio.');
    echo "[PASS] HTTP 201 app_backend ADMIN: voucher USD/PEN, CC-ADM, 2 lineas DRAFT, auditoria y tipo de cambio.\n";
    $db->selectOne("SELECT set_config('app.current_company_id','',true)");

    $invalid=$post;$invalid['entry_number']='F23D-NO-CC';$invalid['lines'][0]['cost_center_id']=null;
    $r=$request('POST','/api/v1/accounting/journal-entries',$companyA,$invalid);
    $check($r->getStatusCode()===422,'HTTP 422 centro obligatorio no rechazado.');
    $invalid=$post;$invalid['entry_number']='F23D-CC-OTHER';
    $invalid['lines'][0]['cost_center_id']=(int)$foreignCenter->id;
    $r=$request('POST','/api/v1/accounting/journal-entries',$companyA,$invalid);
    $check($r->getStatusCode()===422,'HTTP 422 centro de otra empresa no rechazado.');
    $invalid=$post;$invalid['entry_number']='F23D-UNBALANCED';
    $invalid['lines'][1]['credit_local']='149.0000';
    $r=$request('POST','/api/v1/accounting/journal-entries',$companyA,$invalid);
    $check($r->getStatusCode()===422,'HTTP 422 partida descuadrada no rechazada.');
    echo "[PASS] HTTP 422 centro faltante/cruzado y partida doble incorrecta.\n";

    $listA=$request('GET','/api/v1/accounting/journal-entries',$companyA);
    $listB=$request('GET','/api/v1/accounting/journal-entries',$companyB);
    $rowsA=json_decode((string)$listA->getContent(),true,512,JSON_THROW_ON_ERROR);
    $rowsB=json_decode((string)$listB->getContent(),true,512,JSON_THROW_ON_ERROR);
    $check($listA->getStatusCode()===200&&count($rowsA['data']??[])===1
        &&$listB->getStatusCode()===200&&count($rowsB['data']??[])===0,
        'GET filtrado por empresa no respeta RLS.');
    echo "[PASS] HTTP GET diario: A ve asiento propio; B no ve asiento A.\n";

    \Laravel\Sanctum\Sanctum::actingAs($auditorUser,['*'],'sanctum');
    $forbidden=$request('POST','/api/v1/accounting/journal-entries',$companyB,$post);
    $check($forbidden->getStatusCode()===403,'Auditor B pudo crear asiento.');
    $nonMember=$request('GET','/api/v1/accounting/journal-entries',$companyA);
    $check($nonMember->getStatusCode()===403,'Auditor ajeno a A pudo listar A.');
    echo "[PASS] HTTP 403: auditor sin permiso POST y sin membresia A.\n";
    \Laravel\Sanctum\Sanctum::actingAs($adminUser,['*'],'sanctum');

    $probePhase='HTTP reverse and audit';
    $reversalResponse=$request(
        'POST','/api/v1/accounting/journal-entries/'.$entryId.'/reverse',
        $companyA,[
            'fiscal_period_id'=>$periodId,
            'reversal_date'=>'2026-10-22',
            'entry_number'=>'F23D-REV',
            'reason'=>'Extorno reversible HTTP',
        ]
    );
    $reversed=json_decode((string)$reversalResponse->getContent(),true,512,JSON_THROW_ON_ERROR);
    $check($reversalResponse->getStatusCode()===201
        &&count($reversed['data']['lines']??[])===2,
        'HTTP extorno no devolvio 201 y dos lineas.');
    $double=$request(
        'POST','/api/v1/accounting/journal-entries/'.$entryId.'/reverse',
        $companyA,[
            'fiscal_period_id'=>$periodId,
            'reversal_date'=>'2026-10-22',
            'entry_number'=>'F23D-REV-2',
            'reason'=>'Extorno repetido debe bloquearse',
        ]
    );
    $check($double->getStatusCode()===422,'HTTP doble extorno debe bloquearse con 422.');

    $db->selectOne("SELECT set_config('app.current_company_id',?,true)",[(string)$companyA]);
    $audit=$db->selectOne(<<<'SQL'
        SELECT (SELECT count(*) FROM public.journal_entries WHERE company_id=?) AS entries,
          (SELECT count(*) FROM public.journal_entry_lines WHERE company_id=?) AS lines,
          (SELECT count(*) FROM public.audit_logs WHERE company_id=? AND event_type='JOURNAL_ENTRY_REVERSED') AS reversals,
          (SELECT count(*) FROM public.journal_analytic_allocations) AS links
        SQL,[$companyA,$companyA,$companyA]);
    $check((int)$audit->entries===2&&(int)$audit->lines===4
        &&(int)$audit->reversals===1&&(int)$audit->links===0,
        'Auditoria/contabilidad extorno HTTP inconsistentes.');
    $db->statement('SET CONSTRAINTS ALL IMMEDIATE');
    echo "[PASS] HTTP 201 extorno + 422 doble extorno; auditoria y triggers bimoneda.\n";
    echo "[PASS] F2.3d: HTTP harness usa cache/session=array y cola sync solo en memoria.\n";
    }else{
        echo "[FOCUS] F2.3 periodo: se omitieron pruebas HTTP anteriores ya aprobadas.\n";
    }
    // Earlier check forced deferred balance triggers immediately; return to
    // deferred mode before posting additional F2.3 fixtures in this transaction.
    $db->statement('SET CONSTRAINTS ALL DEFERRED');

    // F2.3: all changes run under existing app_backend transaction (ROLLBACK).
    $probePhase='F2.3 HTTP fixture POST';
    $fixture=$post;$fixture['entry_number']='F23-REVERSAL-GUARD';
    $fixtureRes=$request('POST','/api/v1/accounting/journal-entries',$companyA,$fixture);
    $fixtureBody=json_decode((string)$fixtureRes->getContent(),true,512,JSON_THROW_ON_ERROR);
    $check($fixtureRes->getStatusCode()===201,
        'F2.3 no pudo crear fixture previa al cierre');
    $fixtureId=(int)($fixtureBody['data']['id']??0);
    $check($fixtureId>0,'F2.3 no obtuvo id fixture');
    $probePhase='F2.3 GET fiscal periods';
    $periodList=$request('GET','/api/v1/accounting/fiscal-periods?year=2026',$companyA);
    $periodListJson=json_decode((string)$periodList->getContent(),true,512,JSON_THROW_ON_ERROR);
    $check($periodList->getStatusCode()===200
        &&count($periodListJson['data']??[])===1
        &&($periodListJson['data'][0]['status']??'')==='OPEN'
        &&(int)($periodListJson['data'][0]['entry_count']??0)>=2,
        'F2.3 GET period must include OPEN and real journal count');
    $probePhase='F2.3 month creation validation';
    $bad=$request('POST','/api/v1/accounting/fiscal-periods',$companyA,[
        'year'=>2026,'month'=>11,'reason'=>'corto'
    ]);
    $check($bad->getStatusCode()===422,'Opening must validate reason');

    $probePhase='F2.3 open new period';
    $opening=['year'=>2026,'month'=>11,
        'reason'=>'Apertura contable para ensayo transaccional reversible'];
    $result=$request('POST','/api/v1/accounting/fiscal-periods',$companyA,$opening);
    $body=json_decode((string)$result->getContent(),true,512,JSON_THROW_ON_ERROR);
    $check($result->getStatusCode()===201
        &&($body['data']['status']??'')==='OPEN',
        'New monthly period must return 201 OPEN');

    $probePhase='F2.3 duplicate opening guard';
    $repeated=$request('POST','/api/v1/accounting/fiscal-periods',$companyA,$opening);
    $check($repeated->getStatusCode()===409,
        'Duplicate period opening must return 409');
    $probePhase='F2.3 list periods after opening';
    $listResult=$request('GET','/api/v1/accounting/fiscal-periods?year=2026',$companyA);
    $listBody=json_decode((string)$listResult->getContent(),true,512,JSON_THROW_ON_ERROR);
    $check($listResult->getStatusCode()===200
        &&count($listBody['data']??[])===2
        &&(int)($listBody['data'][1]['month']??0)===11
        &&(int)($listBody['data'][1]['entry_count']??-1)===0,
        'The new OPEN month must appear without vouchers');
    $probePhase='F2.3 list periods company B';
    $otherPeriods=$request('GET','/api/v1/accounting/fiscal-periods?year=2026',$companyB);
    $otherBody=json_decode((string)$otherPeriods->getContent(),true,512,JSON_THROW_ON_ERROR);
    $check($otherPeriods->getStatusCode()===200
        &&count($otherBody['data']??[])===0,
        'An unrelated company must not see other accounting periods');
    $probePhase='F2.3 role check for opening';
    \Laravel\Sanctum\Sanctum::actingAs($auditorUser,['*'],'sanctum');
    $deniedOpen=$request('POST','/api/v1/accounting/fiscal-periods',$companyA,$opening);
    $check($deniedOpen->getStatusCode()===403,
        'Opening must be restricted to an authorized company role');
    \Laravel\Sanctum\Sanctum::actingAs($adminUser,['*'],'sanctum');
    $periodUri='/api/v1/accounting/fiscal-periods/'.$periodId;
    $reason=['reason'=>'Cierre contable manual de prueba reversible F2.3'];

    $probePhase='F2.3 HTTP close OPEN->CLOSED';
    $close=$request('POST',$periodUri.'/close',$companyA,$reason);
    $closeBody=json_decode((string)$close->getContent(),true,512,JSON_THROW_ON_ERROR);
    $check($close->getStatusCode()===200
        &&($closeBody['data']['status']??'')==='CLOSED',
        'F2.3 cierre OPEN->CLOSED debe ser 200');
    $probePhase='F2.3 HTTP repeated close';
    $closeAgain=$request('POST',$periodUri.'/close',$companyA,$reason);
    $check($closeAgain->getStatusCode()===409,'F2.3 cierre duplicado debe ser 409');

    $blocked=$post;$blocked['entry_number']='F23-CLOSED-BLOCKED';
    $probePhase='F2.3 HTTP POST rejected CLOSED';
    $closedPost=$request('POST','/api/v1/accounting/journal-entries',$companyA,$blocked);
    $check($closedPost->getStatusCode()===422,'F2.3 POST en CLOSED debe ser 422');
    $probePhase='F2.3 HTTP reverse rejected CLOSED';
    $closedReverse=$request('POST','/api/v1/accounting/journal-entries/'.$fixtureId.'/reverse',
        $companyA,['fiscal_period_id'=>$periodId,
          'reversal_date'=>'2026-10-22',
          'reason'=>'Extorno prohibido en periodo cerrado']);
    $check($closedReverse->getStatusCode()===422,
        'F2.3 extorno con destino CLOSED debe ser 422');

    \Laravel\Sanctum\Sanctum::actingAs($auditorUser,['*'],'sanctum');
    $probePhase='F2.3 HTTP auditor 403';
    $auditorDenied=$request('POST',$periodUri.'/reopen',$companyA,[
        'reason'=>'Reapertura por rol auditor no autorizado']);
    $check($auditorDenied->getStatusCode()===403,'F2.3 auditor debe recibir 403');
    \Laravel\Sanctum\Sanctum::actingAs($adminUser,['*'],'sanctum');

    $probePhase='F2.3 HTTP reopen CLOSED->OPEN';
    $reopen=$request('POST',$periodUri.'/reopen',$companyA,[
        'reason'=>'Reapertura auditada para ajuste contable de prueba']);
    $reopenBody=json_decode((string)$reopen->getContent(),true,512,JSON_THROW_ON_ERROR);
    $check($reopen->getStatusCode()===200
        &&($reopenBody['data']['status']??'')==='OPEN',
        'F2.3 CLOSED->OPEN debe responder 200');
    $allowed=$post;$allowed['entry_number']='F23-REOPENED-OK';
    $probePhase='F2.3 HTTP POST after reopen';
    $reopenedPost=$request('POST','/api/v1/accounting/journal-entries',$companyA,$allowed);
    $check($reopenedPost->getStatusCode()===201,
        'F2.3 no admite POST tras reapertura');

    $probePhase='F2.3 HTTP reclose after reopen';
    $closed=$request('POST',$periodUri.'/close',$companyA,$reason);
    $check($closed->getStatusCode()===200,'F2.3 segundo cierre fallido');
    $probePhase='F2.3 HTTP lock CLOSED->LOCKED';
    $lock=$request('POST',$periodUri.'/lock',$companyA,[
        'reason'=>'Bloqueo definitivo para impedir reapertura ordinaria']);
    $lockBody=json_decode((string)$lock->getContent(),true,512,JSON_THROW_ON_ERROR);
    $check($lock->getStatusCode()===200
        &&($lockBody['data']['status']??'')==='LOCKED',
        'F2.3 CLOSED->LOCKED debe ser 200');
    $probePhase='F2.3 HTTP reject reopen LOCKED';
    $reopenDenied=$request('POST',$periodUri.'/reopen',$companyA,[
        'reason'=>'Intento de reabrir un periodo bloqueado']);
    $check($reopenDenied->getStatusCode()===409,
        'F2.3 LOCKED no debe reabrir sin autorización extraordinaria');
    $lockedPost=$post;$lockedPost['entry_number']='F23-LOCKED-BLOCKED';
    $probePhase='F2.3 HTTP reject POST LOCKED';
    $r=$request('POST','/api/v1/accounting/journal-entries',$companyA,$lockedPost);
    $check($r->getStatusCode()===422,'F2.3 POST en LOCKED debe ser 422');

    $db->selectOne("SELECT set_config('app.current_company_id',?,true)",[(string)$companyA]);
    $probePhase='F2.3 verify 4 audit events';
    $periodEvents=$db->selectOne(<<<'SQL'
      SELECT COUNT(*) FILTER (WHERE event_type='FISCAL_PERIOD_CLOSED') AS c,
        COUNT(*) FILTER (WHERE event_type='FISCAL_PERIOD_REOPENED') AS r,
        COUNT(*) FILTER (WHERE event_type='FISCAL_PERIOD_LOCKED') AS l
      FROM public.audit_logs WHERE company_id=?
      SQL,[$companyA]);
    $check((int)$periodEvents->c===2&&(int)$periodEvents->r===1
        &&(int)$periodEvents->l===1,'F2.3 auditoria atomica de cambios incompleta');
    // Verify the deferred balance trigger while company A remains visible
    // through FORCE RLS. Clearing the GUC first yields false missing lines.
    $probePhase='F2.3 verify tenant visibility';
    $scope=$db->selectOne(
        "SELECT current_user AS actor, current_setting('app.current_company_id', true) AS tenant_id"
    );
    $check($scope!==null && $scope->actor==='app_backend'
        && (string)$scope->tenant_id===(string)$companyA,
        'F2.3: se perdió el contexto RLS antes de forzar triggers de partida doble');
    $probePhase='F2.3 verify deferred balance triggers';
    $db->statement('SET CONSTRAINTS ALL IMMEDIATE');
    $db->selectOne("SELECT set_config('app.current_company_id','',true)");
    echo "[PASS] F2.3 Neon HTTP: CLOSE, REOPEN, LOCK, 403 RBAC, 409 transiciones, 422 POST/extorno bloqueados y 4 auditorias.\n";
    $successful=true;
}catch(\Throwable $e){
    // Errors from integration assertions are safe to show, not SQL with secrets.
    // Phased error labels, not raw SQL or full environment values.
    $detail=$e instanceof \Illuminate\Database\QueryException
        ?'SQLSTATE='.($e->errorInfo[0]??$e->getCode())
        :substr((string)$e->getMessage(),0,240);
    // Report only known public, fixed PostgreSQL exception/constraint labels.
    // Do not print SQL text, database values or raw PDO error messages.
    if($e instanceof \Illuminate\Database\QueryException){
        $dbError=(string)($e->errorInfo[2]??'');
        if(preg_match('/\\b(?:IMMUTABLE_JOURNAL_ENTRY|CLOSED_FISCAL_PERIOD|'
          .'INSUFFICIENT_JOURNAL_LINES|UNBALANCED_JOURNAL_ENTRY|ZERO_JOURNAL_ENTRY|'
          .'NON_OPERATIONAL_ACCOUNT|chk_fiscal_periods_status)\\b/', $dbError,$safe)){
            $detail.=' constraint='.$safe[0];
        }
    }
    fwrite(STDERR,"[FAIL] F2.3d fase=".$probePhase.": ".$detail."\n");
}finally{
    if($inTransaction){
        try{$db->rollBack();echo "[ROLLBACK] HTTP vouchers, auditoria, periodos y tipos de cambio revertidos.\n";}
        catch(\Throwable){$successful=false;fwrite(STDERR,"[BLOQUEO] ROLLBACK HTTP fallido.\n");}
    }
    $db->disconnect();
    // Only PHP memory was changed; ensure the harness restores the defaults
    // when the probe finishes, whether it passes or fails.
    config($previousProbeDrivers);
}
if($successful){
    try{
        $after=$owner->selectOne(<<<'SQL'
            SELECT
              (SELECT count(*) FROM public.journal_entries)::integer AS entries,
              (SELECT count(*) FROM public.journal_entry_lines)::integer AS lines,
              (SELECT count(*) FROM public.journal_analytic_allocations)::integer AS allocations,
              (SELECT count(*) FROM public.fiscal_periods)::integer AS periods,
              (SELECT count(*) FROM public.exchange_rates)::integer AS rates,
              (SELECT count(*) FROM public.audit_logs)::integer AS audits,
              (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='DRAFT')::integer AS drafts,
              (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='ACTIVE')::integer AS active
          SQL);
        $receipts=(int)$pre->migrations===22
            ? (int)$owner->selectOne('SELECT count(*) AS n FROM public.journal_post_idempotency')->n
            : 0;
        $successful=$receipts===0 && $after&&(int)$after->entries===0&&(int)$after->lines===0
            &&(int)$after->allocations===0&&(int)$after->periods===0
            &&(int)$after->rates===0&&(int)$after->audits===0
            &&(int)$after->drafts===3&&(int)$after->active===0;
        echo $successful
            ? "[PASS] HTTP POST-ROLLBACK: 0 asientos/lineas/links/periodos/TC/auditoria, 3 DRAFT.\n"
            : "[BLOQUEO] HTTP POST-ROLLBACK no coincide con baseline.\n";
    }catch(\Throwable){$successful=false;fwrite(STDERR,"[BLOQUEO] Verificacion HTTP post-rollback fallida.\n");}
}
$owner->disconnect();
exit($successful?0:1);
