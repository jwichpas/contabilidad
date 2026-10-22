<?php

declare(strict_types=1);

/**
 * F2.2 — Auditoria READ ONLY de instalacion global + integridad por empresa.
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
    echo "[PLAN] READ ONLY: 18 migraciones, catalogos globales y 3 empresas existentes.\n";
    echo "[PLAN] Por empresa: 2966 cuentas, 1214 operativas de 6 digitos, jerarquia sin huerfanos.\n";
    echo "[PLAN] Valida areas, centros, monedas, configuracion, cuentas vinculadas y RLS.\n";
    echo "[PLAN] Identifica destinos automaticos que no resuelven a cuentas operativas.\n";
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
        || (int)$state->migrations!==18
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
    echo "[PASS] Globales: PG18, 18 migraciones, 3401 PCGE, 43 NIC/NIIF, 48 rubros, 3 empresas.\n";

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
                    AND target.is_operational AND length(target.code)=6))::int AS bad_auto_credit
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
            || (int)$row->invalid_cost_center_targets!==0) {
            throw new RuntimeException('Configuracion multiempresa invalida: '.$row->tax_id);
        }
        echo '[PASS] '.$row->tax_id.': 2966 cuentas, 1214 de movimiento (6 digitos), 6 areas, 9 areas NIC7, 2 CC y 2 monedas.'.PHP_EOL;
        if ((int)$row->bad_auto_debit!==0 || (int)$row->bad_auto_credit!==0) {
            echo '[BLOQUEO] '.$row->tax_id.': '.$row->auto_count.' reglas de destino; '
                .$row->bad_auto_debit.' debitos y '.$row->bad_auto_credit.' creditos no resuelven a cuentas operativas.'.PHP_EOL;
        } else {
            echo '[PASS] '.$row->tax_id.': referencias de destino automatico validas.'.PHP_EOL;
        }
    }
    if (count($tenants)!==3) throw new RuntimeException('Cantidad de empresas inesperada.');

    // No afirmar instalacion completa si hay destinos automaticos ambiguos.
    $bad=0;
    foreach ($tenants as $row) $bad+=(int)$row->bad_auto_debit+(int)$row->bad_auto_credit;
    if ($bad!==0) {
        echo '[PENDIENTE] F2.2: normalizar reglas de destino automatico y versionar matriz antes de activar ese motor.'.PHP_EOL;
    }
    $passed=$bad===0;
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
