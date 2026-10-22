<?php

declare(strict_types=1);

/**
 * F2.2 deployment gate: ONE additive Laravel migration, only Neon DEV.
 *
 * php scripts/phase2-neon-journal-idempotency.php --plan
 * php scripts/phase2-neon-journal-idempotency.php --verify
 * php scripts/phase2-neon-journal-idempotency.php --apply=contabilidad-dev
 *
 * No production connections, no migrate:fresh, no manual SQL DDL.
 */
$root=dirname(__DIR__);
$migration='2026_10_22_140000_create_journal_post_idempotency';
$path='database/portable_migrations/'.$migration.'.php';
$host='ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$mode=$argv[1]??'--plan';
if($argc!==2 || !in_array($mode,['--plan','--verify','--apply=contabilidad-dev'],true)){
    fwrite(STDERR,"[FAIL] Uso: --plan | --verify | --apply=contabilidad-dev\n");exit(2);
}
if(!is_file($root.'/'.$path)){
    fwrite(STDERR,"[BLOQUEO] Falta migración de recibos de idempotencia.\n");exit(1);
}
if($mode==='--plan'){
    echo "[PLAN] F2.2: una migración Laravel aditiva, solo Neon PG18 contabilidad-dev.\n";
    echo "[PLAN] journal_post_idempotency: UNIQUE empresa/clave, FK voucher, SHA256, FORCE RLS.\n";
    echo "[PLAN] app_backend SELECT/INSERT, sin UPDATE/DELETE; 3 matrices DRAFT sin cambios.\n";
    echo "[PLAN] --verify SELECT; --apply hace commit de esquema/registro Laravel, NO rollback.\n";
    echo "[DRY-RUN] Sin conexión SQL.\n";exit(0);
}
if(!is_file($root.'/.env')||is_file($root.'/bootstrap/cache/config.php')){
    fwrite(STDERR,"[BLOQUEO] .env local requerido y sin cache de configuración.\n");exit(1);
}
$seen=[];
foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES)?:[] as $line){
    if(!preg_match('/^[ \t]*(?:export[ \t]+)?([A-Z][A-Z0-9_]*)[ \t]*=/',$line,$m))continue;
    $key=$m[1];
    if(!str_starts_with($key,'DB_')&&!str_starts_with($key,'NEON_')
        &&!in_array($key,['APP_ENV','APP_KEY','DATABASE_URL'],true))continue;
    if(isset($seen[$key])){fwrite(STDERR,"[BLOQUEO] .env contiene clave duplicada.\n");exit(1);}
    $seen[$key]=true;
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
    fwrite(STDERR,"[BLOQUEO] Entorno distinto a Neon contabilidad-dev directo.\n");exit(1);
}
use Illuminate\Support\Facades\DB;
$db=DB::connection('pgsql_migrations');
$success=false;
try{
    $state=$db->selectOne(<<<'SQL'
      SELECT current_user AS actor,current_database() AS database_name,
        current_setting('server_version_num')::integer AS postgres_version,
        (SELECT count(*) FROM public.migrations)::integer AS migrations,
        (SELECT count(*) FROM public.migrations WHERE migration=?)::integer AS migration_record,
        CASE WHEN to_regclass('public.journal_post_idempotency') IS NULL
          THEN 0 ELSE 1 END AS table_exists,
        (SELECT count(*) FROM public.companies)::integer AS companies,
        (SELECT count(*) FROM public.users)::integer AS users,
        (SELECT count(*) FROM public.company_user)::integer AS members,
        (SELECT count(*) FROM public.accounting_account_templates)::integer AS pcge,
        (SELECT count(*) FROM public.accounting_destination_routes)::integer AS routes,
        (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='DRAFT')::integer AS drafts,
        (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='ACTIVE')::integer AS active_versions,
        (SELECT count(*) FROM public.journal_entries)::integer AS entries,
        (SELECT count(*) FROM public.journal_entry_lines)::integer AS lines,
        (SELECT count(*) FROM public.audit_logs)::integer AS audits
      SQL,[$migration]);
    if(!$state||$state->actor!=='neondb_owner'||$state->database_name!=='neondb'
        ||intdiv((int)$state->postgres_version,10000)!==18
        ||!in_array((int)$state->migrations,[21,22],true)
        ||(int)$state->companies!==3||(int)$state->users!==3||(int)$state->members!==8
        ||(int)$state->pcge!==3401||(int)$state->routes!==1362
        ||(int)$state->drafts!==3||(int)$state->active_versions!==0
        ||(int)$state->entries!==0||(int)$state->lines!==0||(int)$state->audits!==0
        ||(int)$state->migration_record!==((int)$state->migrations-21)
        ||(int)$state->table_exists!==(int)$state->migration_record){
        throw new RuntimeException('Baseline inesperado: verificar 21/22 migraciones, DEMO, RLS y tabla.');
    }
    $companies=$db->select('SELECT tax_id FROM public.companies ORDER BY tax_id');
    if(array_map(static fn($r)=>(string)$r->tax_id,$companies)
        !==['20999990011','20999990012','20999990013']){
        throw new RuntimeException('Las empresas no son las tres DEMO autorizadas.');
    }
    echo "[PASS] Neon PG18 DEV, ".$state->migrations." migraciones, 3 DRAFT, 1362 rutas, 0 asientos/auditoría.\n";

    if($mode==='--verify' && (int)$state->table_exists===0){
        echo "[PENDIENTE] La migración de idempotencia todavía no está instalada.\n";
        exit(1);
    }
    if($mode==='--apply=contabilidad-dev' && (int)$state->table_exists===0){
        // Laravel is the sole schema owner, never create tables via direct SQL.
        $code=\Illuminate\Support\Facades\Artisan::call('migrate',[
            '--database'=>'pgsql_migrations','--path'=>$path,
            '--force'=>true,'--no-interaction'=>true,
        ]);
        if($code!==0){
            throw new RuntimeException('Laravel no pudo aplicar la migración F2.2.');
        }
        echo "[PASS] Migración F2.2 instalada exclusivamente mediante Laravel Artisan.\n";
    }

    $check=$db->selectOne(<<<'SQL'
      SELECT (SELECT count(*) FROM public.migrations)::integer AS migrations,
        (SELECT count(*) FROM public.migrations WHERE migration=?)::integer AS registered,
        (SELECT count(*) FROM public.journal_post_idempotency)::integer AS receipts,
        (SELECT count(*) FROM pg_catalog.pg_constraint
            WHERE conrelid='public.journal_post_idempotency'::regclass
              AND conname IN ('fk_journal_post_idempotency_tenant_voucher',
                              'chk_journal_post_idempotency_sha',
                              'chk_journal_post_idempotency_key',
                              'uq_journal_post_idempotency_tenant_key',
                              'uq_journal_post_idempotency_voucher'))::integer AS constraints_count,
        CASE WHEN (SELECT relrowsecurity AND relforcerowsecurity
            FROM pg_catalog.pg_class WHERE oid='public.journal_post_idempotency'::regclass)
            THEN 1 ELSE 0 END AS force_rls,
        (SELECT count(*) FROM pg_catalog.pg_policies
            WHERE schemaname='public' AND tablename='journal_post_idempotency'
                AND policyname IN ('journal_post_idempotency_read_tenant',
                                   'journal_post_idempotency_insert_tenant'))::integer AS policies,
        CASE WHEN has_table_privilege('app_backend','public.journal_post_idempotency','SELECT')
            THEN 1 ELSE 0 END AS can_read,
        CASE WHEN has_table_privilege('app_backend','public.journal_post_idempotency','INSERT')
            THEN 1 ELSE 0 END AS can_insert,
        CASE WHEN has_table_privilege('app_backend','public.journal_post_idempotency','UPDATE')
            THEN 1 ELSE 0 END AS can_update,
        CASE WHEN has_table_privilege('app_backend','public.journal_post_idempotency','DELETE')
            THEN 1 ELSE 0 END AS can_delete,
        (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='DRAFT')::integer AS drafts,
        (SELECT count(*) FROM public.journal_entries)::integer AS entries
      SQL,[$migration]);
    if(!$check||(int)$check->migrations!==22||(int)$check->registered!==1
        ||(int)$check->receipts!==0||(int)$check->constraints_count!==5
        ||(int)$check->force_rls!==1||(int)$check->policies!==2
        ||(int)$check->can_read!==1||(int)$check->can_insert!==1
        ||(int)$check->can_update!==0||(int)$check->can_delete!==0
        ||(int)$check->drafts!==3||(int)$check->entries!==0){
        throw new RuntimeException('Contrato RLS/FK/UNIQUE o baseline posterior incompleto.');
    }
    echo "[PASS] F2.2: 22 migraciones, 5 constraints, 2 políticas RLS, app_backend SELECT/INSERT sin UPDATE/DELETE.\n";
    echo "[PASS] Tres matrices DRAFT, sin asientos ni recibos: migración aditiva verificada.\n";
    $success=true;
}catch(Throwable $e){
    fwrite(STDERR,"[BLOQUEO] F2.2: ".substr($e->getMessage(),0,240)."\n");
}finally{
    $db->disconnect();
}
exit($success?0:1);
