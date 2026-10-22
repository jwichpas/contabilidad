<?php

declare(strict_types=1);

/**
 * F2.3b: ONLY adds immutable analytic-line traceability schema on Neon DEV.
 * Does not promote any destination matrix from DRAFT to ACTIVE.
 * --plan never connects. --verify only SELECT. --apply requires explicit name.
 */
$root=dirname(__DIR__);
$host='ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$migration='2026_10_22_130000_create_journal_analytic_allocations';
$path='database/portable_migrations/'.$migration.'.php';
$mode=$argv[1]??'--plan';
if ($argc !== 2 || !in_array($mode,['--plan','--verify','--apply=contabilidad-dev'],true)) {
    fwrite(STDERR,"[FAIL] Uso: --plan|--verify|--apply=contabilidad-dev\n");exit(2);
}
if (!is_file($root.'/'.$path)) { fwrite(STDERR,"[FAIL] Falta migracion F2.3b.\n");exit(1); }
if ($mode==='--plan') {
    echo "[PLAN] F2.3b: agregar amarre journal_analytic_allocations (fuente 6xx, Debe 94/95, Haber 79).\n";
    echo "[PLAN] FK compuestas mismo tenant y mismo asiento; UNIQUE por origen; RLS SELECT/INSERT sin UPDATE/DELETE.\n";
    echo "[PLAN] Matrices PCGE_2019_CC_V2 permanecen DRAFT. Activacion POST prohibida.\n";
    echo "[DRY-RUN] Ninguna conexion SQL.\n";exit(0);
}
if (!is_file($root.'/.env')||is_file($root.'/bootstrap/cache/config.php')) {
    fwrite(STDERR,"[BLOQUEO] Falta .env o existe cache Laravel.\n");exit(1);
}
$seen=[];
foreach (file($root.'/.env',FILE_IGNORE_NEW_LINES)?:[] as $line) {
    if (!preg_match('/^[ \t]*(?:export[ \t]+)?([A-Z][A-Z0-9_]*)[ \t]*=/',$line,$m))continue;
    $key=$m[1];
    if (!str_starts_with($key,'DB_')&&!str_starts_with($key,'NEON_')
        &&!in_array($key,['APP_ENV','APP_KEY','DATABASE_URL'],true))continue;
    if (isset($seen[$key])){fwrite(STDERR,"[BLOQUEO] Clave privada duplicada.\n");exit(1);}
    $seen[$key]=true;
}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$admin=(array)config('database.connections.pgsql_migrations',[]);
$runtime=(array)config('database.connections.pgsql',[]);
if (!$app->environment('local')||config('database.default')!=='pgsql'
    ||env('NEON_TEST_BRANCH_NAME','')!=='contabilidad-dev'
    ||env('NEON_TEST_BRANCH_HOST','')!==$host
    ||($admin['host']??'')!==$host||($runtime['host']??'')!==$host
    ||(string)($admin['port']??'')!=='5432'||(string)($runtime['port']??'')!=='5432'
    ||($admin['database']??'')!=='neondb'||($runtime['database']??'')!=='neondb'
    ||($admin['username']??'')!=='neondb_owner'||($runtime['username']??'')!=='app_backend'
    ||trim((string)($admin['url']??''))!==''||trim((string)($runtime['url']??''))!==''
    ||!in_array((string)($admin['sslmode']??''),['require','verify-ca','verify-full'],true)
    ||!in_array((string)($runtime['sslmode']??''),['require','verify-ca','verify-full'],true)
    ||filter_var(env('NEON_USE_POOLING',false),FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR,"[BLOQUEO] Solo Neon contabilidad-dev PG18 directo.\n");exit(1);
}
try {
    $db=\Illuminate\Support\Facades\DB::connection('pgsql_migrations');
    $state=$db->selectOne(<<<'SQL'
      SELECT current_user AS role_name,current_database() AS db_name,
        current_setting('server_version_num')::integer AS pg_ver,
        (SELECT count(*) FROM public.migrations)::integer AS migrations,
        (SELECT count(*) FROM public.migrations WHERE migration=?)::integer AS migration_record,
        (SELECT count(*) FROM public.companies)::integer AS companies,
        (SELECT count(*) FROM public.users)::integer AS users,
        (SELECT count(*) FROM public.company_user)::integer AS members,
        (SELECT count(*) FROM public.reference_data_versions)::integer AS global_versions,
        (SELECT count(*) FROM public.accounting_account_templates)::integer AS pcge,
        (SELECT count(*) FROM public.accounting_destination_rule_versions
            WHERE status='DRAFT' AND version_key='PCGE_2019_CC_V2')::integer AS drafts,
        (SELECT count(*) FROM public.accounting_destination_rule_versions
            WHERE status='ACTIVE')::integer AS active_count,
        (SELECT count(*) FROM public.accounting_destination_routes)::integer AS routes,
        (SELECT count(*) FROM public.journal_entries)::integer AS entries,
        (SELECT count(*) FROM information_schema.columns
            WHERE table_schema='public' AND table_name='journal_entry_lines'
                AND column_name='cost_center_id')::integer AS center_column,
        (SELECT count(*) FROM pg_catalog.pg_constraint
            WHERE conname='fk_journal_lines_cost_center_same_tenant')::integer AS center_fk,
        CASE WHEN to_regclass('public.journal_analytic_allocations') IS NULL THEN 0 ELSE 1 END AS allocation_table
      SQL,[$migration]);
    if (!$state||$state->role_name!=='neondb_owner'||$state->db_name!=='neondb'
        ||intdiv((int)$state->pg_ver,10000)!==18
        ||!in_array((int)$state->migrations,[20,21],true)
        ||(int)$state->migration_record !== (int)$state->migrations-20
        ||(int)$state->allocation_table!==(int)$state->migration_record
        ||(int)$state->companies!==3||(int)$state->users!==3||(int)$state->members!==8
        ||(int)$state->global_versions!==1||(int)$state->pcge!==3401
        ||(int)$state->drafts!==3||(int)$state->active_count!==0||(int)$state->routes!==1362
        ||(int)$state->center_column!==1||(int)$state->center_fk!==1) {
        throw new RuntimeException('Precondiciones del esquema incumplidas.');
    }
    echo '[PASS] PG18 contabilidad-dev, '.(int)$state->migrations.' migraciones, 3 matrices DRAFT, 1362 rutas.'.PHP_EOL;
    if ($mode==='--apply=contabilidad-dev' && (int)$state->entries!==0) {
        throw new RuntimeException('Se requieren cero asientos antes de instalar trazabilidad.');
    }
    if ($mode==='--verify' && (int)$state->allocation_table===0) {
        echo "[PENDIENTE] Aun no existe trazabilidad de imputaciones F2.3b.\n";exit(1);
    }
    if ($mode==='--apply=contabilidad-dev' && (int)$state->allocation_table===0) {
        $result=\Illuminate\Support\Facades\Artisan::call('migrate',[
            '--database'=>'pgsql_migrations','--path'=>$path,
            '--force'=>true,'--no-interaction'=>true,
        ]);
        if ($result!==0)throw new RuntimeException('Migracion F2.3b fallida.');
    }
    $check=$db->selectOne(<<<'SQL'
      SELECT
        (SELECT count(*) FROM public.migrations)::integer AS migrations,
        (SELECT count(*) FROM public.migrations WHERE migration=?)::integer AS migration_record,
        (SELECT count(*) FROM pg_catalog.pg_constraint
            WHERE conrelid='public.journal_analytic_allocations'::regclass
              AND conname IN ('fk_analytic_alloc_source_same_voucher',
                             'fk_analytic_alloc_debit_same_voucher',
                             'fk_analytic_alloc_credit_same_voucher',
                             'fk_analytic_alloc_version_tenant',
                             'fk_analytic_alloc_center_tenant'))::integer AS fks,
        CASE WHEN (SELECT relrowsecurity AND relforcerowsecurity
            FROM pg_catalog.pg_class
            WHERE oid='public.journal_analytic_allocations'::regclass)
            THEN 1 ELSE 0 END AS force_rls,
        (SELECT count(*) FROM pg_catalog.pg_indexes
            WHERE schemaname='public'
              AND indexname='uq_dest_versions_one_active_per_company')::integer AS active_index,
        CASE WHEN has_table_privilege('app_backend',
            'public.journal_analytic_allocations','SELECT') THEN 1 ELSE 0 END AS can_read,
        CASE WHEN has_table_privilege('app_backend',
            'public.journal_analytic_allocations','INSERT') THEN 1 ELSE 0 END AS can_insert,
        CASE WHEN has_table_privilege('app_backend',
            'public.journal_analytic_allocations','UPDATE') THEN 1 ELSE 0 END AS can_update,
        CASE WHEN has_table_privilege('app_backend',
            'public.journal_analytic_allocations','DELETE') THEN 1 ELSE 0 END AS can_delete
      SQL,[$migration]);
    if (!$check||(int)$check->migrations!==21||(int)$check->migration_record!==1
        ||(int)$check->fks!==5||(int)$check->force_rls!==1
        ||(int)$check->active_index!==1||(int)$check->can_read!==1||(int)$check->can_insert!==1
        ||(int)$check->can_update!==0||(int)$check->can_delete!==0) {
        throw new RuntimeException('Contrato PostgreSQL RLS/FK F2.3b incompleto.');
    }
    echo "[PASS] F2.3b: 21 migraciones, lineage same-voucher, UNIQUE y RLS sin UPDATE/DELETE.\n";
    echo "[PENDIENTE] V2 sigue DRAFT: no se generan asientos 94/95/79 automaticamente.\n";
} catch (Throwable $error) {
    fwrite(STDERR,"[BLOQUEO] F2.3b no verificada. No ejecutar migrate general ni activar matrices.\n");
    exit(1);
} finally {
    if (isset($db))$db->disconnect();
}
