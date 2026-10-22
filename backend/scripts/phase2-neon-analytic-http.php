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
if ($argc!==2||!in_array($mode,['--plan','--probe=contabilidad-dev'],true)) {
    fwrite(STDERR,"[FAIL] Uso: --plan|--probe=contabilidad-dev\n");exit(2);
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
        ||(int)$pre->migrations!==21||(int)$pre->companies!==3
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
    $db->beginTransaction();$inTransaction=true;
    $db->selectOne("SELECT set_config('statement_timeout','20000',true)");
    $who=$db->selectOne('SELECT current_user AS actor');
    if(!$who||$who->actor!=='app_backend')throw new RuntimeException('No se conecto con app_backend.');

    $adminUser=User::query()->findOrFail(3000000000201);
    $auditorUser=User::query()->findOrFail(3000000000203);
    \Laravel\Sanctum\Sanctum::actingAs($adminUser,['*'],'sanctum');

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
    $foreignCenter=$db->selectOne(
        'SELECT id FROM public.cost_centers WHERE company_id=? ORDER BY id LIMIT 1',
        [$companyB]);
    if(!$route||!$offset||!$foreignCenter)throw new RuntimeException('Faltan fixtures contables demo.');

    $request=static function(string $method,string $path,int $companyId,?array $payload=null) use($kernel):\Symfony\Component\HttpFoundation\Response {
        $headers=[
            'HTTP_ACCEPT'=>'application/json',
            'CONTENT_TYPE'=>'application/json',
            'HTTP_X_COMPANY_ID'=>(string)$companyId,
        ];
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
    $createdResponse=$request('POST','/api/v1/accounting/journal-entries',$companyA,$post);
    $created=json_decode((string)$createdResponse->getContent(),true,512,JSON_THROW_ON_ERROR);
    $check($createdResponse->getStatusCode()===201,
        'HTTP POST esperado 201: '.(string)$createdResponse->getStatusCode()
        .' '.substr((string)($created['message']??''),0,250));
    $entry=$created['data']??[];
    $entryId=(int)($entry['id']??0);
    $periodId=(int)($entry['fiscal_period_id']??0);
    $check($entryId>0&&$periodId>0&&count($entry['lines']??[])===2,
        'HTTP DRAFT debe devolver asiento con 2 lineas, sin automaticas.');

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
    $successful=true;
}catch(\Throwable $e){
    // Errors from integration assertions are safe to show, not SQL with secrets.
    $detail=(string)$e->getMessage();
    fwrite(STDERR,"[FAIL] F2.3d: ".substr($detail,0,500)."\n");
}finally{
    if($inTransaction){
        try{$db->rollBack();echo "[ROLLBACK] HTTP vouchers, auditoria, periodos y tipos de cambio revertidos.\n";}
        catch(\Throwable){$successful=false;fwrite(STDERR,"[BLOQUEO] ROLLBACK HTTP fallido.\n");}
    }
    $db->disconnect();
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
        $successful=$after&&(int)$after->entries===0&&(int)$after->lines===0
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
