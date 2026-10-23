<?php

declare(strict_types=1);

/**
 * F3.5 — first journal through HTTP on persistent company ID 4.
 * Explicit one-time persisted fictitious journal on Neon DEV. Roll back on failure.
 */
$mode = $argv[1] ?? '--plan';
if ($argc !== 2 || ! in_array($mode, ['--plan', '--apply=contabilidad-dev'], true)) {
    fwrite(STDERR, "[USO] --plan | --apply=contabilidad-dev\n"); exit(2);
}
if ($mode === '--plan') {
    echo "[PLAN] F3.5: empresa persistente 4, apertura HTTP de periodo y asiento PEN/USD balanceado.\n";
    echo "[PLAN] Verifica 201, idempotencia 200/409, auditoria, RLS y rollback total.\n";
    echo "[PLAN] La opción apply persiste periodo, asiento, FX, auditoria y recibo de prueba.\n"; exit(0);
}
$root = dirname(__DIR__);
if (! is_file($root.'/.env') || ! is_file($root.'/vendor/autoload.php')
    || is_file($root.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "[BLOQUEO] Requiere Laravel .env privado, vendor y config sin cache.\n"); exit(1);
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$host = 'ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$runtime = (array) config('database.connections.pgsql', []);
$adminConfig = (array) config('database.connections.pgsql_migrations', []);
$safe = static fn (array $c, string $role): bool =>
    ($c['host'] ?? null) === $host && (string) ($c['port'] ?? '') === '5432'
    && ($c['database'] ?? null) === 'neondb' && ($c['username'] ?? null) === $role
    && trim((string) ($c['url'] ?? '')) === ''
    && in_array((string) ($c['sslmode'] ?? ''), ['require', 'verify-ca', 'verify-full'], true);
if (! app()->environment('local') || config('database.default') !== 'pgsql'
    || env('NEON_TEST_BRANCH_NAME') !== 'contabilidad-dev'
    || env('NEON_TEST_BRANCH_HOST') !== $host
    || ! $safe($runtime, 'app_backend') || ! $safe($adminConfig, 'neondb_owner')
    || filter_var(env('NEON_USE_POOLING', false), FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, "[BLOQUEO] Solo Neon contabilidad-dev directo con SSL y roles previstos.\n"); exit(1);
}
$old = [
    'cache.default' => config('cache.default'),
    'session.driver' => config('session.driver'),
    'queue.default' => config('queue.default'),
];
config(['cache.default'=>'array','session.driver'=>'array','queue.default'=>'sync']);
$db = \Illuminate\Support\Facades\DB::connection('pgsql');
$owner = \Illuminate\Support\Facades\DB::connection('pgsql_migrations');
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$companyId = 4; $started = false; $success = false; $committed = false; $journalId = 0; $phase = 'preflight';
$check = static function (bool $pass, string $message): void {
    if (! $pass) throw new \RuntimeException($message);
};
try {
    $identity = $owner->selectOne(
        "SELECT current_database() AS db, current_user AS actor, current_setting('server_version_num')::integer AS pg"
    );
    $check($identity && $identity->db === 'neondb' && $identity->actor === 'neondb_owner'
        && intdiv((int)$identity->pg, 10000) === 18, 'ADMIN no es Neon PG18 DEV.');
    $check($db->selectOne('SELECT current_user AS actor')?->actor === 'app_backend',
        'APP no usa app_backend.');
    $baseline = $owner->selectOne(<<<'SQL'
        SELECT
          (SELECT count(*) FROM public.companies)::integer AS companies,
          (SELECT count(*) FROM public.journal_entries)::integer AS entries,
          (SELECT count(*) FROM public.journal_entry_lines)::integer AS lines,
          (SELECT count(*) FROM public.fiscal_periods)::integer AS periods,
          (SELECT count(*) FROM public.exchange_rates)::integer AS rates,
          (SELECT count(*) FROM public.journal_post_idempotency)::integer AS receipts,
          (SELECT count(*) FROM public.audit_logs)::integer AS audits
        SQL);
    $check((int)$baseline->companies >= 4, 'Falta empresa F3.3.');
    $company = $owner->selectOne('SELECT id FROM public.companies WHERE id=? AND is_active IS TRUE', [$companyId]);
    $check($company !== null, 'Empresa 4 inactiva o ausente.');
    $membership = $owner->selectOne(<<<'SQL'
        SELECT user_id FROM public.company_user
        WHERE company_id=? AND role='ADMIN' AND is_active IS TRUE
        ORDER BY user_id LIMIT 1
        SQL, [$companyId]);
    $check($membership !== null, 'Empresa 4 carece de ADMIN activo.');
    $existing = $owner->selectOne(
        'SELECT count(*)::integer AS n FROM public.journal_entries WHERE company_id=?', [$companyId]
    );
    $check((int)$existing->n === 0, 'Empresa 4 ya tiene asientos; no repetir ensayo inicial.');
    $period = $owner->selectOne(
        'SELECT id FROM public.fiscal_periods WHERE company_id=? AND year=2026 AND month=10',
        [$companyId]
    );
    $check($period === null, 'Ya existe periodo octubre 2026: el ensayo requiere apertura nueva.');
    $db->beginTransaction(); $started = true;
    $db->selectOne("SELECT set_config('statement_timeout', '30000', true)");
    $db->selectOne("SELECT set_config('app.current_company_id', ?, true)", [(string)$companyId]);
    $rows = $db->select(<<<'SQL'
        SELECT id,code FROM public.accounting_accounts
        WHERE company_id=? AND is_active IS TRUE AND is_operational IS TRUE
          AND length(code)=6 AND requires_auxiliary IS FALSE
          AND requires_cost_center IS FALSE AND has_automatic_destination IS FALSE
          AND requires_currency IS FALSE
        ORDER BY code
        SQL, [$companyId]);
    // Choose two unrestricted tenant-local accounts; no cost center/third party requirements.
    $check(count($rows) >= 2, 'No hay dos cuentas operativas simples para este POST.');
    $account1 = (int)$rows[0]->id;
    $account2 = (int)$rows[1]->id;
    $currencies = $db->select(<<<'SQL'
        SELECT c.code,cc.is_base FROM public.company_currencies cc
        JOIN public.currencies c ON c.id=cc.currency_id
        WHERE cc.company_id=? AND cc.is_active IS TRUE
        SQL, [$companyId]);
    $codes = array_map(static fn($r):string => (string)$r->code, $currencies);
    $check(in_array('PEN',$codes,true) && in_array('USD',$codes,true)
        && count($currencies) === 2, 'F3.5 espera PEN/USD unicamente.');
    $db->selectOne("SELECT set_config('app.current_company_id', '', true)");
    $actor = \App\Models\User::query()->findOrFail((int)$membership->user_id);
    \Laravel\Sanctum\Sanctum::actingAs($actor, ['*'], 'sanctum');
    $http = static function (string $method, string $path, ?array $payload = null, ?string $key = null) use ($kernel, $companyId) {
        $headers = [
            'HTTP_ACCEPT'=>'application/json',
            'CONTENT_TYPE'=>'application/json',
            'HTTP_X_COMPANY_ID'=>(string)$companyId,
        ];
        if ($key !== null) $headers['HTTP_IDEMPOTENCY_KEY'] = $key;
        return $kernel->handle(\Illuminate\Http\Request::create(
            $path,$method,[],[],[],$headers,
            $payload === null ? null : json_encode($payload,JSON_THROW_ON_ERROR)
        ));
    };
    $phase = 'HTTP period opening';
    $opened = $http('POST','/api/v1/accounting/fiscal-periods',[
        'year'=>2026,'month'=>10,'reason'=>'F3.5 primera apertura contable reversible de empresa ficticia'
    ]);
    $openedBody = json_decode((string)$opened->getContent(),true,512,JSON_THROW_ON_ERROR);
    $check($opened->getStatusCode() === 201 && ($openedBody['data']['status'] ?? '') === 'OPEN',
        'Apertura HTTP esperada 201 OPEN, recibido '.$opened->getStatusCode());
    echo "[PASS] F3.5 apertura HTTP 201 de periodo de empresa 4.\n";
    $post = [
        'entry_date'=>'2026-10-23','entry_type'=>'STANDARD','currency'=>'PEN',
        'exchange_rates'=>[['currency'=>'USD','buy_rate'=>'3.720000','sell_rate'=>'3.750000','rate'=>'3.750000']],
        'description'=>'F3.5 asiento inicial de ensayo reversible',
        'lines'=>[
            ['accounting_account_id'=>$account1,'debit_local'=>'150.0000','credit_local'=>'0.0000',
             'debit_foreign'=>'40.0000','credit_foreign'=>'0.0000'],
            ['accounting_account_id'=>$account2,'debit_local'=>'0.0000','credit_local'=>'150.0000',
             'debit_foreign'=>'0.0000','credit_foreign'=>'40.0000'],
        ],
    ];
    $key='f35-empresa-cuatro-primer-post-20261023';
    $phase='HTTP first POST';
    $created=$http('POST','/api/v1/accounting/journal-entries',$post,$key);
    $body=json_decode((string)$created->getContent(),true,512,JSON_THROW_ON_ERROR);
    $check($created->getStatusCode()===201,
        'POST esperado 201, recibido '.$created->getStatusCode().': '.substr((string)($body['message']??''),0,160));
    $id=(int)($body['data']['id']??0);
    $check($id>0 && count($body['data']['lines']??[])>=2, 'Asiento o partidas faltantes.');
    echo "[PASS] F3.5 HTTP 201 primer asiento bimoneda empresa 4.\n";
    $phase='HTTP idempotency replay';
    $replay=$http('POST','/api/v1/accounting/journal-entries',$post,$key);
    $replayBody=json_decode((string)$replay->getContent(),true,512,JSON_THROW_ON_ERROR);
    $check($replay->getStatusCode()===200 && (int)($replayBody['data']['id']??0)===$id,
        'Reintento idempotente no obtuvo 200 y mismo id.');
    $mutated=$post; $mutated['description']='F3.5 contenido distinto';
    $conflict=$http('POST','/api/v1/accounting/journal-entries',$mutated,$key);
    $check($conflict->getStatusCode()===409,'Clave duplicada distinta no devolvio 409.');
    echo "[PASS] F3.5 idempotencia 200/409.\n";
    $db->selectOne("SELECT set_config('app.current_company_id', ?, true)", [(string)$companyId]);
    $checks=$db->selectOne(<<<'SQL'
        SELECT
          (SELECT count(*) FROM public.journal_entries WHERE company_id=?)::integer AS entries,
          (SELECT count(*) FROM public.journal_entry_lines WHERE company_id=? AND journal_entry_id=?)::integer AS lines,
          (SELECT count(*) FROM public.audit_logs WHERE company_id=? AND event_type='JOURNAL_ENTRY_CREATED')::integer AS audits,
          (SELECT count(*) FROM public.journal_post_idempotency WHERE company_id=?)::integer AS receipts
        SQL, [$companyId,$companyId,$id,$companyId,$companyId]);
    $check($checks && $checks->entries===1 && $checks->lines>=2
        && $checks->audits===1 && $checks->receipts===1,
        'Contabilizacion no es unica o auditoria/recibo faltan.');
    $db->statement('SET CONSTRAINTS ALL IMMEDIATE');
    echo "[PASS] F3.5 1 asiento, partidas, 1 recibo, 1 auditoria, constraints.\n";
    $phase = "commit";
    $db->commit(); $started=false; $committed=true; $journalId=$id;
    echo "[COMMIT] Asiento ficticio de empresa 4, ID=".$journalId.".\n";
    $success=true;
} catch (\Throwable $e) {
    fwrite(STDERR,'[FAIL] F3.5 fase='.$phase.' '.get_class($e).': '.substr($e->getMessage(),0,240)."\n");
} finally {
    if ($started) {
        try { $db->rollBack(); echo "[ROLLBACK] F3.5 transaccion fallida revertida.\n"; }
        catch (\Throwable $e) { $success=false; fwrite(STDERR,"[BLOQUEO] Rollback F3.5 fallido.\n"); }
    }
    $db->disconnect();
    if ($success) {
        try {
            $after=$owner->selectOne(<<<'SQL'
                SELECT
                  (SELECT count(*) FROM public.companies)::integer AS companies,
                  (SELECT count(*) FROM public.journal_entries)::integer AS entries,
                  (SELECT count(*) FROM public.journal_entry_lines)::integer AS lines,
                  (SELECT count(*) FROM public.fiscal_periods)::integer AS periods,
                  (SELECT count(*) FROM public.exchange_rates)::integer AS rates,
                  (SELECT count(*) FROM public.journal_post_idempotency)::integer AS receipts,
                  (SELECT count(*) FROM public.audit_logs)::integer AS audits
                SQL);
            $expected=['companies'=>0,'entries'=>1,'periods'=>1,'rates'=>1,'receipts'=>1,'audits'=>2];
            foreach($expected as $field=>$delta){
                if((int)$after->$field !== (int)$baseline->$field+$delta)
                    throw new \RuntimeException('Delta inesperado: '.$field);
            }
            if((int)$after->lines < (int)$baseline->lines+2)
                throw new \RuntimeException('No se persistieron las partidas');
            $confirmed=$owner->selectOne('SELECT company_id FROM public.journal_entries WHERE id=?',[$journalId]);
            if(!$confirmed || (int)$confirmed->company_id!==$companyId)
                throw new \RuntimeException('Asiento persistido ajeno o ausente');
            echo "[PASS] F3.5 POST-COMMIT: 1 periodo, 1 asiento, partidas, 1 FX, 1 recibo y 2 auditorias.\n";
        } catch (\Throwable $e) {
            $success=false; fwrite(STDERR,"[BLOQUEO] Verificacion post-commit fallida; no reintentar automaticamente.\n");
        }
    }
    $owner->disconnect();
    config($old);
}
if($committed && !$success) fwrite(STDERR,"[ALERTA] COMMIT aplicado, verificar antes de reintentar.\n");
exit($success?0:1);
