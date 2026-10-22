<?php

declare(strict_types=1);

/**
 * F2.3c: negative RLS and same-voucher FK checks with actual app_backend role.
 * All temporary vouchers and links run under a single transaction + ROLLBACK.
 * No ACTIVE matrices, no new Neon branch, no seeded persistent data.
 */
$root=dirname(__DIR__);
$host='ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$mode=$argv[1]??'--plan';
if ($argc!==2 || !in_array($mode,['--plan','--probe=contabilidad-dev'],true)) {
    fwrite(STDERR,"[FAIL] Uso: --plan|--probe=contabilidad-dev\n");exit(2);
}
if ($mode==='--plan') {
    echo "[PLAN] F2.3c: usuario PostgreSQL app_backend real: read isolation A/B/C y INSERT cross-tenant denegado.\n";
    echo "[PLAN] Insertar asientos/links ficticios dentro de la transaccion, probar FK same-voucher/UNIQUE.\n";
    echo "[PLAN] UPDATE/DELETE denegados, FORCE RLS y permisos. Todo termina en ROLLBACK.\n";
    echo "[AVISO] Asientos usados son ficticios, sin cambiar matrices DRAFT ni configuracion global.\n";
    echo "[DRY-RUN] Ninguna conexion SQL ni escritura.\n";exit(0);
}
if (!is_file($root.'/.env')||is_file($root.'/bootstrap/cache/config.php')) {
    fwrite(STDERR,"[BLOQUEO] Falta .env o existe config cacheada.\n");exit(1);
}
$seen=[];
foreach (file($root.'/.env',FILE_IGNORE_NEW_LINES)?:[] as $line) {
    if (!preg_match('/^[ \t]*(?:export[ \t]+)?([A-Z][A-Z0-9_]*)[ \t]*=/',$line,$m))continue;
    $key=$m[1];
    if (!str_starts_with($key,'DB_')&&!str_starts_with($key,'NEON_')
        && !in_array($key,['APP_ENV','APP_KEY','DATABASE_URL'],true))continue;
    if (isset($seen[$key])){fwrite(STDERR,"[BLOQUEO] Clave duplicada .env.\n");exit(1);}
    $seen[$key]=true;
}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$admin=(array)config('database.connections.pgsql_migrations',[]);
$traffic=(array)config('database.connections.pgsql',[]);
if (!$app->environment('local')||config('database.default')!=='pgsql'
    ||env('NEON_TEST_BRANCH_NAME','')!=='contabilidad-dev'
    ||env('NEON_TEST_BRANCH_HOST','')!==$host
    ||($admin['host']??'')!==$host||($traffic['host']??'')!==$host
    ||(string)($admin['port']??'')!=='5432'||(string)($traffic['port']??'')!=='5432'
    ||($admin['database']??'')!=='neondb'||($traffic['database']??'')!=='neondb'
    ||($admin['username']??'')!=='neondb_owner'||($traffic['username']??'')!=='app_backend'
    ||trim((string)($admin['url']??''))!==''||trim((string)($traffic['url']??''))!==''
    ||!in_array((string)($admin['sslmode']??''),['require','verify-ca','verify-full'],true)
    ||!in_array((string)($traffic['sslmode']??''),['require','verify-ca','verify-full'],true)
    ||filter_var(env('NEON_USE_POOLING',false),FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR,"[BLOQUEO] Solo Neon DEV PG18 no pooled, admin + app_backend.\n");exit(1);
}

use App\Models\FiscalPeriod;
use App\Models\JournalEntryLine;
use App\Services\Accounting\PostJournalEntryService;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

$adminDb=DB::connection('pgsql_migrations');
$database=DB::connection('pgsql');
$begun=false;$success=false;
try {
    $guard=$adminDb->selectOne(<<<'SQL'
      SELECT current_database() AS db_name,
       current_setting('server_version_num')::integer AS pg_ver,
       (SELECT count(*) FROM public.migrations)::integer AS migrations,
       (SELECT count(*) FROM public.companies)::integer AS companies,
       (SELECT count(*) FROM public.company_user)::integer AS members,
       (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='DRAFT')::integer AS draft_versions,
       (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='ACTIVE')::integer AS active_versions,
       (SELECT count(*) FROM public.accounting_destination_routes)::integer AS routes,
       (SELECT count(*) FROM public.journal_entries)::integer AS entries,
       (SELECT count(*) FROM public.journal_analytic_allocations)::integer AS allocations,
       (SELECT count(*) FROM pg_roles WHERE rolname='app_backend' AND rolcanlogin
           AND NOT rolbypassrls AND NOT rolsuper AND NOT rolcreatedb AND NOT rolcreaterole)::integer AS restricted_role
      SQL);
    if (!$guard||$guard->db_name!=='neondb'||intdiv((int)$guard->pg_ver,10000)!==18
        ||(int)$guard->migrations!==21||(int)$guard->companies!==3||(int)$guard->members!==8
        ||(int)$guard->draft_versions!==3||(int)$guard->active_versions!==0
        ||(int)$guard->routes!==1362||(int)$guard->entries!==0||(int)$guard->allocations!==0
        ||(int)$guard->restricted_role!==1) {
        throw new RuntimeException('Estado inicial de desarrollo incompatible con F2.3c.');
    }
    $companies=$adminDb->select('SELECT id,tax_id FROM public.companies ORDER BY tax_id');
    if (array_column($companies,'tax_id')!==['20999990011','20999990012','20999990013']) {
        throw new RuntimeException('Empresas demo no coinciden.');
    }
    [$A,$B,$C]=array_map(static fn($co)=>(int)$co->id,$companies);
    $database->beginTransaction();$begun=true;
    $database->selectOne("SELECT set_config('statement_timeout','20000',true)");
    $role=$database->selectOne('SELECT current_user AS role_name');
    if (!$role||$role->role_name!=='app_backend')throw new RuntimeException('No se conecto como app_backend.');
    $tenant=app(TenantContext::class);
    $service=app(PostJournalEntryService::class);
    $must=static function(bool $condition,string $error):void {
        if(!$condition)throw new RuntimeException($error);
    };
    $expectSql=static function(callable $action,string $state) use($database):void {
        static $savepoint=0;
        $name='f23c_negative_'.++$savepoint;
        $database->statement('SAVEPOINT '.$name);
        $seenState=null;
        try { $action(); }
        catch(\Throwable $ex) { $seenState=(string)($ex->errorInfo[0]??$ex->getCode()); }
        finally {
            $database->statement('ROLLBACK TO SAVEPOINT '.$name);
            $database->statement('RELEASE SAVEPOINT '.$name);
        }
        if ($seenState!==$state) {
            throw new RuntimeException('SQLSTATE inesperado: se esperaba '.$state.' en prueba negativa.');
        }
    };

    // Build a fixture voucher per company. No version ACTIVE => 2 ordinary
    // lines from service. Add two balanced lines for a transient lineage test.
    $build=static function(int $companyId,string $number) use($tenant,$database,$service,$must):array {
        return $tenant->run($companyId,static function()use($companyId,$number,$database,$service,$must):array {
            $route=$database->selectOne(<<<'SQL'
              SELECT r.source_account_id,r.cost_center_id,r.destination_debit_account_id,
                  r.destination_credit_account_id,r.rule_version_id
              FROM public.accounting_destination_routes r
              JOIN public.accounting_accounts a
                ON a.company_id=r.company_id AND a.id=r.source_account_id
              JOIN public.cost_centers cc
                ON cc.company_id=r.company_id AND cc.id=r.cost_center_id
              WHERE r.company_id=? AND a.is_operational AND a.is_active
                AND a.has_automatic_destination AND a.requires_cost_center
                AND a.account_type='EXPENSE' AND a.code LIKE '6%'
                AND cc.code='CC-ADM' AND cc.is_active
              ORDER BY a.code LIMIT 1
              SQL,[$companyId]);
            $offset=$database->selectOne(<<<'SQL'
              SELECT id FROM public.accounting_accounts WHERE company_id=?
                AND is_operational AND is_active AND NOT requires_cost_center
                AND code LIKE '4%' AND length(code)=6
              ORDER BY code LIMIT 1
              SQL,[$companyId]);
            $actor=$database->selectOne(<<<'SQL'
              SELECT user_id FROM public.company_user WHERE company_id=?
                AND is_active ORDER BY user_id LIMIT 1
              SQL,[$companyId]);
            if(!$route||!$offset||!$actor)throw new RuntimeException('Faltan fixtures DEMO de '.$companyId);
            $period=FiscalPeriod::query()->create([
                'year'=>2026,'month'=>10,'start_date'=>'2026-10-01',
                'end_date'=>'2026-10-31','status'=>'OPEN',
            ]);
            $posted=$service->post([
                'fiscal_period_id'=>(int)$period->id,
                'entry_date'=>'2026-10-22',
                'entry_type'=>'STANDARD','entry_number'=>$number,
                'currency'=>'PEN','exchange_rate'=>'4.000000',
                'exchange_rate_type'=>'SELL',
                'description'=>'F2.3c RLS rollback only',
                'created_by_user_id'=>(int)$actor->user_id,
                'lines'=>[
                    ['accounting_account_id'=>(int)$route->source_account_id,
                     'cost_center_id'=>(int)$route->cost_center_id,
                     'debit_local'=>'100.0000','credit_local'=>'0.0000',
                     'debit_foreign'=>'25.0000','credit_foreign'=>'0.0000'],
                    ['accounting_account_id'=>(int)$offset->id,
                     'cost_center_id'=>null,
                     'debit_local'=>'0.0000','credit_local'=>'100.0000',
                     'debit_foreign'=>'0.0000','credit_foreign'=>'25.0000'],
                ],
            ]);
            $must($posted->lines->count()===2,'DRAFT publico asientos automaticos inesperadamente.');
            $source=$posted->lines->sortBy('line_number')->first();
            $base=[
                'journal_entry_id'=>(int)$posted->id,
                'cost_center_id'=>(int)$route->cost_center_id,
                'exchange_rate'=>'4.000000',
            ];
            $debit=JournalEntryLine::query()->create([
                ...$base,'line_number'=>3,
                'accounting_account_id'=>(int)$route->destination_debit_account_id,
                'description'=>'F2.3c transient 94/95',
                'debit_local'=>'100.0000','credit_local'=>'0.0000',
                'debit_foreign'=>'25.0000','credit_foreign'=>'0.0000',
            ]);
            $credit=JournalEntryLine::query()->create([
                ...$base,'line_number'=>4,
                'accounting_account_id'=>(int)$route->destination_credit_account_id,
                'description'=>'F2.3c transient 79',
                'debit_local'=>'0.0000','credit_local'=>'100.0000',
                'debit_foreign'=>'0.0000','credit_foreign'=>'25.0000',
            ]);
            $link=[
                'company_id'=>$companyId,
                'journal_entry_id'=>(int)$posted->id,
                'source_line_id'=>(int)$source->id,
                'debit_line_id'=>(int)$debit->id,
                'credit_line_id'=>(int)$credit->id,
                'rule_version_id'=>(int)$route->rule_version_id,
                'cost_center_id'=>(int)$route->cost_center_id,
            ];
            $database->table('journal_analytic_allocations')->insert($link);
            $must((int)$database->table('journal_analytic_allocations')
                ->where('journal_entry_id',$posted->id)->count()===1,
                'app_backend no pudo INSERT/SELECT en su tenant.');
            // Flush deferred triggers while this company's RLS GUC is active.
            $database->statement('SET CONSTRAINTS ALL IMMEDIATE');
            $database->statement('SET CONSTRAINTS ALL DEFERRED');
            return $link;
        });
    };

    $linkA=$build($A,'F23C-RLS-A');
    $linkB=$build($B,'F23C-RLS-B');
    echo "[PASS] app_backend INSERT/SELECT valido en A y B; DRAFT conserva POST manual.\n";

    $must((int)$database->table('journal_analytic_allocations')->count()===0,
        'Sin contexto tenant debe ver cero asignaciones.');
    $tenant->run($A,static function()use(
        $database,$must,$expectSql,$linkA,$linkB,$A,$B
    ):void {
        $must((int)$database->table('journal_analytic_allocations')->count()===1,
            'A debe ver unicamente su registro.');
        $must((int)$database->table('journal_analytic_allocations')
            ->where('company_id',$B)->count()===0,
            'A pudo leer asignacion de B.');
        $must((int)$database->table('journal_entries')
            ->where('company_id',$B)->count()===0,
            'A pudo leer asiento de B.');
        $expectSql(static function()use($database,$linkB):void {
            $database->table('journal_analytic_allocations')->insert($linkB);
        },'42501');
        $expectSql(static function()use($database,$linkA,$linkB):void {
            $illegal=$linkA;
            $illegal['source_line_id']=$linkB['source_line_id'];
            $database->table('journal_analytic_allocations')->insert($illegal);
        },'23503');
        $expectSql(static function()use($database,$linkA):void {
            $database->table('journal_analytic_allocations')->insert($linkA);
        },'23505');
        $expectSql(static function()use($database,$linkA):void {
            $database->table('journal_analytic_allocations')
                ->where('company_id',$linkA['company_id'])
                ->update(['cost_center_id'=>$linkA['cost_center_id']]);
        },'42501');
        $expectSql(static function()use($database,$linkA):void {
            $database->table('journal_analytic_allocations')
                ->where('company_id',$linkA['company_id'])->delete();
        },'42501');
    });
    echo "[PASS] RLS A: B invisible; INSERT cruzado 42501; FK 23503; UNIQUE 23505; UPDATE/DELETE 42501.\n";

    $tenant->run($B,static function()use($database,$must,$A):void {
        $must((int)$database->table('journal_analytic_allocations')->count()===1,
            'B debe ver unicamente su registro.');
        $must((int)$database->table('journal_analytic_allocations')
            ->where('company_id',$A)->count()===0,
            'B pudo leer asignacion de A.');
    });
    $tenant->run($C,static function()use($database,$must):void {
        $must((int)$database->table('journal_analytic_allocations')->count()===0,
            'C pudo ver asignaciones A o B.');
    });
    echo "[PASS] RLS B/C y sin tenant: aislamiento de lectura estricto.\n";
    $database->statement('SET CONSTRAINTS ALL IMMEDIATE');
    echo "[PASS] PostgreSQL triggers diferidos para vouchers ficticios con 4 partidas.\n";
    $success=true;
} catch(\Throwable $error) {
    fwrite(STDERR,"[FAIL] F2.3c: ".$error->getMessage()."\n");
} finally {
    if($begun){
        try{$database->rollBack();echo "[ROLLBACK] Datos ficticios de app_backend revertidos.\n";}
        catch(\Throwable){$success=false;fwrite(STDERR,"[BLOQUEO] No se pudo revertir transaccion.\n");}
    }
    $database->disconnect();
}
if($success){
    try {
        $after=$adminDb->selectOne(<<<'SQL'
          SELECT (SELECT count(*) FROM public.journal_entries)::integer AS entries,
                 (SELECT count(*) FROM public.journal_entry_lines)::integer AS lines,
                 (SELECT count(*) FROM public.journal_analytic_allocations)::integer AS links,
                 (SELECT count(*) FROM public.fiscal_periods)::integer AS periods,
                 (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='DRAFT')::integer AS drafts,
                 (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='ACTIVE')::integer AS active
          SQL);
        $success=$after && (int)$after->entries===0&&(int)$after->lines===0
            &&(int)$after->links===0&&(int)$after->periods===0
            &&(int)$after->drafts===3&&(int)$after->active===0;
        echo $success?"[PASS] POST-ROLLBACK: cero asientos/partidas/links/periodos; 3 DRAFT.\n"
            :"[BLOQUEO] El estado despues de ROLLBACK no coincide.\n";
    }catch(\Throwable){$success=false;fwrite(STDERR,"[BLOQUEO] No se pudo verificar estado post-rollback.\n");}
}
$adminDb->disconnect();
exit($success?0:1);
