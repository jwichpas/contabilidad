<?php
declare(strict_types=1);

// Read-only F3.1 Neon readiness. Does not install migrations or create companies.
if($argc!==2||$argv[1]!=='--check'){
    fwrite(STDERR,"[USO] php scripts/phase3-company-onboarding-preflight.php --check\n");exit(2);
}
$root=dirname(__DIR__);
if(!is_file($root.'/.env')||is_file($root.'/bootstrap/cache/config.php')
    ||!is_file($root.'/vendor/autoload.php')){
    fwrite(STDERR,"[BLOQUEO] Laravel local sin cache y .env privado requeridos.\n");exit(1);
}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$host='ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$a=(array)config('database.connections.pgsql',[]);
$o=(array)config('database.connections.pgsql_migrations',[]);
$valid=static fn(array $c,string $role):bool =>
    ($c['host']??'')===$host && (string)($c['port']??'')==='5432'
    && ($c['database']??'')==='neondb'&&($c['username']??'')===$role
    &&trim((string)($c['url']??''))===''
    &&in_array((string)($c['sslmode']??''),['require','verify-ca','verify-full'],true);
if(!$app->environment('local')||config('database.default')!=='pgsql'
    ||env('NEON_TEST_BRANCH_NAME')!=='contabilidad-dev'
    ||env('NEON_TEST_BRANCH_HOST')!==$host
    ||filter_var(env('NEON_USE_POOLING',false),FILTER_VALIDATE_BOOLEAN)
    ||!$valid($a,'app_backend')||!$valid($o,'neondb_owner')){
    fwrite(STDERR,"[BLOQUEO] Solo Neon PG18 DEV directa con app_backend y neondb_owner.\n");exit(1);
}
$admin=\Illuminate\Support\Facades\DB::connection('pgsql_migrations');
$ok=false;
try{
    $s=$admin->selectOne(<<<'SQL'
       SELECT current_database() AS db,current_user AS actor,
         current_setting('server_version_num')::integer AS pg,
         (SELECT count(*) FROM public.migrations)::integer AS migrations,
         (SELECT count(*) FROM public.companies)::integer AS companies,
         (SELECT count(*) FROM public.users)::integer AS users,
         (SELECT count(*) FROM public.company_user)::integer AS memberships,
         (SELECT count(*) FROM public.accounting_account_templates)::integer AS templates,
         (SELECT count(*) FROM public.fiscal_periods)::integer AS periods,
         (SELECT count(*) FROM public.fiscal_periods WHERE year=2026
           AND month=1 AND status='OPEN')::integer AS jan_open,
         (SELECT count(*) FROM public.journal_entries)::integer AS journals,
         (SELECT count(*) FROM information_schema.columns WHERE table_schema='public'
           AND table_name='company_settings'
           AND column_name IN ('legal_form','initial_fiscal_year','income_reference_year',
              'annual_income','initial_pcge_version'))::integer AS profile_columns,
         CASE WHEN to_regclass('public.company_economic_activities') IS NULL THEN 0 ELSE 1
           END AS activity_table
       SQL);
    if(!$s||$s->db!=='neondb'||$s->actor!=='neondb_owner'
        ||intdiv((int)$s->pg,10000)!==18||(int)$s->companies!==3
        ||(int)$s->users!==3||(int)$s->memberships!==8
        ||(int)$s->templates!==3401||(int)$s->periods!==1
        ||(int)$s->jan_open!==1||(int)$s->journals!==0){
        throw new \RuntimeException('Baseline F3.1 no coincide.');
    }
    if((int)$s->migrations===22&&(int)$s->profile_columns===0&&(int)$s->activity_table===0){
        echo "[PASS] F3.1: Neon PG18 DEV, 3 empresas, enero OPEN, 22 migraciones.\n";
        echo "[PENDIENTE] Migracion aditiva de perfil economico no instalada; aplicar solo ruta revisada.\n";
        $ok=true;
    }elseif((int)$s->migrations===23&&(int)$s->profile_columns===5&&(int)$s->activity_table===1){
        echo "[PASS] F3.1: 23 migraciones, 5 columnas fiscales y tabla CIIU instalada.\n";
        $ok=true;
    }else{
        throw new \RuntimeException('Migracion parcial/desconocida: bloquear.');
    }
}catch(\Throwable $e){
    fwrite(STDERR,"[BLOQUEO] F3.1: esquema o baseline inesperado, sin escritura.\n");
}finally{$admin->disconnect();}
exit($ok?0:1);
