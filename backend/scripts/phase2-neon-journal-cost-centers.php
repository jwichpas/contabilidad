<?php

declare(strict_types=1);

/**
 * F2.3a: guarded additive schema change for cost centers on journal lines.
 * --plan                  No database connection.
 * --verify                Read-only schema and baseline checks.
 * --apply=contabilidad-dev  ONLY existing Neon dev / direct PG18.
 * No POST automatic 94/95/79, no entries, no new branches.
 */
$root = dirname(__DIR__);
$host = 'ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$migration = '2026_10_22_120000_add_journal_line_cost_centers';
$path = 'database/portable_migrations/'.$migration.'.php';
$mode = $argv[1] ?? '--plan';

if ($argc !== 2 || !in_array($mode, ['--plan','--verify','--apply=contabilidad-dev'], true)) {
    fwrite(STDERR, "[FAIL] Uso: --plan|--verify|--apply=contabilidad-dev\n");
    exit(2);
}
if (!is_file($root.'/'.$path)) {
    fwrite(STDERR, "[FAIL] Migracion F2.3a no encontrada.\n");
    exit(1);
}
if ($mode === '--plan') {
    echo "[PLAN] F2.3a: agregar journal_entry_lines.cost_center_id NULL y FK (company_id,cost_center_id).\n";
    echo "[PLAN] Validar centro activo en POST; preservar centro de costo durante extorno.\n";
    echo "[PLAN] Mantener 1.362 rutas PCGE_2019_CC_V2 en DRAFT, sin imputacion automatica.\n";
    echo "[DRY-RUN] No se conecto a base de datos.\n";
    exit(0);
}
if (!is_file($root.'/.env') || is_file($root.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "[BLOQUEO] Falta .env o hay config cacheada.\n");
    exit(1);
}
$seen = [];
foreach (file($root.'/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (!preg_match('/^[ \t]*(?:export[ \t]+)?([A-Z][A-Z0-9_]*)[ \t]*=/', $line, $m)) continue;
    $key=$m[1];
    if (!str_starts_with($key, 'DB_') && !str_starts_with($key, 'NEON_')
        && !in_array($key, ['APP_ENV','APP_KEY','DATABASE_URL'], true)) continue;
    if (isset($seen[$key])) {
        fwrite(STDERR, "[BLOQUEO] Clave DB/Neon duplicada.\n");
        exit(1);
    }
    $seen[$key]=true;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$admin=(array)config('database.connections.pgsql_migrations',[]);
$runtime=(array)config('database.connections.pgsql',[]);
if (!$app->environment('local') || config('database.default')!=='pgsql'
    || env('NEON_TEST_BRANCH_NAME','')!=='contabilidad-dev'
    || env('NEON_TEST_BRANCH_HOST','')!==$host
    || ($admin['host']??'')!==$host || ($runtime['host']??'')!==$host
    || (string)($admin['port']??'')!=='5432' || (string)($runtime['port']??'')!=='5432'
    || ($admin['database']??'')!=='neondb' || ($runtime['database']??'')!=='neondb'
    || ($admin['username']??'')!=='neondb_owner' || ($runtime['username']??'')!=='app_backend'
    || trim((string)($admin['url']??''))!=='' || trim((string)($runtime['url']??''))!==''
    || !in_array((string)($admin['sslmode']??''),['require','verify-ca','verify-full'],true)
    || !in_array((string)($runtime['sslmode']??''),['require','verify-ca','verify-full'],true)
    || filter_var(env('NEON_USE_POOLING',false),FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, "[BLOQUEO] Solo Neon contabilidad-dev PG18 directo.\n");
    exit(1);
}

try {
    $db=\Illuminate\Support\Facades\DB::connection('pgsql_migrations');
    $before=$db->selectOne(<<<'SQL'
      SELECT current_user AS role_name,current_database() AS db_name,
        current_setting('server_version_num')::integer AS pg_ver,
        (SELECT count(*) FROM public.migrations)::integer AS migrations,
        (SELECT count(*) FROM public.migrations WHERE migration=?)::integer AS migration_record,
        (SELECT count(*) FROM public.companies)::integer AS companies,
        (SELECT count(*) FROM public.users)::integer AS users,
        (SELECT count(*) FROM public.company_user)::integer AS members,
        (SELECT count(*) FROM public.reference_data_versions)::integer AS global_reference,
        (SELECT count(*) FROM public.accounting_account_templates)::integer AS pcge,
        (SELECT count(*) FROM public.accounting_destination_rule_versions
             WHERE status='DRAFT' AND version_key='PCGE_2019_CC_V2')::integer AS versions,
        (SELECT count(*) FROM public.accounting_destination_routes)::integer AS routes,
        (SELECT count(*) FROM public.journal_entries)::integer AS journal_entries,
        (SELECT count(*) FROM information_schema.columns
             WHERE table_schema='public' AND table_name='journal_entry_lines'
               AND column_name='cost_center_id')::integer AS col_present,
        (SELECT count(*) FROM pg_catalog.pg_constraint
             WHERE conname='fk_journal_lines_cost_center_same_tenant')::integer AS fk_present
      SQL,[$migration]);
    if (!$before || $before->role_name!=='neondb_owner' || $before->db_name!=='neondb'
        || intdiv((int)$before->pg_ver,10000)!==18
        || !in_array((int)$before->migrations,[19,20,21],true)
        || ((int)$before->migrations === 19 && (int)$before->migration_record !== 0)
        || ((int)$before->migrations >= 20 && (int)$before->migration_record !== 1)
        || (int)$before->companies!==3 || (int)$before->users!==3 || (int)$before->members!==8
        || (int)$before->global_reference!==1 || (int)$before->pcge!==3401
        || (int)$before->versions!==3 || (int)$before->routes!==1362) {
        throw new RuntimeException('Estado previo de Neon no coincide.');
    }
    echo "[PASS] Neon dev PG18, 3 empresas, 3 matrices DRAFT, 1.362 rutas, 19/20/21 migraciones.\n";
    if ($mode==='--verify') {
        if ((int)$before->migrations<20 || (int)$before->migration_record!==1
            || (int)$before->col_present!==1 || (int)$before->fk_present!==1) {
            echo "[PENDIENTE] Dimension cost_center_id aun no esta instalada.\n";
            exit(1);
        }
        echo "[PASS] F2.3a: cost_center_id nullable y FK multiempresa instalados.\n";
        exit(0);
    }
    if ((int)$before->migrations>=20) {
        if ((int)$before->col_present!==1 || (int)$before->fk_present!==1) {
            throw new RuntimeException('Migracion aplicada parcialmente.');
        }
        echo "[PASS] Migracion F2.3a ya aplicada, no se reejecuta.\n";
        exit(0);
    }
    if ((int)$before->col_present!==0 || (int)$before->fk_present!==0) {
        throw new RuntimeException('Schema parcial no autorizado.');
    }
    $exit=\Illuminate\Support\Facades\Artisan::call('migrate',[
        '--database'=>'pgsql_migrations','--path'=>$path,
        '--force'=>true,'--no-interaction'=>true,
    ]);
    if ($exit!==0) throw new RuntimeException('Migracion F2.3a fallida.');
    $after=$db->selectOne(<<<'SQL'
      SELECT (SELECT count(*) FROM public.migrations)::integer AS migrations,
        (SELECT count(*) FROM public.migrations WHERE migration=?)::integer AS registered,
        (SELECT count(*) FROM information_schema.columns
           WHERE table_schema='public' AND table_name='journal_entry_lines'
             AND column_name='cost_center_id' AND is_nullable='YES')::integer AS nullable_column,
        (SELECT count(*) FROM pg_catalog.pg_constraint
           WHERE conname='fk_journal_lines_cost_center_same_tenant'
             AND contype='f')::integer AS tenant_fk
      SQL,[$migration]);
    if (!$after || (int)$after->migrations!==20 || (int)$after->registered!==1
        || (int)$after->nullable_column!==1 || (int)$after->tenant_fk!==1) {
        throw new RuntimeException('Post-verify F2.3a fallido.');
    }
    echo "[PASS] F2.3a aplicada: 20 migraciones y FK misma empresa, sin activar POST analitico.\n";
} catch (Throwable $e) {
    fwrite(STDERR,"[BLOQUEO] F2.3a: estado incompatible o migracion fallida. No ejecutar migrate general.\n");
    exit(1);
} finally {
    if (isset($db)) $db->disconnect();
}
