<?php

declare(strict_types=1);

/**
 * F2.2/F2.3a — Auditoria READ ONLY de datos globales, matriz de rutas v2 y tenants.
 *
 * --plan: ningun SQL.
 * --verify: SELECT sobre contabilidad-dev como neondb_owner dentro de
 *           BEGIN TRANSACTION READ ONLY, ROLLBACK en finally.
 * No modifica empresa, PCGE, ledger de referencia ni claves.
 */
$backend = dirname(__DIR__);
$host = 'ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$mode = $argv[1] ?? '--plan';
if ($argc > 2 || ! in_array($mode, ['--plan','--verify'],true)) {
    fwrite(STDERR,"[FAIL] Uso: php scripts/phase2-neon-tenant-integrity.php --plan|--verify\n");
    exit(2);
}
if ($mode === '--plan') {
    echo "[PLAN] READ ONLY: 19/20/21/22 migraciones, catalogos globales y 3 empresas existentes.\n";
    echo "[PLAN] Por empresa: 2966 cuentas, 1214 operativas de 6 digitos, jerarquia sin huerfanos.\n";
    echo "[PLAN] Valida areas, centros, monedas, configuracion, cuentas vinculadas y RLS.\n";
    echo "[PLAN] Verifica 454 rutas versionadas por empresa; codigos 9411/7911 legacy NO son destinos activos.\n";
    echo "[DRY-RUN] Sin conexion SQL.\n";
    exit(0);
}
if (! is_file($backend.'/.env') || is_file($backend.'/bootstrap/cache/config.php')) {
    fwrite(STDERR,"[BLOQUEO] No hay .env privado o la configuracion de Laravel esta cacheada.\n");
    exit(1);
}
$seen=[];$dups=[];
$lines=file($backend.'/.env',FILE_IGNORE_NEW_LINES);
foreach ($lines===false?[]:$lines as $line) {
    if (! preg_match('/^[ \t]*(?:export[ \t]+)?([A-Z][A-Z0-9_]*)[ \t]*=/', $line,$matches)) continue;
    $key=$matches[1];
    if (! str_starts_with($key,'DB_') && ! str_starts_with($key,'NEON_')
        && ! in_array($key,['APP_ENV','APP_KEY','DATABASE_URL'],true)) continue;
    if (isset($seen[$key])) $dups[]=$key;
    $seen[$key]=true;
}
if ($dups!==[]) {
    fwrite(STDERR,"[BLOQUEO] Claves de .env duplicadas: ".implode(', ',array_unique($dups)).PHP_EOL);
    exit(1);
}
require $backend.'/vendor/autoload.php';
$app=require $backend.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$admin=(array) config('database.connections.pgsql_migrations',[]);
$runtime=(array) config('database.connections.pgsql',[]);
if (! $app->environment('local')
    || config('database.default')!=='pgsql'
    || env('NEON_TEST_BRANCH_NAME','')!=='contabilidad-dev'
    || env('NEON_TEST_BRANCH_HOST','')!==$host
    || (string)($admin['host']??'')!==$host
    || (string)($runtime['host']??'')!==$host
    || (string)($admin['port']??'')!=='5432'
    || (string)($runtime['port']??'')!=='5432'
    || (string)($admin['database']??'')!=='neondb'
    || (string)($runtime['database']??'')!=='neondb'
    || (string)($admin['username']??'')!=='neondb_owner'
    || (string)($runtime['username']??'')!=='app_backend'
    || trim((string)($admin['url']??''))!==''
    || trim((string)($runtime['url']??''))!==''
    || ! in_array((string)($admin['sslmode']??''),['require','verify-ca','verify-full'],true)
    || ! in_array((string)($runtime['sslmode']??''),['require','verify-ca','verify-full'],true)
    || filter_var(env('NEON_USE_POOLING',false),FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR,"[BLOQUEO] Solo lectura Neon contabilidad-dev, PG18 directo.\n");
    exit(1);
}
$db=\Illuminate\Support\Facades\DB::connection('pgsql_migrations');
$pdo=null;$begun=false;$passed=false;
try {
    $pdo=$db->getPdo();
    $pdo->exec('BEGIN TRANSACTION READ ONLY');
    $begun=true;
    $db->selectOne("SELECT set_config('statement_timeout','15000',true) AS v");
    $state=$db->selectOne(<<<'SQL'
        SELECT current_user AS role_name,current_database() AS db_name,
            current_setting('server_version_num')::int AS pg_ver,
            (SELECT count(*) FROM public.migrations)::int AS migrations,
            (SELECT count(*) FROM public.accounting_account_templates)::int AS templates,
            (SELECT count(*) FROM public.reference_data_versions)::int AS ledger,
            (SELECT count(*) FROM public.companies)::int AS companies,
            (SELECT count(*) FROM public.users)::int AS users,
            (SELECT count(*) FROM public.company_user)::int AS memberships,
            (SELECT count(*) FROM public.currencies)::int AS currencies,
            (SELECT count(*) FROM public.accounting_standards)::int AS standards,
            (SELECT count(*) FROM public.financial_statement_rubrics)::int AS rubrics
        SQL);
    if ($state===null || $state->role_name!=='neondb_owner'
        || $state->db_name!=='neondb'
        || intdiv((int)$state->pg_ver,10000)!==18
        || ! in_array((int)$state->migrations,[19,20,21,22],true)
        || (int)$state->templates!==3401
        || (int)$state->ledger!==1
        || (int)$state->companies!==3
        || (int)$state->users!==3
        || (int)$state->memberships!==8
        || (int)$state->currencies!==3
        || (int)$state->standards!==43
        || (int)$state->rubrics!==48) {
        throw new RuntimeException('Identidad o catalogos globales distintos del estado aprobado.');
    }
    echo "[PASS] Globales: PG18, ".(int)$state->migrations." migraciones, 3401 PCGE, 43 NIC/NIIF, 48 rubros, 3 empresas.\n";

    $tenants=$db->select(<<<'SQL'
        SELECT c.id,c.tax_id,
          (SELECT count(*) FROM public.accounting_accounts a WHERE a.company_id=c.id)::int AS accounts,
          (SELECT count(*) FROM public.accounting_accounts a WHERE a.company_id=c.id AND a.is_operational)::int AS operational,
          (SELECT count(*) FROM public.accounting_accounts a WHERE a.company_id=c.id
             AND a.is_operational AND length(a.code)=6)::int AS operative_six,
          (SELECT count(*) FROM public.accounting_accounts a WHERE a.company_id=c.id
             AND a.is_operational AND EXISTS (
                 SELECT 1 FROM public.accounting_accounts ch WHERE ch.parent_id=a.id
             ))::int AS operational_has_children,
          (SELECT count(*) FROM public.accounting_accounts a WHERE a.company_id=c.id
             AND a.parent_id IS NOT NULL AND NOT EXISTS (
                 SELECT 1 FROM public.accounting_accounts parent
                  WHERE parent.id=a.parent_id AND parent.company_id=c.id
             ))::int AS orphan_parent,
          (SELECT count(*) FROM public.company_areas a WHERE a.company_id=c.id)::int AS areas,
          (SELECT count(*) FROM public.cash_flow_areas a WHERE a.company_id=c.id)::int AS cash_flow_areas,
          (SELECT count(*) FROM public.cost_centers a WHERE a.company_id=c.id)::int AS cost_centers,
          (SELECT count(*) FROM public.company_settings s WHERE s.company_id=c.id)::int AS settings,
          (SELECT count(*) FROM public.company_currencies cc WHERE cc.company_id=c.id)::int AS company_currencies,
          (SELECT count(*) FROM public.company_currencies cc WHERE cc.company_id=c.id AND cc.is_base)::int AS base_currencies,
          (SELECT count(*) FROM public.cost_centers cc WHERE cc.company_id=c.id AND (
               NOT EXISTS (SELECT 1 FROM public.accounting_accounts a
                           WHERE a.id=cc.destination_debit_account_id AND a.company_id=c.id
                           AND a.is_operational AND length(a.code)=6)
               OR NOT EXISTS (SELECT 1 FROM public.accounting_accounts a
                           WHERE a.id=cc.destination_credit_account_id AND a.company_id=c.id
                           AND a.is_operational AND length(a.code)=6)
          ))::int AS invalid_cost_center_targets,
          (SELECT count(*) FROM public.accounting_accounts a WHERE a.company_id=c.id
               AND a.has_automatic_destination)::int AS auto_count,
          (SELECT count(*) FROM public.accounting_accounts a WHERE a.company_id=c.id
               AND a.has_automatic_destination
               AND NOT EXISTS(SELECT 1 FROM public.accounting_accounts target
                  WHERE target.company_id=c.id AND target.code=a.destination_debit_code
                    AND target.is_operational AND length(target.code)=6))::int AS bad_auto_debit,
          (SELECT count(*) FROM public.accounting_accounts a WHERE a.company_id=c.id
               AND a.has_automatic_destination
               AND NOT EXISTS(SELECT 1 FROM public.accounting_accounts target
                  WHERE target.company_id=c.id AND target.code=a.destination_credit_code
                    AND target.is_operational AND length(target.code)=6))::int AS bad_auto_credit,
          (SELECT count(*) FROM public.accounting_destination_rule_versions v
              WHERE v.company_id=c.id AND v.version_key='PCGE_2019_CC_V2'
                AND v.status='DRAFT' AND v.strategy='COST_CENTER'
                AND v.source_count=227 AND v.route_count=454)::int AS v2_valid_versions,
          (SELECT count(*) FROM public.accounting_destination_routes r
              JOIN public.accounting_destination_rule_versions v
                ON v.company_id=r.company_id AND v.id=r.rule_version_id
              WHERE v.company_id=c.id AND v.version_key='PCGE_2019_CC_V2')::int AS v2_route_count,
          (SELECT count(*) FROM public.accounting_accounts src
              JOIN public.cost_centers cc ON cc.company_id=src.company_id AND cc.is_active
              LEFT JOIN public.accounting_destination_rule_versions v
                ON v.company_id=src.company_id AND v.version_key='PCGE_2019_CC_V2'
              LEFT JOIN public.accounting_destination_routes r
                ON r.company_id=src.company_id AND r.rule_version_id=v.id
                  AND r.source_account_id=src.id AND r.cost_center_id=cc.id
                  AND r.destination_debit_account_id=cc.destination_debit_account_id
                  AND r.destination_credit_account_id=cc.destination_credit_account_id
              WHERE src.company_id=c.id AND src.has_automatic_destination
                AND src.is_operational AND src.is_active AND length(src.code)=6
                AND r.id IS NULL)::int AS missing_v2_pairs,
          (SELECT count(*) FROM public.accounting_destination_routes r
              JOIN public.accounting_destination_rule_versions v
                ON v.company_id=r.company_id AND v.id=r.rule_version_id
              JOIN public.cost_centers cc ON cc.company_id=r.company_id AND cc.id=r.cost_center_id
              JOIN public.accounting_accounts src ON src.company_id=r.company_id AND src.id=r.source_account_id
              WHERE v.company_id=c.id AND v.version_key='PCGE_2019_CC_V2'
                AND (NOT src.has_automatic_destination OR NOT src.is_operational OR NOT src.is_active
                  OR length(src.code)<>6 OR NOT cc.is_active
                  OR cc.destination_debit_account_id<>r.destination_debit_account_id
                  OR cc.destination_credit_account_id<>r.destination_credit_account_id))::int AS invalid_v2_pairs
        FROM public.companies c ORDER BY c.tax_id
        SQL);
    $expectedTaxIds=['20999990011','20999990012','20999990013'];
    foreach ($tenants as $index=>$row) {
        if (($expectedTaxIds[$index]??null)!==(string)$row->tax_id
            || (int)$row->accounts!==2966
            || (int)$row->operational!==1214
            || (int)$row->operative_six!==1214
            || (int)$row->operational_has_children!==0
            || (int)$row->orphan_parent!==0
            || (int)$row->areas!==6
            || (int)$row->cash_flow_areas!==9
            || (int)$row->cost_centers!==2
            || (int)$row->settings!==1
            || (int)$row->company_currencies!==2
            || (int)$row->base_currencies!==1
            || (int)$row->invalid_cost_center_targets!==0
            || (int)$row->v2_valid_versions!==1
            || (int)$row->v2_route_count!==454
            || (int)$row->missing_v2_pairs!==0
            || (int)$row->invalid_v2_pairs!==0) {
            throw new RuntimeException('Configuracion multiempresa invalida: '.$row->tax_id);
        }
        echo '[PASS] '.$row->tax_id.': 2966 cuentas, 1214 de movimiento (6 digitos), 6 areas, 9 areas NIC7, 2 CC y 2 monedas.'.PHP_EOL;
        echo '[PASS] '.$row->tax_id.': matriz de destinos v2 en DRAFT (227 x 2 = 454 rutas validas).'.PHP_EOL;
        echo '[INFO] '.$row->tax_id.': metadatos legacy 9411/7911 pendientes de retiro, no usados por v2.'.PHP_EOL;
    }
    if (count($tenants)!==3) throw new RuntimeException('Cantidad de empresas inesperada.');

    // La matriz v2 es valida pero sigue DRAFT: no afirmar POST analitico operativo.
    echo '[PASS] Integridad F2.2: tres empresas, matriz v2 coherente y configuracion global.'.PHP_EOL;
    echo '[PENDIENTE] Activacion automatica POST 94/95/79 sigue bloqueada hasta pruebas.'.PHP_EOL;
    $passed=true;
} catch (Throwable $error) {
    // No exponer SQL, credenciales ni consultas con datos de empresas reales.
    fwrite(STDERR,'[FAIL] F2.2: '.($error instanceof RuntimeException
        ? $error->getMessage() : 'consulta de integridad fallida').PHP_EOL);
} finally {
    if ($begun && $pdo!==null) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $error) { $passed=false; }
    }
    $db->disconnect();
}
exit($passed?0:1);
