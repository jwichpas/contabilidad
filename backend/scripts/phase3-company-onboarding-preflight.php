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
           END AS activity_table,
         CASE WHEN to_regclass('public.sunat_ruc_contributor_types') IS NULL THEN 0 ELSE 1 END AS types_table,
         CASE WHEN to_regclass('public.sunat_ciiu_rev4_classes') IS NULL THEN 0 ELSE 1 END AS ciiu_table,
         CASE WHEN to_regclass('public.sunat_ruc_tax_codes') IS NULL THEN 0 ELSE 1 END AS tax_table,
         CASE WHEN to_regclass('public.company_tax_codes') IS NULL THEN 0 ELSE 1 END AS company_tax_table
       SQL);
    if(!$s||$s->db!=='neondb'||$s->actor!=='neondb_owner'
        ||intdiv((int)$s->pg,10000)!==18
        ||(int)$s->users!==3
        ||(int)$s->companies < ((int)$s->migrations===24 ? 4 : 3)
        ||(int)$s->memberships < ((int)$s->migrations===24 ? 9 : 8)
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
        echo "[PENDIENTE] SUNAT RUC: 3 catálogos globales no instalados. Instalar exclusivamente migración 24.\n";
    }elseif((int)$s->migrations===24&&(int)$s->profile_columns===5&&(int)$s->activity_table===1
        &&(int)$s->types_table===1&&(int)$s->ciiu_table===1
        &&(int)$s->tax_table===1&&(int)$s->company_tax_table===1){
        $counts=$admin->selectOne(<<<'SQL'
           SELECT (SELECT count(*) FROM public.sunat_ruc_contributor_types)::integer AS types,
                  (SELECT count(*) FROM public.sunat_ciiu_rev4_classes)::integer AS ciiu,
                  (SELECT count(*) FROM public.sunat_ruc_tax_codes)::integer AS taxes,
                  (SELECT count(*) FROM public.sunat_ruc_reference_versions)::integer AS versions,
                  (SELECT count(*) FROM public.company_tax_codes)::integer AS assignments
           SQL);
        if(!$counts || (int)$counts->types!==51 || (int)$counts->ciiu!==417
            ||(int)$counts->taxes!==38||(int)$counts->versions!==3||(int)$counts->assignments<2){
            throw new \RuntimeException('Catálogos SUNAT RUC incompletos');
        }
        $security=$admin->selectOne(<<<'SQL'
          SELECT
            (SELECT relrowsecurity AND relforcerowsecurity
             FROM pg_class WHERE oid='public.company_tax_codes'::regclass) AS tenant_force_rls,
            (SELECT count(*)::int FROM pg_constraint WHERE conname IN
             ('fk_company_sunat_contributor_type','fk_company_economic_official_ciiu',
              'fk_company_tax_official_code')) AS valid_foreign_keys,
            (SELECT count(*)::int FROM information_schema.columns
             WHERE table_schema='public' AND table_name='company_settings'
               AND column_name='sunat_contributor_type_code') AS company_field,
            has_table_privilege('app_backend','public.sunat_ruc_contributor_types','SELECT') AS types_select,
            has_table_privilege('app_backend','public.sunat_ciiu_rev4_classes','SELECT') AS ciiu_select,
            has_table_privilege('app_backend','public.sunat_ruc_tax_codes','SELECT') AS taxes_select,
            has_table_privilege('app_backend','public.sunat_ruc_contributor_types','INSERT') AS types_insert,
            has_table_privilege('app_backend','public.sunat_ciiu_rev4_classes','INSERT') AS ciiu_insert,
            has_table_privilege('app_backend','public.sunat_ruc_tax_codes','INSERT') AS taxes_insert,
            has_table_privilege('app_backend','public.company_tax_codes','INSERT') AS assignment_insert
          SQL);
        if(!$security||!$security->tenant_force_rls
            ||(int)$security->valid_foreign_keys!==3
            ||(int)$security->company_field!==1
            ||!$security->types_select||!$security->ciiu_select||!$security->taxes_select
            ||$security->types_insert||$security->ciiu_insert||$security->taxes_insert
            ||!$security->assignment_insert){
            throw new \RuntimeException('Catálogos globales o permisos RLS incorrectos');
        }
        $ledger=$admin->select('SELECT dataset,source_file,sha256,row_count,version FROM public.sunat_ruc_reference_versions ORDER BY dataset');
        $verified=0;
        foreach($ledger as $entry){
            $source=database_path('reference_data/'.$entry->source_file);
            if(!is_file($source) || !hash_equals((string)$entry->sha256,hash_file('sha256',$source))
                ||(string)$entry->version!=='2026-10-22-v1'){
                throw new \RuntimeException('Archivo global versionado cambiado o sin instalación');
            }
            $verified++;
        }
        if($verified!==3)throw new \RuntimeException('Faltan hashes de catálogo global');
        $company=$admin->selectOne(<<<'SQL'
            SELECT
              (SELECT count(*) FROM public.companies WHERE id=4 AND tax_id='20610141588')::integer AS company_exists,
              (SELECT count(*) FROM public.company_user WHERE company_id=4 AND role='ADMIN')::integer AS admins,
              (SELECT count(*) FROM public.accounting_accounts WHERE company_id=4)::integer AS accounts,
              (SELECT count(*) FROM public.company_currencies WHERE company_id=4)::integer AS currencies,
              (SELECT count(*) FROM public.company_tax_codes WHERE company_id=4)::integer AS taxes,
              (SELECT count(*) FROM public.audit_logs WHERE company_id=4 AND event_type='COMPANY_ONBOARDED')::integer AS created_audit
            SQL);
        if(!$company||(int)$company->company_exists!==1||(int)$company->admins!==1
            ||(int)$company->accounts!==2966||(int)$company->currencies!==2
            ||(int)$company->taxes!==2||(int)$company->created_audit!==1){
            throw new \RuntimeException('Alta real de empresa 4 o PCGE no está íntegra');
        }
        echo "[PASS] F3.1: 24 migraciones y 3 catálogos SUNAT (51/417/38).\n";
        echo "[PASS] Alta web persistente: empresa 4, 2966 cuentas, ADMIN, monedas, tributos y auditoria.\n";
        $ok=true;
    }else{
        throw new \RuntimeException('Migracion parcial/desconocida: bloquear.');
    }
}catch(\Throwable $e){
    fwrite(STDERR,"[BLOQUEO] F3.1: esquema o baseline inesperado, sin escritura.\n");
}finally{$admin->disconnect();}
exit($ok?0:1);
