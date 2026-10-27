<?php

declare(strict_types=1);

/**
 * F5.2 — one real, tenant-scoped analytic posting through Laravel HTTP.
 *
 * --plan: offline, no connection.
 * --probe=contabilidad-dev: real HTTP POST and financial-report checks,
 *      then ROLLBACK all changes, including analytic allocations and audit.
 * --apply=contabilidad-dev: same assertions, then COMMIT once in DEV.
 *
 * DEMO A only. Does not touch B/C or rewrite old journals.
 */
$mode = $argv[1] ?? '--plan';
if ($argc !== 2 || ! in_array($mode, ['--plan', '--probe=contabilidad-dev', '--apply=contabilidad-dev'], true)) {
    fwrite(STDERR, "[USO] --plan | --probe=contabilidad-dev | --apply=contabilidad-dev\n");
    exit(2);
}
if ($mode === '--plan') {
    echo "[PLAN] DEMO A ACTIVE: gasto electrico 636100 en CC-ADM y CC-VTA.\n";
    echo "[PLAN] Laravel POST: 3 partidas manuales + 4 analiticas (94/95,79).\n";
    echo "[PLAN] Verificar 2 allocations, 2 monedas, Libro Mayor, ESF/ERN y auditoria.\n";
    echo "[PLAN] probe revierte todo; apply persiste una sola vez. Sin conexion SQL.\n";
    exit(0);
}
$root = dirname(__DIR__);
if (! is_file($root.'/.env') || ! is_file($root.'/vendor/autoload.php')
    || is_file($root.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "[BLOQUEO] Requiere backend/.env, vendor y configuracion Laravel sin cache.\n");
    exit(1);
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

$host = 'ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$runtime = (array) config('database.connections.pgsql', []);
$ownerConfig = (array) config('database.connections.pgsql_migrations', []);
$safe = static fn (array $cfg, string $username): bool =>
    ($cfg['host'] ?? '') === $host
    && (string) ($cfg['port'] ?? '') === '5432'
    && ($cfg['database'] ?? '') === 'neondb'
    && ($cfg['username'] ?? '') === $username
    && trim((string) ($cfg['url'] ?? '')) === ''
    && in_array((string) ($cfg['sslmode'] ?? ''), ['require', 'verify-ca', 'verify-full'], true);
if (! app()->environment('local') || config('database.default') !== 'pgsql'
    || env('NEON_TEST_BRANCH_NAME', '') !== 'contabilidad-dev'
    || env('NEON_TEST_BRANCH_HOST', '') !== $host
    || ! $safe($runtime, 'app_backend') || ! $safe($ownerConfig, 'neondb_owner')
    || filter_var(env('NEON_USE_POOLING', false), FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, "[BLOQUEO] Exclusivo Neon contabilidad-dev PostgreSQL 18, conexiones directas.\n");
    exit(1);
}

$oldRuntime = [
    'cache.default' => config('cache.default'),
    'session.driver' => config('session.driver'),
    'queue.default' => config('queue.default'),
];
config(['cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync']);

$owner = DB::connection('pgsql_migrations');
$db = DB::connection('pgsql');
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$companyId = 3000000000101;
$actorId = 3000000000201;
$description = '[DEMO F5.2] Energia electrica por CC-ADM y CC-VTA';
$expectedHash = '7ec6129c655bc4ebd9ad6f378d8b12352c3e097da33517e4f1f6e484d70e01f8';
$begun = false;
$committed = false;
$passed = false;
$entryId = 0;
$phase = 'baseline';
$check = static function (bool $condition, string $reason): void {
    if (! $condition) throw new RuntimeException($reason);
};
try {
    $guard = $owner->selectOne(<<<'SQL'
        SELECT current_user AS actor, current_database() AS db,
               current_setting('server_version_num')::integer AS pg,
               (SELECT count(*) FROM public.migrations)::integer AS migrations,
               (SELECT count(*) FROM public.companies)::integer AS companies,
               (SELECT count(*) FROM public.journal_entries WHERE company_id=3000000000101)::integer AS demo_entries,
               (SELECT count(*) FROM public.journal_analytic_allocations WHERE company_id=3000000000101)::integer AS demo_allocations
        SQL);
    $check($guard && $guard->actor === 'neondb_owner' && $guard->db === 'neondb'
        && intdiv((int)$guard->pg, 10000) === 18
        && (int)$guard->migrations === 63 && (int)$guard->companies === 4
        && (int)$guard->demo_entries === 7 && (int)$guard->demo_allocations === 0,
        'Baseline DEMO A incompatible, no duplicar ni alterar asientos.');
    $version = $owner->table('accounting_destination_rule_versions')
        ->where('company_id', $companyId)->where('id', 1)
        ->where('status', 'ACTIVE')->where('source_count', 227)->where('route_count', 454)->first();
    $check($version && hash_equals($expectedHash, trim((string)$version->matrix_sha256)),
        'Matriz de DEMO A no activa o SHA diferente.');
    $others = $owner->table('accounting_destination_rule_versions')
        ->whereIn('company_id', [3000000000102, 3000000000103])
        ->where('status', 'DRAFT')->count();
    $check($others === 2, 'DEMOs B y C deben permanecer DRAFT.');
    $check($owner->table('company_user')->where('company_id', $companyId)
        ->where('user_id', $actorId)->where('role', 'ADMIN')->where('is_active', true)->exists(),
        'Actor DEMO ADMIN sin autorizacion.');
    $check($owner->table('journal_entries')->where('company_id', $companyId)
        ->where('description', $description)->doesntExist(),
        'Asiento F5.2 ya registrado; no repetir POST.');

    $routes = $owner->select(<<<'SQL'
        SELECT cc.code AS center, src.id AS source_id, src.code AS source_code,
               cc.id AS center_id, d.id AS debit_id, d.code AS debit_code,
               h.id AS credit_id, h.code AS credit_code
        FROM public.accounting_destination_routes r
        JOIN public.accounting_accounts src ON src.company_id=r.company_id AND src.id=r.source_account_id
        JOIN public.cost_centers cc ON cc.company_id=r.company_id AND cc.id=r.cost_center_id
        JOIN public.accounting_accounts d ON d.company_id=r.company_id AND d.id=r.destination_debit_account_id
        JOIN public.accounting_accounts h ON h.company_id=r.company_id AND h.id=r.destination_credit_account_id
        WHERE r.company_id=? AND r.rule_version_id=1 AND src.code='636100'
        ORDER BY cc.code
        SQL, [$companyId]);
    $check(count($routes) === 2 && $routes[0]->center === 'CC-ADM'
        && $routes[1]->center === 'CC-VTA'
        && $routes[0]->debit_code === '941100' && $routes[1]->debit_code === '951100'
        && $routes[0]->credit_code === '791000' && $routes[1]->credit_code === '791000',
        'Rutas 636100 hacia 94/95 y 79 inconsistentes.');
    $liabilityId = (int) $owner->table('accounting_accounts')
        ->where('company_id', $companyId)->where('code', '421200')->value('id');
    $supplierId = (int) $owner->table('entities')
        ->where('company_id', $companyId)->where('id', 28)
        ->where('is_active', true)->where('is_supplier', true)->value('id');
    $period = $owner->table('fiscal_periods')->where('company_id', $companyId)
        ->where('year', 2026)->where('month', 10)->where('status', 'OPEN')->first();
    $fx = $owner->table('exchange_rates')->where('company_id', $companyId)
        ->where('source_currency', 'USD')->where('target_currency', 'PEN')
        ->whereDate('rate_date', '2026-10-26')->first();
    $check($liabilityId > 0 && $supplierId === 28 && $period !== null
        && $fx !== null && (string)$fx->sell_rate === '3.758000',
        'Falta cuenta proveedora, entidad, periodo o TC DEMO esperado.');
    $check($db->selectOne('SELECT current_user AS role_name')?->role_name === 'app_backend',
        'POST debe usar el rol app_backend, no owner.');

    $db->beginTransaction();
    $begun = true;
    $db->selectOne("SELECT set_config('statement_timeout','45000',true)");

    $actor = User::query()->findOrFail($actorId);
    Sanctum::actingAs($actor, ['*'], 'sanctum');
    $request = static function (string $method, string $uri, ?array $body = null, ?string $key = null)
        use ($kernel, $companyId): array {
        $headers = [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_COMPANY_ID' => (string)$companyId,
        ];
        if ($key !== null) $headers['HTTP_IDEMPOTENCY_KEY'] = $key;
        $response = $kernel->handle(\Illuminate\Http\Request::create(
            $uri, $method, [], [], [], $headers,
            $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR)
        ));
        return [$response->getStatusCode(),
            json_decode((string)$response->getContent(), true, 512, JSON_THROW_ON_ERROR)];
    };

    $phase = 'snapshot initial';
    [$beforeStatus, $beforeBody] = $request('GET',
        '/api/v1/accounting/reports/financial-snapshot?as_of=2026-10-26');
    $check($beforeStatus === 200, 'No se pudo consultar ESF antes del POST.');

    $amounts = [
        ['local' => '150.3200', 'foreign' => '40.0000'],
        ['local' => '225.4800', 'foreign' => '60.0000'],
    ];
    $lines = [];
    foreach ($routes as $index => $route) {
        $lines[] = [
            'accounting_account_id' => (int)$route->source_id,
            'cost_center_id' => (int)$route->center_id,
            'description' => 'Electricidad ficticia '.$route->center,
            'debit_local' => $amounts[$index]['local'],
            'credit_local' => '0.0000',
            'debit_foreign' => $amounts[$index]['foreign'],
            'credit_foreign' => '0.0000',
        ];
    }
    $lines[] = [
        'accounting_account_id' => $liabilityId,
        'entity_id' => $supplierId,
        'description' => 'Proveedor DEMO por gasto electrico',
        'debit_local' => '0.0000', 'credit_local' => '375.8000',
        'debit_foreign' => '0.0000', 'credit_foreign' => '100.0000',
    ];
    $payload = [
        'entry_date' => '2026-10-26', 'entry_type' => 'STANDARD',
        'subdiary_code' => 'GENERAL', 'currency' => 'USD',
        // No exchange_rate/exchange_rates in this payload: the HTTP controller
        // must use the already stored 2026-10-26 USD/PEN sell rate, and must
        // NOT rewrite the original 3.742000 BUY exchange-rate record.
        'exchange_rate_type' => 'SELL',
        'description' => $description,
        'lines' => $lines,
    ];
    $phase = 'POST journal';
    [$status, $body] = $request('POST', '/api/v1/accounting/journal-entries',
        $payload, 'f52-demo-a-analytic-20261026-v1');
    $check($status === 201,
        'POST devolvio '.$status.': '.substr((string)($body['message'] ?? ''), 0, 180));
    $entryId = (int)($body['data']['id'] ?? 0);
    $check($entryId > 0 && count($body['data']['lines'] ?? []) === 7,
        'Asiento POST no produjo 3 lineas fuente + 4 lineas destino.');

    $db->selectOne("SELECT set_config('app.current_company_id', ?, true)", [(string)$companyId]);
    $phase = 'allocations/amounts';
    $allocations = $db->select(<<<'SQL'
        SELECT a.id, a.source_line_id,a.debit_line_id,a.credit_line_id,cc.code AS center,
          sa.code AS source_code, da.code AS debit_code,ca.code AS credit_code,
          dl.debit_local,dl.debit_foreign,cl.credit_local,cl.credit_foreign
        FROM public.journal_analytic_allocations a
        JOIN public.cost_centers cc ON cc.id=a.cost_center_id AND cc.company_id=a.company_id
        JOIN public.journal_entry_lines sl ON sl.id=a.source_line_id AND sl.company_id=a.company_id
        JOIN public.journal_entry_lines dl ON dl.id=a.debit_line_id AND dl.company_id=a.company_id
        JOIN public.journal_entry_lines cl ON cl.id=a.credit_line_id AND cl.company_id=a.company_id
        JOIN public.accounting_accounts sa ON sa.id=sl.accounting_account_id AND sa.company_id=a.company_id
        JOIN public.accounting_accounts da ON da.id=dl.accounting_account_id AND da.company_id=a.company_id
        JOIN public.accounting_accounts ca ON ca.id=cl.accounting_account_id AND ca.company_id=a.company_id
        WHERE a.company_id=? AND a.journal_entry_id=? AND a.rule_version_id=1
        ORDER BY cc.code
        SQL, [$companyId, $entryId]);
    $check(count($allocations) === 2, 'No hay exactamente dos asignaciones 6xx/94-95/79.');
    foreach ($allocations as $index => $a) {
        $check($a->source_code === '636100'
            && $a->debit_code === ($index === 0 ? '941100' : '951100')
            && $a->credit_code === '791000'
            && bccomp((string)$a->debit_local, $amounts[$index]['local'], 4) === 0
            && bccomp((string)$a->credit_local, $amounts[$index]['local'], 4) === 0
            && bccomp((string)$a->debit_foreign, $amounts[$index]['foreign'], 4) === 0
            && bccomp((string)$a->credit_foreign, $amounts[$index]['foreign'], 4) === 0,
            'Destino o importe de '.$a->center.' incorrecto.');
    }
    $entryTotals = $db->selectOne(<<<'SQL'
        SELECT count(*)::int AS n, sum(debit_local) AS debit, sum(credit_local) AS credit,
               sum(debit_foreign) AS fx_debit, sum(credit_foreign) AS fx_credit
        FROM public.journal_entry_lines WHERE company_id=? AND journal_entry_id=?
        SQL, [$companyId, $entryId]);
    $check((int)$entryTotals->n === 7
        && bccomp((string)$entryTotals->debit, (string)$entryTotals->credit, 4) === 0
        && bccomp((string)$entryTotals->fx_debit, (string)$entryTotals->fx_credit, 4) === 0,
        'Asiento bimoneda desbalanceado.');
    $db->statement('SET CONSTRAINTS ALL IMMEDIATE');
    $db->selectOne("SELECT set_config('app.current_company_id','',true)");

    $phase = 'snapshot/ledger final';
    [$afterStatus, $afterBody] = $request('GET',
        '/api/v1/accounting/reports/financial-snapshot?as_of=2026-10-26');
    $check($afterStatus === 200 && ($afterBody['data']['analytic_routing_status'] ?? null) === 'ACTIVE',
        'Reporte financiero no refleja estado ACTIVE.');
    $before = $beforeBody['data'];
    $after = $afterBody['data'];
    $check(bccomp(bcsub($after['income_statement']['net'], $before['income_statement']['net'], 4),
                    '-375.8000', 4) === 0
        && bccomp(bcsub($after['totals']['result'], $before['totals']['result'], 4),
                    '-375.8000', 4) === 0
        && bccomp((string)$after['totals']['difference'], '0', 4) === 0
        && bccomp((string)$after['totals']['trial_difference'], '0', 4) === 0,
        'ERN/ESF duplico 94/95/79 o desbalanceo los estados financieros.');
    [$ledgerStatus, $ledgerBody] = $request('GET',
        '/api/v1/accounting/reports/general-ledger?account_id='
        .(int)$routes[0]->source_id.'&from=2026-10-01&to=2026-10-26');
    $check($ledgerStatus === 200
        && (int)($ledgerBody['data']['total'] ?? -1) === 2
        && bccomp((string)end($ledgerBody['data']['rows'])['balance_local'], '375.8000', 4) === 0,
        'Libro Mayor 636100 no reconcilia dos movimientos.');
    echo "[PASS] POST 201: asiento #{$entryId}, 3 originales + 4 analiticas, 2 allocations.\n";
    echo "[PASS] CC-ADM->941100; CC-VTA->951100; ambos contra 791000.\n";
    echo "[PASS] Bimoneda USD100/PEN375.8000 cuadrada; mayor 636100 exacto.\n";
    echo "[PASS] ERN: resultado varia -375.8000 sin duplicar destinos; ESF y trial cuadran.\n";

    if ($mode === '--probe=contabilidad-dev') {
        $passed = true;
    } else {
        $phase = 'commit';
        $db->commit();
        $begun = false;
        $committed = true;
        $passed = true;
        echo "[COMMIT] F5.2 DEMO A asiento #{$entryId} persistido.\n";
    }
} catch (\Throwable $exception) {
    fwrite(STDERR, '[FAIL] '.$phase.' '.get_class($exception).': '
        .substr($exception->getMessage(), 0, 300)."\n");
} finally {
    if ($begun) {
        try {
            $db->rollBack();
            echo "[ROLLBACK] F5.2: asiento, asignaciones y auditoria revertidos.\n";
        } catch (\Throwable $exception) {
            $passed = false;
            fwrite(STDERR, "[ALERTA] Rollback fallido; revisar datos antes de repetir.\n");
        }
    }
    $db->disconnect();
    if ($committed) {
        $persisted = $owner->table('journal_entries')->where('company_id', $companyId)
            ->where('id', $entryId)->exists();
        if (! $persisted) {
            $passed = false;
            fwrite(STDERR, "[ALERTA] No se confirmo asiento post-COMMIT.\n");
        } else {
            echo "[PASS] Post-COMMIT: asiento DEMO A confirmado en Neon.\n";
        }
    }
    $owner->disconnect();
    config($oldRuntime);
}
exit($passed ? 0 : 1);
