<?php

declare(strict_types=1);

// F3.1: reversible HTTP API test; all DEMO company creation is rolled back.
$mode=$argv[1]??'--plan';
if($argc!==2||!in_array($mode,['--plan','--probe=contabilidad-dev'],true)){
    fwrite(STDERR,"[USO] --plan | --probe=contabilidad-dev\n");exit(2);
}
if($mode==='--plan'){
    echo "[PLAN] HTTP F3.1: RUC ficticio y PCGE 2019 en transaccion app_backend.\n";
    echo "[PLAN] Comprueba empresa, ADMIN, monedas, CIIU, areas, PCGE, auditoria y ROLLBACK.\n";
    echo "[PLAN] No deja empresas ni registros nuevos en Neon DEV.\n";exit(0);
}
$root=dirname(__DIR__);
if(!is_file($root.'/.env')||is_file($root.'/bootstrap/cache/config.php')
    ||!is_file($root.'/vendor/autoload.php')){
    fwrite(STDERR,"[BLOQUEO] Laravel .env y dependencias requeridos, sin config cache.\n");exit(1);
}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$kernel=$app->make(\Illuminate\Contracts\Http\Kernel::class);
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$host='ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$a=(array)config('database.connections.pgsql',[]);
$o=(array)config('database.connections.pgsql_migrations',[]);
$safe=static fn(array $c,string $role):bool =>
    ($c['host']??null)===$host&&(string)($c['port']??'')==='5432'
    &&($c['database']??null)==='neondb'&&($c['username']??null)===$role
    &&trim((string)($c['url']??''))===''
    &&in_array((string)($c['sslmode']??''),['require','verify-ca','verify-full'],true);
if(!app()->environment('local')||config('database.default')!=='pgsql'
    ||env('NEON_TEST_BRANCH_NAME')!=='contabilidad-dev'
    ||env('NEON_TEST_BRANCH_HOST')!==$host
    ||!$safe($a,'app_backend')||!$safe($o,'neondb_owner')
    ||filter_var(env('NEON_USE_POOLING',false),FILTER_VALIDATE_BOOLEAN)){
    fwrite(STDERR,"[BLOQUEO] Requiere Neon DEV PG18 con roles APP/ADMIN y SSL.\n");exit(1);
}
$owner=\Illuminate\Support\Facades\DB::connection('pgsql_migrations');
$db=\Illuminate\Support\Facades\DB::connection('pgsql');
$oldDrivers=[
    'cache.default'=>config('cache.default'),
    'session.driver'=>config('session.driver'),
    'queue.default'=>config('queue.default'),
];
config(['cache.default'=>'array','session.driver'=>'array','queue.default'=>'sync']);
$started=false;$passed=false;$phase='preflight';
try{
    $pre=$owner->selectOne(<<<'SQL'
       SELECT current_database() AS db,current_user AS actor,
         current_setting('server_version_num')::integer AS pg,
         (SELECT count(*) FROM public.migrations)::integer AS migrations,
         (SELECT count(*) FROM public.companies)::integer AS companies,
         (SELECT count(*) FROM public.users)::integer AS users,
         (SELECT count(*) FROM public.company_user)::integer AS members,
         (SELECT count(*) FROM public.fiscal_periods WHERE year=2026 AND month=1 AND status='OPEN')::integer AS january,
         (SELECT count(*) FROM public.journal_entries)::integer AS journals,
         (SELECT count(*) FROM public.audit_logs)::integer AS audits
       SQL);
    $role=$db->selectOne('SELECT current_user AS actor');
    if(!$pre||$pre->db!=='neondb'||$pre->actor!=='neondb_owner'
        ||intdiv((int)$pre->pg,10000)!==18||$role?->actor!=='app_backend'
        ||(int)$pre->migrations!==24||(int)$pre->companies!==3
        ||(int)$pre->users!==3||(int)$pre->members!==8
        ||(int)$pre->january!==1||(int)$pre->journals!==0){
        throw new \RuntimeException('Baseline Neon F3.1 no coincide');
    }
    $companies=$owner->select('SELECT id,tax_id FROM public.companies ORDER BY tax_id');
    if(array_column($companies,'tax_id')!==['20999990011','20999990012','20999990013']){
        throw new \RuntimeException('Solo empresas DEMO autorizadas');
    }
    $aId=(int)$companies[0]->id;
    $db->beginTransaction();$started=true;
    $db->selectOne("SELECT set_config('statement_timeout','30000',true)");
    $admin=\App\Models\User::query()->findOrFail(3000000000201);
    \Laravel\Sanctum\Sanctum::actingAs($admin,['*'],'sanctum');
    $http=static function(string $method,string $url,?array $payload=null)use($kernel,$aId):\Symfony\Component\HttpFoundation\Response{
        return $kernel->handle(\Illuminate\Http\Request::create(
            $url,$method,[],[],[],[
                'HTTP_ACCEPT'=>'application/json',
                'CONTENT_TYPE'=>'application/json',
                'HTTP_X_COMPANY_ID'=>(string)$aId,
            ],$payload===null?null:json_encode($payload,JSON_THROW_ON_ERROR)
        ));
    };
    $check=static function(bool $pass,string $error):void{
        if(!$pass)throw new \RuntimeException($error);
    };
    $phase='GET onboarding capability';
    $cap=$http('GET','/api/v1/tenant/company-onboarding/capabilities');
    $capData=json_decode((string)$cap->getContent(),true,512,JSON_THROW_ON_ERROR);
    $check($cap->getStatusCode()===200
        &&($capData['data']['can_create']??false)===true,
        'Guard F3.1 bloquea alta de empresa');
    echo "[PASS] F3.1: ADMIN puede crear, migracion y referencias globales listas.\n";

    $phase='GET 3 global SUNAT reference selectors';
    foreach(['contributor-types'=>51,'ciiu'=>417,'tax-codes'=>38] as $dataset=>$expected){
        $responseCatalog=$http('GET','/api/v1/catalogs/sunat-ruc/'.$dataset);
        $payloadCatalog=json_decode((string)$responseCatalog->getContent(),true,512,JSON_THROW_ON_ERROR);
        $check($responseCatalog->getStatusCode()===200
            &&count($payloadCatalog['data']??[])===$expected,
            'SUNAT reference dataset incomplete: '.$dataset);
    }
    echo "[PASS] F3.1 global SUNAT: 51 tipos, 417 CIIU, 38 tributos.\n";

    $phase='POST company wizard';
    $payload=[
      'tax_id'=>'20999990020',
      'business_name'=>'F3.1 Transporte Temporal E.I.R.L.',
      'legal_address'=>'Av. Desarrollo 100 - Prueba reversible',
      'legal_form'=>'EIRL','sunat_contributor_type_code'=>'07',
      'tax_regime'=>'MYPE_TRIBUTARIO','tax_codes'=>['1011','3031'],
      'economic_activities'=>[[
        'sector_code'=>'TRANSPORTE_LOGISTICA','ciiu_code'=>'4923',
        'description'=>'TRANSPORTE DE CARGA POR CARRETERA','is_primary'=>true
      ]],
      'fiscal_year'=>2026,'income_reference_year'=>2025,
      'annual_income'=>'100.00','base_currency_code'=>'PEN',
      'secondary_currency_codes'=>['USD'],
      'pcge_version'=>'PCGE_2019','confirm_early_adoption'=>false,
      'confirm_legal_data'=>true,
    ];
    $startPost=microtime(true);
    $response=$http('POST','/api/v1/tenant/companies',$payload);
    $postDuration=round(microtime(true)-$startPost,2);
    echo "[METRICA] F3.1 POST HTTP clonado PCGE: ".number_format($postDuration,2,'.','')." segundos.\n";
    if($postDuration>25){
        echo "[AVISO] F3.1 alta >25 s. Evitar reintento web antes de revisar rendimiento.\n";
    }
    $body=json_decode((string)$response->getContent(),true,512,JSON_THROW_ON_ERROR);
    $id=(int)($body['data']['id']??0);
    $check($response->getStatusCode()===201&&$id>0
        &&($body['data']['tax_id']??'')===$payload['tax_id']
        &&(int)($body['data']['plan_accounts_created']??0)>=1764,
        'POST de empresa o plan PCGE no confirmado (esperado 201)');
    $phase='tenant settings, PCGE and audit';
    $db->selectOne("SELECT set_config('app.current_company_id',?,true)",[(string)$id]);
    $detail=$db->selectOne(<<<'SQL'
       SELECT
         (SELECT count(*) FROM public.company_user WHERE company_id=? AND user_id=? AND role='ADMIN') AS members,
         (SELECT count(*) FROM public.company_settings WHERE company_id=? AND legal_form='EIRL' AND tax_regime='MYPE_TRIBUTARIO' AND sunat_contributor_type_code='07') AS settings,
         (SELECT count(*) FROM public.company_currencies WHERE company_id=?) AS currencies,
         (SELECT count(*) FROM public.company_tax_codes WHERE company_id=? AND tax_code IN ('1011','3031')) AS tax_codes,
         (SELECT count(*) FROM public.company_economic_activities WHERE company_id=? AND is_primary=true AND ciiu_code='4923' AND description='TRANSPORTE DE CARGA POR CARRETERA') AS activities,
         (SELECT count(*) FROM public.accounting_accounts WHERE company_id=?) AS accounts,
         (SELECT count(*) FROM public.company_areas WHERE company_id=?) AS areas,
         (SELECT count(*) FROM public.cash_flow_areas WHERE company_id=?) AS flow_areas,
         (SELECT count(*) FROM public.audit_logs WHERE company_id=? AND event_type='COMPANY_ONBOARDED') AS audits
       SQL,[$id,3000000000201,$id,$id,$id,$id,$id,$id,$id,$id]);
    $check($detail && (int)$detail->members===1
        &&(int)$detail->settings===1&&(int)$detail->currencies===2
        &&(int)$detail->tax_codes===2
        &&(int)$detail->activities===1&&(int)$detail->accounts>1764
        &&(int)$detail->areas===6&&(int)$detail->flow_areas===9
        &&(int)$detail->audits===1,
        'F3.1 configuracion incompleta / auditoria ausente');
    $db->selectOne("SELECT set_config('app.current_company_id','',true)");
    echo "[PASS] F3.1 HTTP 201: 1 empresa, ADMIN, EIRL/RMT, PEN/USD, SUNAT 07, tributos 1011/3031, CIIU 4923, PCGE, 6 areas, 9 flujos, 1 auditoria.\n";
    $passed=true;
}catch(\Throwable $e){
    $code=$e instanceof \Illuminate\Database\QueryException
        ?(string)($e->errorInfo[0]??'UNKNOWN'):'N/A';
    fwrite(STDERR,"[FAIL] F3.1 fase=".$phase." SQLSTATE=".preg_replace('/[^A-Z0-9]/','',$code)."\n");
}finally{
    if($started){
        try{$db->rollBack();echo "[ROLLBACK] F3.1 empresa temporal, cuentas y auditoria revertidas.\n";}
        catch(\Throwable){$passed=false;fwrite(STDERR,"[BLOQUEO] ROLLBACK fallido.\n");}
    }
    $db->disconnect();
    config($oldDrivers);
}
if($passed){
    try{
        $after=$owner->selectOne(<<<'SQL'
          SELECT (SELECT count(*) FROM public.companies)::integer AS companies,
            (SELECT count(*) FROM public.company_user)::integer AS members,
            (SELECT count(*) FROM public.audit_logs)::integer AS audits,
            (SELECT count(*) FROM public.fiscal_periods WHERE year=2026 AND month=1 AND status='OPEN')::integer AS january
          SQL);
        $passed=$after&&(int)$after->companies===(int)$pre->companies
          &&(int)$after->members===(int)$pre->members
          &&(int)$after->audits===(int)$pre->audits
          &&(int)$after->january===(int)$pre->january;
        echo $passed?"[PASS] F3.1 post-rollback: 3 empresas, 8 membresias, auditoria y enero intactos.\n"
            :"[BLOQUEO] F3.1 post-rollback no preservo baseline.\n";
    }catch(\Throwable){$passed=false;}
}
$owner->disconnect();
exit($passed?0:1);
