<?php
declare(strict_types=1);

/**
 * F2.3f read-only promotion preflight.
 * Never modifies versions or audits. Strictly Neon contabilidad-dev PG18.
 */
$root=dirname(__DIR__);
$host='ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$arg=$argv[1]??'--plan';
$taxes=['20999990011','20999990012','20999990013'];
if($argc!==2||($arg!=='--plan'&&$arg!=='--verify'
    &&!in_array($arg,array_map(static fn($tax)=>'--preflight='.$tax,$taxes),true))){
    fwrite(STDERR,"[FAIL] Use --plan | --verify | --preflight=20999990011 (RUC demo).\n");exit(2);
}
if($arg==='--plan'){
    echo "[PLAN] F2.3f: verificar 21 migraciones, empresa DEMO, SHA256 de rutas, 227 x 2.\n";
    echo "[PLAN] SELECT-only: sin activar matrices, sin crear asientos ni auditar cambios.\n";
    echo "[DRY-RUN] Sin conexion SQL.\n";exit(0);
}
if(!is_file($root.'/.env')||is_file($root.'/bootstrap/cache/config.php')){
    fwrite(STDERR,"[BLOQUEO] .env local sin cache obligatorio.\n");exit(1);
}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$owner=(array)config('database.connections.pgsql_migrations',[]);
$runtime=(array)config('database.connections.pgsql',[]);
if(!$app->environment('local')||config('database.default')!=='pgsql'
  ||env('NEON_TEST_BRANCH_NAME','')!=='contabilidad-dev'
  ||env('NEON_TEST_BRANCH_HOST','')!==$host
  ||($owner['host']??'')!==$host||($runtime['host']??'')!==$host
  ||(string)($owner['port']??'')!=='5432'||(string)($runtime['port']??'')!=='5432'
  ||($owner['database']??'')!=='neondb'||($runtime['database']??'')!=='neondb'
  ||($owner['username']??'')!=='neondb_owner'||($runtime['username']??'')!=='app_backend'
  ||trim((string)($owner['url']??''))!==''||trim((string)($runtime['url']??''))!==''
  ||!in_array((string)($owner['sslmode']??''),['require','verify-ca','verify-full'],true)
  ||!in_array((string)($runtime['sslmode']??''),['require','verify-ca','verify-full'],true)
  ||filter_var(env('NEON_USE_POOLING',false),FILTER_VALIDATE_BOOLEAN)){
    fwrite(STDERR,"[BLOQUEO] Destino no autorizado: solo Neon DEV PG18.\n");exit(1);
}
$db=\Illuminate\Support\Facades\DB::connection('pgsql_migrations');
$ok=false;
try{
    $guard=$db->selectOne(<<<'SQL'
        SELECT current_user AS actor,current_database() AS db,
          current_setting('server_version_num')::integer AS version,
          (SELECT count(*) FROM public.migrations)::integer AS migrations,
          (SELECT count(*) FROM public.companies)::integer AS companies,
          (SELECT count(*) FROM public.accounting_destination_rule_versions)::integer AS matrices,
          (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='ACTIVE')::integer AS active,
          (SELECT count(*) FROM public.accounting_destination_routes)::integer AS routes
        SQL);
    if(!$guard||$guard->actor!=='neondb_owner'||$guard->db!=='neondb'
        ||intdiv((int)$guard->version,10000)!==18
        ||(int)$guard->migrations!==21||(int)$guard->companies!==3
        ||(int)$guard->matrices!==3||(int)$guard->routes!==1362
        ||(int)$guard->active!==0){
        throw new RuntimeException('Baseline de matrices DEMO alterado o hay version ACTIVE.');
    }
    $companies=$db->select('SELECT id,tax_id FROM public.companies ORDER BY tax_id');
    if(array_column($companies,'tax_id')!==$taxes)throw new RuntimeException('RUC DEMO no coincide.');
    $selected=$arg==='--verify'?$taxes:[substr($arg,12)];
    foreach($companies as $company){
        if(!in_array($company->tax_id,$selected,true))continue;
        $v=$db->selectOne(<<<'SQL'
            SELECT id,version_key,status,strategy,source_count,route_count,matrix_sha256
            FROM public.accounting_destination_rule_versions
            WHERE company_id=?
            SQL,[$company->id]);
        if(!$v||$v->version_key!=='PCGE_2019_CC_V2'||$v->status!=='DRAFT'
            ||$v->strategy!=='COST_CENTER'
            ||(int)$v->source_count!==227||(int)$v->route_count!==454){
            throw new RuntimeException('Version DRAFT y metadata no validos.');
        }
        $routes=$db->select(<<<'SQL'
            SELECT a.code AS source,cc.code AS center,d.code AS debit,h.code AS credit
            FROM public.accounting_destination_routes r
            JOIN public.accounting_accounts a ON a.company_id=r.company_id
                AND a.id=r.source_account_id AND a.is_operational AND a.is_active
                AND a.has_automatic_destination AND a.requires_cost_center
                AND a.account_type='EXPENSE' AND length(a.code)=6 AND left(a.code,1)='6'
            JOIN public.cost_centers cc ON cc.company_id=r.company_id
                AND cc.id=r.cost_center_id AND cc.is_active
                AND cc.destination_debit_account_id=r.destination_debit_account_id
                AND cc.destination_credit_account_id=r.destination_credit_account_id
            JOIN public.accounting_accounts d ON d.company_id=r.company_id
                AND d.id=r.destination_debit_account_id
                AND d.is_active AND d.is_operational AND length(d.code)=6
            JOIN public.accounting_accounts h ON h.company_id=r.company_id
                AND h.id=r.destination_credit_account_id
                AND h.is_active AND h.is_operational AND length(h.code)=6
            WHERE r.company_id=? AND r.rule_version_id=?
            ORDER BY a.code,cc.code
            SQL,[$company->id,$v->id]);
        $snapshot=[];$sourceCodes=[];
        foreach($routes as $r){
            $want=match($r->center){'CC-ADM'=>'941100','CC-VTA'=>'951100',default=>null};
            if($r->debit!==$want||$r->credit!=='791000'||$want===null){
                throw new RuntimeException('Cuenta de destino no reconocida.');
            }
            $snapshot[]=$r->source.'|'.$r->center.'|'.$r->debit.'|'.$r->credit;
            $sourceCodes[$r->source]=true;
        }
        $sha=hash('sha256',implode("\n",$snapshot));
        if(count($snapshot)!==454||count($sourceCodes)!==227
            ||$sha!==(string)$v->matrix_sha256){
            throw new RuntimeException('Rutas 227x2 o matriz SHA256 inconsistente.');
        }
        echo "[PASS] ".$company->tax_id.": 227 fuentes, CC-ADM/CC-VTA, 454 rutas, SHA256 valido, DRAFT.\n";
    }
    echo "[PASS] F2.3f: preflight READ ONLY, ninguna matriz activada.\n";
    $ok=true;
}catch(\Throwable $e){
    fwrite(STDERR,"[BLOQUEO] F2.3f: ".$e->getMessage()."\n");
}finally{$db->disconnect();}
exit($ok?0:1);
