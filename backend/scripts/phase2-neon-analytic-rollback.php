<?php

declare(strict_types=1);

/**
 * F2.3b rollback-only integration probe against EXISTING Neon DEV demo data.
 * Uses the administrative connection for a SINGLE OUTER transaction, sets a
 * version ACTIVE only inside that transaction, tests actual Post/Reverse services,
 * forces deferred constraints and rolls EVERYTHING back in finally.
 *
 * The admin connection bypasses RLS. Separate app_backend RLS negative tests
 * are still required before real activation. The sequence values may advance.
 */
$backend=dirname(__DIR__);
$host='ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$mode=$argv[1]??'--plan';
if ($argc!==2 || !in_array($mode,['--plan','--probe=contabilidad-dev'],true)) {
    fwrite(STDERR,"[FAIL] Usage: --plan|--probe=contabilidad-dev\n");exit(2);
}
if ($mode==='--plan') {
    echo "[PLAN] F2.3b: probar servicios Laravel POST/REVERSE sobre matriz ACTIVE solo en transaccion administrativa.\n";
    echo "[PLAN] CC-ADM y CC-VTA; asiento de ajuste Haber 6; 94/95-79; exactitud bimoneda; links y extorno.\n";
    echo "[PLAN] Rechazar centro cruzado y centro ausente; validar triggers diferidos y ROLLBACK.\n";
    echo "[AVISO] El probe usa rol owner para poder activar DRAFT temporalmente; NO certifica RLS app_backend.\n";
    echo "[DRY-RUN] Ninguna conexion SQL ni escritura.\n";exit(0);
}
if (!is_file($backend.'/.env') || is_file($backend.'/bootstrap/cache/config.php')) {
    fwrite(STDERR,"[BLOQUEO] Se necesita .env sin cache Laravel.\n");exit(1);
}
$seen=[];
foreach (file($backend.'/.env',FILE_IGNORE_NEW_LINES)?:[] as $line) {
    if (!preg_match('/^[ \t]*(?:export[ \t]+)?([A-Z][A-Z0-9_]*)[ \t]*=/',$line,$m))continue;
    $key=$m[1];
    if (!str_starts_with($key,'DB_')&&!str_starts_with($key,'NEON_')
        && !in_array($key,['APP_ENV','APP_KEY','DATABASE_URL'],true))continue;
    if (isset($seen[$key])){fwrite(STDERR,"[BLOQUEO] Clave duplicada en .env.\n");exit(1);}
    $seen[$key]=true;
}
require $backend.'/vendor/autoload.php';
$app=require $backend.'/bootstrap/app.php';
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
    fwrite(STDERR,"[BLOQUEO] Solo owner Neon contabilidad-dev PG18 directo.\n");exit(1);
}

use App\Models\FiscalPeriod;
use App\Services\Accounting\PostJournalEntryService;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

$manager=app('db');
$previousDefault=$manager->getDefaultConnection();
$manager->setDefaultConnection('pgsql_migrations');
$db=DB::connection('pgsql_migrations');
$begun=false;$ok=false;
try {
    $db->beginTransaction();
    $begun=true;
    $db->selectOne("SELECT set_config('statement_timeout','20000',true)");
    $guard=$db->selectOne(<<<'SQL'
        SELECT current_user AS role_name,current_database() AS database_name,
          current_setting('server_version_num')::integer AS server_version,
          (SELECT count(*) FROM public.migrations)::integer AS migrations,
          (SELECT count(*) FROM public.companies)::integer AS companies,
          (SELECT count(*) FROM public.company_user)::integer AS memberships,
          (SELECT count(*) FROM public.journal_entries)::integer AS entries,
          (SELECT count(*) FROM public.journal_entry_lines)::integer AS lines,
          (SELECT count(*) FROM public.journal_analytic_allocations)::integer AS allocations,
          (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='DRAFT')::integer AS drafts,
          (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='ACTIVE')::integer AS active_rules,
          (SELECT count(*) FROM public.accounting_destination_routes)::integer AS routes
        SQL);
    if (!$guard||$guard->role_name!=='neondb_owner'||$guard->database_name!=='neondb'
        ||intdiv((int)$guard->server_version,10000)!==18
        ||!in_array((int)$guard->migrations,[21,22],true)||(int)$guard->companies!==3
        ||(int)$guard->memberships!==8||(int)$guard->entries!==0
        ||(int)$guard->lines!==0||(int)$guard->allocations!==0
        ||(int)$guard->drafts!==3||(int)$guard->active_rules!==0||(int)$guard->routes!==1362) {
        throw new RuntimeException('Precondiciones: se requieren 21 o 22 migraciones, tres matrices DRAFT y 0 asientos.');
    }
    echo "[PASS] Neon dev PG18, 21/22 migraciones, matrices DRAFT, cero asientos.\n";
    $companies=$db->select('SELECT id,tax_id FROM public.companies ORDER BY tax_id');
    if (array_column($companies,'tax_id')!==['20999990011','20999990012','20999990013']) {
        throw new RuntimeException('Empresas demo inesperadas.');
    }
    $companyA=(int)$companies[0]->id;
    $companyB=(int)$companies[1]->id;
    $fixtures=$db->select(<<<'SQL'
        SELECT cc.id AS center_id,cc.code AS center_code,
               src.id AS source_account_id,src.code AS source_code,
               deb.code AS debit_code,cred.code AS credit_code,
               v.id AS version_id
        FROM public.accounting_destination_routes r
        JOIN public.accounting_destination_rule_versions v
          ON v.company_id=r.company_id AND v.id=r.rule_version_id
          AND v.status='DRAFT' AND v.version_key='PCGE_2019_CC_V2'
        JOIN public.cost_centers cc
          ON cc.company_id=r.company_id AND cc.id=r.cost_center_id
          AND cc.is_active AND cc.code IN ('CC-ADM','CC-VTA')
        JOIN public.accounting_accounts src
          ON src.company_id=r.company_id AND src.id=r.source_account_id
          AND src.is_operational AND src.is_active AND src.has_automatic_destination
          AND src.requires_cost_center AND src.account_type='EXPENSE'
        JOIN public.accounting_accounts deb
          ON deb.company_id=r.company_id AND deb.id=r.destination_debit_account_id
        JOIN public.accounting_accounts cred
          ON cred.company_id=r.company_id AND cred.id=r.destination_credit_account_id
        WHERE r.company_id=? AND src.code LIKE '6%'
        ORDER BY cc.code,src.code
        SQL,[$companyA]);
    $byCenter=[];
    foreach ($fixtures as $fixture) {
        $byCenter[$fixture->center_code]??=$fixture;
    }
    if (count($byCenter)!==2
        ||$byCenter['CC-ADM']->debit_code!=='941100'
        ||$byCenter['CC-VTA']->debit_code!=='951100'
        ||$byCenter['CC-ADM']->credit_code!=='791000'
        ||$byCenter['CC-VTA']->credit_code!=='791000') {
        throw new RuntimeException('Destinos de CC-ADM o CC-VTA invalidos.');
    }
    $versionId=(int)$byCenter['CC-ADM']->version_id;
    $counterpart=$db->selectOne(<<<'SQL'
      SELECT id,code FROM public.accounting_accounts
      WHERE company_id=? AND code LIKE '4%' AND is_operational
         AND is_active AND length(code)=6 AND NOT requires_cost_center
      ORDER BY code LIMIT 1
      SQL,[$companyA]);
    $otherCenter=$db->selectOne(<<<'SQL'
      SELECT id FROM public.cost_centers WHERE company_id=? AND is_active ORDER BY id LIMIT 1
      SQL,[$companyB]);
    $membership=$db->selectOne(<<<'SQL'
      SELECT user_id FROM public.company_user
      WHERE company_id=? AND is_active ORDER BY user_id LIMIT 1
      SQL,[$companyA]);
    if (!$counterpart||!$otherCenter||!$membership) {
        throw new RuntimeException('Faltan miembros o cuentas operativas demo.');
    }
    $updated=$db->table('accounting_destination_rule_versions')
        ->where('company_id',$companyA)
        ->where('id',$versionId)->where('status','DRAFT')
        ->update(['status'=>'ACTIVE']);
    if ($updated!==1)throw new RuntimeException('No se pudo activar regla transitoria.');

    $tenant=app(TenantContext::class);
    $service=app(PostJournalEntryService::class);
    $check=static function(bool $condition,string $failure):void {
        if (!$condition)throw new RuntimeException($failure);
    };
    $createdIds=[];
    $tenant->run($companyA,function () use (
        $service,$db,$tenant,$companyA,$byCenter,$counterpart,$membership,
        $check,&$createdIds,$otherCenter
    ):void {
        $period=FiscalPeriod::query()->create([
            'year'=>2026,'month'=>10,'start_date'=>'2026-10-01',
            'end_date'=>'2026-10-31','status'=>'OPEN',
        ]);
        $base=[
            'fiscal_period_id'=>(int)$period->id,
            'entry_date'=>'2026-10-22',
            'entry_type'=>'STANDARD',
            'currency'=>'PEN','exchange_rate'=>'3.750000',
            'exchange_rate_type'=>'SELL',
            'description'=>'F2.3b - rollback only',
            'created_by_user_id'=>(int)$membership->user_id,
        ];
        $makePayload=static function(object $center,string $number,bool $isCredit=false) use($base,$counterpart):array {
            $main=[
                'accounting_account_id'=>(int)$center->source_account_id,
                'cost_center_id'=>(int)$center->center_id,
                'debit_local'=>$isCredit?'0.0000':'100.0000',
                'credit_local'=>$isCredit?'100.0000':'0.0000',
                'debit_foreign'=>$isCredit?'0.0000':'26.6667',
                'credit_foreign'=>$isCredit?'26.6667':'0.0000',
            ];
            $offset=[
                'accounting_account_id'=>(int)$counterpart->id,
                'cost_center_id'=>null,
                'debit_local'=>$main['credit_local'],
                'credit_local'=>$main['debit_local'],
                'debit_foreign'=>$main['credit_foreign'],
                'credit_foreign'=>$main['debit_foreign'],
            ];
            return [...$base,'entry_number'=>$number,'lines'=>[$main,$offset]];
        };
        foreach (['CC-ADM','CC-VTA'] as $centerCode) {
            $fixture=$byCenter[$centerCode];
            $created=$service->post($makePayload($fixture,'F23B-'.$centerCode));
            $createdIds[$centerCode]=(int)$created->id;
            $check($created->lines->count()===4,'El POST no produjo dos lineas analiticas.');
            $lines=$created->lines->sortBy('line_number')->values();
            $codes=[];
            foreach ($lines as $line) {
                $codes[]=(string)$db->table('accounting_accounts')->where('id',$line->accounting_account_id)->value('code');
            }
            $check($codes[0]===$fixture->source_code
                &&$codes[2]===$fixture->debit_code&&$codes[3]==='791000',
                'La ruta efectiva difiere de 6xx -> 94/95-79.');
            $check((string)$lines[2]->debit_local==='100.0000'
                &&(string)$lines[3]->credit_local==='100.0000',
                'Importes analiticos Debe/Haber incorrectos.');
            $check((string)$lines[2]->debit_foreign==='26.6667'
                &&(string)$lines[3]->credit_foreign==='26.6667',
                'Importes bimoneda analiticos incorrectos.');
            $link=$db->table('journal_analytic_allocations')
                ->where('journal_entry_id',$created->id)->sole();
            $check((int)$link->source_line_id===(int)$lines[0]->id
                &&(int)$link->debit_line_id===(int)$lines[2]->id
                &&(int)$link->credit_line_id===(int)$lines[3]->id,
                'Trazabilidad 6xx al par 94/95-79 incorrecta.');
        }
        echo "[PASS] POST CC-ADM y CC-VTA: 4 lineas, montos bimoneda y enlaces auditables.\n";

        $credit=$service->post($makePayload($byCenter['CC-VTA'],'F23B-CREDIT',true));
        $clines=$credit->lines->sortBy('line_number')->values();
        $debitCode=(string)$db->table('accounting_accounts')
            ->where('id',$clines[2]->accounting_account_id)->value('code');
        $creditCode=(string)$db->table('accounting_accounts')
            ->where('id',$clines[3]->accounting_account_id)->value('code');
        $check($debitCode==='791000'&&$creditCode==='951100',
            'Ajuste al Haber 6xx no invirtio 79/95.');
        echo "[PASS] Ajuste Haber 6xx: Debe 791000, Haber 951100.\n";

        $invalid=$makePayload($byCenter['CC-ADM'],'F23B-FOREIGN-CENTER');
        $invalid['lines'][0]['cost_center_id']=(int)$otherCenter->id;
        $rejected=false;
        try{$service->post($invalid);}catch(\DomainException){$rejected=true;}
        $check($rejected,'No rechazo centro de costos ajeno a la empresa.');
        $invalid=$makePayload($byCenter['CC-ADM'],'F23B-NO-CENTER');
        $invalid['lines'][0]['cost_center_id']=null;
        $rejected=false;
        try{$service->post($invalid);}catch(\DomainException){$rejected=true;}
        $check($rejected,'No rechazo centro de costos requerido y omitido.');
        echo "[PASS] POST rechaza centro cruzado y centro obligatorio omitido.\n";

        $originalId=$createdIds['CC-ADM'];
        $originalLines=$db->table('journal_entry_lines')
            ->where('journal_entry_id',$originalId)->orderBy('line_number')->get();
        $reversal=$service->reverse(
            $originalId,(int)$period->id,'F23B-REVERSE','2026-10-22',
            'Extorno de prueba en rollback',(int)$membership->user_id
        );
        $check($reversal->lines->count()===4,'Extorno no preserva cuatro lineas.');
        $newLines=$reversal->lines->sortBy('line_number')->values();
        foreach ($originalLines as $i=>$line) {
            $rev=$newLines[$i];
            $check((string)$rev->debit_local===(string)$line->credit_local
                &&(string)$rev->credit_local===(string)$line->debit_local
                &&(string)$rev->debit_foreign===(string)$line->credit_foreign
                &&(string)$rev->credit_foreign===(string)$line->debit_foreign,
                'Extorno no invierte los montos exactamente.');
        }
        $origLink=$db->table('journal_analytic_allocations')
            ->where('journal_entry_id',$originalId)->sole();
        $reverseLink=$db->table('journal_analytic_allocations')
            ->where('journal_entry_id',$reversal->id)->sole();
        $check((int)$reverseLink->source_line_id===(int)$newLines[0]->id
            &&(int)$reverseLink->debit_line_id===(int)$newLines[3]->id
            &&(int)$reverseLink->credit_line_id===(int)$newLines[2]->id
            &&(int)$reverseLink->rule_version_id===(int)$origLink->rule_version_id,
            'Extorno no preserva/invierte la trazabilidad.');
        echo "[PASS] Extorno exacto de 4 lineas y linkage versionado invertido.\n";
        $db->statement('SET CONSTRAINTS ALL IMMEDIATE');
        $totals=$db->select(<<<'SQL'
          SELECT journal_entry_id,
             sum(debit_local)-sum(credit_local) AS diff_local,
             sum(debit_foreign)-sum(credit_foreign) AS diff_foreign
          FROM public.journal_entry_lines
          WHERE company_id=?
          GROUP BY journal_entry_id
          SQL,[$companyA]);
        $check(count($totals)===4,'Se esperaban 4 asientos incluyendo extorno.');
        foreach($totals as $row){
            $check((string)$row->diff_local==='0.0000'&&(string)$row->diff_foreign==='0.0000',
                'Asiento no balanceado en PostgreSQL.');
        }
        echo "[PASS] Triggers diferidos y partida doble exacta en 4 asientos.\n";
    });
    $ok=true;
} catch(\Throwable $e) {
    fwrite(STDERR,"[FAIL] F2.3b PROBE: ".$e->getMessage()."\n");
} finally {
    if($begun){try{$db->rollBack();echo "[ROLLBACK] Transaccion administrativa F2.3b revertida.\n";}
        catch(\Throwable $rollbackError){$ok=false;fwrite(STDERR,"[BLOQUEO] Fallo ROLLBACK.\n");}}
    $manager->setDefaultConnection($previousDefault);
    $db->disconnect();
}
if($ok){
    // Independently verify no traces survived and demo matrices are still DRAFT.
    try{
        $verify=DB::connection('pgsql_migrations')->selectOne(<<<'SQL'
          SELECT (SELECT count(*) FROM public.journal_entries)::integer AS entries,
                 (SELECT count(*) FROM public.journal_entry_lines)::integer AS lines,
                 (SELECT count(*) FROM public.journal_analytic_allocations)::integer AS allocations,
                 (SELECT count(*) FROM public.fiscal_periods)::integer AS periods,
                 (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='ACTIVE')::integer AS active,
                 (SELECT count(*) FROM public.accounting_destination_rule_versions WHERE status='DRAFT')::integer AS drafts
          SQL);
        $ok=$verify&&(int)$verify->entries===0&&(int)$verify->lines===0
            &&(int)$verify->allocations===0&&(int)$verify->periods===0
            &&(int)$verify->active===0&&(int)$verify->drafts===3;
        echo $ok?"[PASS] POST-ROLLBACK: 0 asientos, 0 asignaciones, 3 matrices DRAFT.\n"
            :"[BLOQUEO] La verificacion POST-ROLLBACK no coincide.\n";
    }catch(\Throwable){$ok=false;fwrite(STDERR,"[BLOQUEO] No se pudo verificar limpieza despues de ROLLBACK.\n");}
}
exit($ok?0:1);
