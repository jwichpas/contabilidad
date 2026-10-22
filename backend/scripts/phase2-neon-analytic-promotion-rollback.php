<?php

declare(strict_types=1);

/**
 * F2.3g: exercise privileged version transition without committing anything.
 * One outer owner transaction -> nested audited activate -> retire -> rollback.
 */
$root=dirname(__DIR__);
$host='ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$mode=$argv[1]??'--plan';
if($argc!==2||!in_array($mode,['--plan','--probe=contabilidad-dev'],true)){
    fwrite(STDERR,"[FAIL] Uso: --plan | --probe=contabilidad-dev\n");exit(2);
}
if($mode==='--plan'){
    echo "[PLAN] F2.3g: ADMIN demo activa y retira matriz 20999990011 solo dentro de transaccion OWNER.\n";
    echo "[PLAN] Bloquear actor no autorizado, SHA incorrecto, activacion y retiro duplicados.\n";
    echo "[PLAN] Comprobar dos auditorias atomicas y transiciones DRAFT/ACTIVE/RETIRED.\n";
    echo "[PLAN] ROLLBACK completo; matrices 3 DRAFT, auditoria y asientos vacios.\n";
    echo "[DRY-RUN] Ninguna conexion SQL o escritura.\n";exit(0);
}
if(!is_file($root.'/.env')||is_file($root.'/bootstrap/cache/config.php')){
    fwrite(STDERR,"[BLOQUEO] .env ausente o configuración cacheada.\n");exit(1);
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
    ||($owner['database']??'')!=='neondb'||($runtime['database']??'')!=='neondb'
    ||($owner['username']??'')!=='neondb_owner'||($runtime['username']??'')!=='app_backend'
    ||(string)($owner['port']??'')!=='5432'||(string)($runtime['port']??'')!=='5432'
    ||trim((string)($owner['url']??''))!==''||trim((string)($runtime['url']??''))!==''
    ||!in_array((string)($owner['sslmode']??''),['require','verify-ca','verify-full'],true)
    ||filter_var(env('NEON_USE_POOLING',false),FILTER_VALIDATE_BOOLEAN)){
    fwrite(STDERR,"[BLOQUEO] Exclusivo Neon DEV PG18 no pooled.\n");exit(1);
}

use App\Services\Accounting\AnalyticMatrixPromotionService;
use Illuminate\Support\Facades\DB;

$db=DB::connection('pgsql_migrations');
$begun=false;$ok=false;
try{
    $state=$db->selectOne(<<<'SQL'
      SELECT current_user AS actor,current_database() AS db,
          current_setting('server_version_num')::integer AS ver,
          (SELECT count(*) FROM public.migrations)::integer AS migrations,
          (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='DRAFT')::integer AS drafts,
          (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='ACTIVE')::integer AS active,
          (SELECT count(*) FROM public.accounting_destination_routes)::integer AS routes,
          (SELECT count(*) FROM public.journal_entries)::integer AS entries,
          (SELECT count(*) FROM public.audit_logs)::integer AS audits
      SQL);
    if(!$state||$state->actor!=='neondb_owner'||$state->db!=='neondb'
        ||intdiv((int)$state->ver,10000)!==18
        ||(int)$state->migrations!==21||(int)$state->drafts!==3
        ||(int)$state->active!==0||(int)$state->routes!==1362
        ||(int)$state->entries!==0||(int)$state->audits!==0){
        throw new RuntimeException('Baseline original incompatible con F2.3g.');
    }
    $db->beginTransaction();$begun=true;
    $db->selectOne("SELECT set_config('statement_timeout','20000',true)");
    $version=$db->selectOne(<<<'SQL'
        SELECT v.id,v.company_id,v.matrix_sha256,v.status
        FROM public.accounting_destination_rule_versions v
        JOIN public.companies c ON c.id=v.company_id
        WHERE c.tax_id='20999990011'
          AND v.version_key='PCGE_2019_CC_V2'
        SQL);
    if(!$version||$version->status!=='DRAFT'){
        throw new RuntimeException('No existe versión DRAFT a ensayar.');
    }
    $sha=(string)$version->matrix_sha256;
    $service=app(AnalyticMatrixPromotionService::class);
    $reject=static function(callable $fn):void{
        $rejected=false;
        try{$fn();}catch(\DomainException $e){$rejected=true;}
        if(!$rejected)throw new RuntimeException('La promoción omitió una validación obligatoria.');
    };
    $reject(static fn()=>$service->transition('20999990011',3000000000203,$sha,'activate'));
    $reject(static fn()=>$service->transition('20999990011',3000000000201,str_repeat('0',64),'activate'));
    echo "[PASS] Promocion deniega auditor sin ADMIN y SHA256 esperado incorrecto.\n";
    $activated=$service->transition('20999990011',3000000000201,$sha,'activate');
    if($activated['from']!=='DRAFT'||$activated['to']!=='ACTIVE'
        ||$activated['company_id']!==(int)$version->company_id){
        throw new RuntimeException('Transición ADMIN DRAFT->ACTIVE incorrecta.');
    }
    $reject(static fn()=>$service->transition('20999990011',3000000000201,$sha,'activate'));
    echo "[PASS] ACTIVE transitorio auditado; segunda activacion rechazada.\n";
    $retired=$service->transition('20999990011',3000000000201,$sha,'retire');
    if($retired['from']!=='ACTIVE'||$retired['to']!=='RETIRED'
        ||$retired['version_id']!==$activated['version_id']){
        throw new RuntimeException('Retiro ACTIVE->RETIRED incorrecto.');
    }
    $reject(static fn()=>$service->transition('20999990011',3000000000201,$sha,'retire'));
    $audits=$db->select(<<<'SQL'
        SELECT event_type,user_id,company_id
        FROM public.audit_logs WHERE company_id=?
          AND event_type IN ('ANALYTIC_MATRIX_ACTIVATED','ANALYTIC_MATRIX_RETIRED')
        ORDER BY id
        SQL,[$version->company_id]);
    if(count($audits)!==2
        ||$audits[0]->event_type!=='ANALYTIC_MATRIX_ACTIVATED'
        ||$audits[1]->event_type!=='ANALYTIC_MATRIX_RETIRED'
        ||(int)$audits[0]->user_id!==3000000000201
        ||(int)$audits[1]->user_id!==3000000000201){
        throw new RuntimeException('Auditoria versionada atomica incompleta.');
    }
    echo "[PASS] RETIRED transitorio, retiro duplicado bloqueado, 2 eventos ADMIN auditados.\n";
    $ok=true;
}catch(\Throwable $e){
    fwrite(STDERR,"[FAIL] F2.3g: ".substr($e->getMessage(),0,360)."\n");$ok=false;
}finally{
    if($begun){
        try{$db->rollBack();echo "[ROLLBACK] Activación, retiro y auditorías transitorias revertidas.\n";}
        catch(\Throwable){$ok=false;fwrite(STDERR,"[BLOQUEO] Rollback ADMIN incompleto.\n");}
    }
    $db->disconnect();
}
if($ok){
    try{
        $after=DB::connection('pgsql_migrations')->selectOne(<<<'SQL'
            SELECT (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='DRAFT')::integer AS drafts,
                (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='ACTIVE')::integer AS active,
                (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='RETIRED')::integer AS retired,
                (SELECT count(*) FROM public.journal_entries)::integer AS entries,
                (SELECT count(*) FROM public.audit_logs)::integer AS audits
            SQL);
        $ok=$after&&(int)$after->drafts===3&&(int)$after->active===0
            &&(int)$after->retired===0&&(int)$after->entries===0&&(int)$after->audits===0;
        echo $ok?"[PASS] POST-ROLLBACK: 3 DRAFT, 0 ACTIVE/RETIRED, 0 asientos/auditorias.\n"
            :"[BLOQUEO] Baseline post-rollback no se restauro.\n";
    }catch(\Throwable){$ok=false;fwrite(STDERR,"[BLOQUEO] Fallo verificación posrollback.\n");}
}
exit($ok?0:1);
