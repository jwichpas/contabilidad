<?php

declare(strict_types=1);

/**
 * F3.1 — controlled, auditable correction of persisted DEMO company 4:
 * CIIU 4923 (freight transport) was incorrectly labelled COMERCIAL.
 *
 * --plan                no DB connection
 * --probe=contabilidad-dev   real HTTP PUT inside outer transaction + ROLLBACK
 * --apply=contabilidad-dev   same guards + HTTP PUT + audit verification + COMMIT
 * --verify=contabilidad-dev  read-only verification after COMMIT
 *
 * Never edits fiscal regimes, invoices, PCGE, journal 52 or other tenants.
 */
$mode = $argv[1] ?? '--plan';
$validModes = [
    '--plan', '--probe=contabilidad-dev',
    '--apply=contabilidad-dev', '--verify=contabilidad-dev',
];
if ($argc !== 2 || ! in_array($mode, $validModes, true)) {
    fwrite(STDERR, "[USO] --plan | --probe=contabilidad-dev | --apply=contabilidad-dev | --verify=contabilidad-dev\n");
    exit(2);
}
if ($mode === '--plan') {
    echo "[PLAN] F3.1: corregir exclusivamente empresa ID=4, CIIU 4923 COMERCIAL -> TRANSPORTE_LOGISTICA.\n";
    echo "[PLAN] Se utilizará PUT /api/v1/company/settings con ADMIN y RLS; auditoría obligatoria de antes/después.\n";
    echo "[PLAN] --probe revierte todo; --apply hace COMMIT; --verify solo consulta.\n";
    echo "[PLAN] No modificar el asiento 52, plan PCGE, tributos, régimen ni otras empresas.\n";
    exit(0);
}
$root = dirname(__DIR__);
if (! is_file($root.'/.env') || ! is_file($root.'/vendor/autoload.php')
    || is_file($root.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "[BLOQUEO] Requiere .env privado, vendor y configuración Laravel no cacheada.\n");
    exit(1);
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$host = 'ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$runtime = (array) config('database.connections.pgsql', []);
$adminCfg = (array) config('database.connections.pgsql_migrations', []);
$safe = static fn (array $c, string $role): bool =>
    ($c['host'] ?? null) === $host && (string)($c['port'] ?? '') === '5432'
    && ($c['database'] ?? null) === 'neondb' && ($c['username'] ?? null) === $role
    && trim((string)($c['url'] ?? '')) === ''
    && in_array((string)($c['sslmode'] ?? ''), ['require','verify-ca','verify-full'], true);
if (! app()->environment('local') || config('database.default') !== 'pgsql'
    || env('NEON_TEST_BRANCH_NAME') !== 'contabilidad-dev'
    || env('NEON_TEST_BRANCH_HOST') !== $host
    || ! $safe($runtime, 'app_backend') || ! $safe($adminCfg, 'neondb_owner')
    || filter_var(env('NEON_USE_POOLING', false), FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, "[BLOQUEO] Solo Neon PG18 contabilidad-dev, roles correctos, conexión directa con SSL.\n");
    exit(1);
}
$previousDrivers = [
    'cache.default' => config('cache.default'),
    'session.driver' => config('session.driver'),
    'queue.default' => config('queue.default'),
];
config(['cache.default'=>'array','session.driver'=>'array','queue.default'=>'sync']);

$owner = \Illuminate\Support\Facades\DB::connection('pgsql_migrations');
$db = \Illuminate\Support\Facades\DB::connection('pgsql');
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$companyId = 4;
$inTransaction = false;
$committed = false;
$success = false;
$stage = 'preflight';
$assert = static function (bool $condition, string $why): void {
    if (! $condition) throw new \RuntimeException($why);
};
$hasSectorTransition = static function (object $audit): bool {
    $old = json_decode((string)$audit->old_values, true);
    $new = json_decode((string)$audit->new_values, true);
    if (! is_array($old) || ! is_array($new)) return false;
    $prior = $old['economic_activities'] ?? [];
    $after = $new['economic_activities'] ?? [];
    return count($prior) === 1 && count($after) === 1
        && (string)($prior[0]['ciiu_code'] ?? '') === '4923'
        && (string)($prior[0]['sector_code'] ?? '') === 'COMERCIAL'
        && (string)($after[0]['ciiu_code'] ?? '') === '4923'
        && (string)($after[0]['sector_code'] ?? '') === 'TRANSPORTE_LOGISTICA';
};

try {
    $who = $owner->selectOne(
        "SELECT current_user AS actor, current_database() AS db, current_setting('server_version_num')::integer AS pg"
    );
    $assert($who && $who->actor === 'neondb_owner' && $who->db === 'neondb'
        && intdiv((int)$who->pg, 10000) === 18, 'ADMIN no conectado a Neon PG18 neondb.');
    $assert($db->selectOne('SELECT current_user AS actor')?->actor === 'app_backend',
        'La API no usa el rol app_backend.');
    $company = $owner->selectOne(
        'SELECT id,tax_id FROM public.companies WHERE id=? AND is_active IS TRUE',
        [$companyId]
    );
    $assert($company && (string)$company->tax_id === '20610141588',
        'La identidad de empresa 4 no coincide con el alta E2E verificada.');
    $journal = $owner->selectOne('SELECT company_id FROM public.journal_entries WHERE id=52');
    $assert($journal && (int)$journal->company_id === $companyId,
        'Asiento ficticio 52 no existe en empresa 4.');
    $activities = $owner->select(
        'SELECT ciiu_code,sector_code,description,is_primary FROM public.company_economic_activities WHERE company_id=?',
        [$companyId]
    );
    $assert(count($activities) === 1 && (string)$activities[0]->ciiu_code === '4923'
        && in_array($activities[0]->is_primary, [true, 1, '1', 't'], true),
        'El registro principal CIIU 4923 no es único o no está activo.');
    $sector = (string)$activities[0]->sector_code;
    $auditBefore = $owner->selectOne(
        "SELECT COALESCE(MAX(id),0)::bigint AS latest FROM public.audit_logs WHERE company_id=? AND event_type='COMPANY_SETTINGS_UPDATED'",
        [$companyId]
    );
    $baseline = $owner->selectOne(<<<'SQL'
        SELECT
          (SELECT count(*) FROM public.companies)::integer AS companies,
          (SELECT count(*) FROM public.accounting_accounts)::integer AS accounts,
          (SELECT count(*) FROM public.journal_entries)::integer AS journals,
          (SELECT count(*) FROM public.journal_entry_lines)::integer AS lines,
          (SELECT count(*) FROM public.company_user)::integer AS memberships,
          (SELECT count(*) FROM public.fiscal_periods)::integer AS periods,
          (SELECT count(*) FROM public.audit_logs)::integer AS audits
        SQL);
    $assert($baseline !== null, 'No fue posible obtener el baseline de seguridad.');
    $auditId = 0;

    if ($mode === '--verify=contabilidad-dev') {
        $assert($sector === 'TRANSPORTE_LOGISTICA', 'El sector todavía no fue corregido.');
        $history = $owner->select(
            "SELECT id,old_values,new_values FROM public.audit_logs WHERE company_id=? AND event_type='COMPANY_SETTINGS_UPDATED' ORDER BY id DESC LIMIT 30",
            [$companyId]
        );
        $matches = array_values(array_filter($history, $hasSectorTransition));
        $assert(count($matches) >= 1, 'No se encontró auditoría de COMERCIAL a TRANSPORTE_LOGISTICA.');
        echo "[PASS] F3.1 empresa 4: CIIU 4923 TRANSPORTE_LOGISTICA y auditoría antes/después existentes.\n";
        echo "[PASS] F3.1 asiento ID 52 preservado; verificación de solo lectura.\n";
        $success = true;
    } else {
        $assert($sector === 'COMERCIAL',
            'El sector ya cambió o no coincide con el esperado; NO reintentar actualización.');
        $membership = $owner->selectOne(<<<'SQL'
            SELECT user_id FROM public.company_user
            WHERE company_id=? AND role='ADMIN' AND is_active IS TRUE
            ORDER BY user_id LIMIT 1
            SQL, [$companyId]);
        $assert($membership !== null, 'No existe ADMIN activo de empresa 4.');
        $db->beginTransaction();
        $inTransaction = true;
        $db->selectOne("SELECT set_config('statement_timeout','30000',true)");
        $actor = \App\Models\User::query()->findOrFail((int)$membership->user_id);
        \Laravel\Sanctum\Sanctum::actingAs($actor, ['*'], 'sanctum');

        $payload = ['economic_activities' => [[
            'ciiu_code'=>'4923',
            'sector_code'=>'TRANSPORTE_LOGISTICA',
            'description'=>(string)$activities[0]->description,
            'is_primary'=>true,
        ]]];
        $stage = 'PUT company settings';
        $response = $kernel->handle(\Illuminate\Http\Request::create(
            '/api/v1/company/settings', 'PUT', [], [], [],
            ['HTTP_ACCEPT'=>'application/json',
             'CONTENT_TYPE'=>'application/json',
             'HTTP_X_COMPANY_ID'=>(string)$companyId],
            json_encode($payload, JSON_THROW_ON_ERROR)
        ));
        $body = json_decode((string)$response->getContent(), true);
        $assert($response->getStatusCode() === 200 && is_array($body),
            'PUT devolvió HTTP '.$response->getStatusCode().' (se revertirá).');
        $stage = 'verify tenant data and audit before finalizing';
        $db->selectOne("SELECT set_config('app.current_company_id', ?, true)", [(string)$companyId]);
        $updated = $db->select(<<<'SQL'
            SELECT ciiu_code,sector_code,description,is_primary
            FROM public.company_economic_activities WHERE company_id=?
            SQL, [$companyId]);
        $assert(count($updated) === 1
            && (string)$updated[0]->ciiu_code === '4923'
            && (string)$updated[0]->sector_code === 'TRANSPORTE_LOGISTICA'
            && (string)$updated[0]->description === (string)$activities[0]->description
            && in_array($updated[0]->is_primary, [true, 1, '1', 't'], true),
            'La API alteró actividades distintas al cambio de sector esperado.');
        $audits = $db->select(<<<'SQL'
            SELECT id,user_id,old_values,new_values FROM public.audit_logs
            WHERE company_id=? AND event_type='COMPANY_SETTINGS_UPDATED'
              AND id > ? ORDER BY id
            SQL, [$companyId,(int)$auditBefore->latest]);
        $assert(count($audits) === 1 && (int)$audits[0]->user_id === (int)$actor->id
            && $hasSectorTransition($audits[0]),
            'Auditoría del cambio no coincide; se revierte la operación.');
        $auditId = (int)$audits[0]->id;
        $db->statement('SET CONSTRAINTS ALL IMMEDIATE');
        echo "[PASS] F3.1 HTTP 200: CIIU 4923 corregido y 1 auditoría con valores anteriores/nuevos.\n";
        if ($mode === '--probe=contabilidad-dev') {
            $db->rollBack();
            $inTransaction = false;
            echo "[ROLLBACK] F3.1 simulación HTTP sin cambios permanentes.\n";
        } else {
            $stage = 'COMMIT';
            $db->commit();
            $inTransaction = false;
            $committed = true;
            echo "[COMMIT] F3.1 sector corregido en empresa 4; auditoría ID=".$auditId.".\n";
        }
        $stage = 'post-transaction consistency';
        $after = $owner->selectOne(<<<'SQL'
            SELECT
              (SELECT count(*) FROM public.companies)::integer AS companies,
              (SELECT count(*) FROM public.accounting_accounts)::integer AS accounts,
              (SELECT count(*) FROM public.journal_entries)::integer AS journals,
              (SELECT count(*) FROM public.journal_entry_lines)::integer AS lines,
              (SELECT count(*) FROM public.company_user)::integer AS memberships,
              (SELECT count(*) FROM public.fiscal_periods)::integer AS periods,
              (SELECT count(*) FROM public.audit_logs)::integer AS audits
            SQL);
        foreach (['companies','accounts','journals','lines','memberships','periods'] as $field) {
            $assert((int)$after->$field === (int)$baseline->$field,
                'Baseline modificado: '.$field);
        }
        $expectedAuditChange = $committed ? 1 : 0;
        $assert((int)$after->audits === (int)$baseline->audits+$expectedAuditChange,
            'Número de auditorías inesperado tras la operación.');
        $finalActivity = $owner->selectOne(
            'SELECT sector_code FROM public.company_economic_activities WHERE company_id=? AND ciiu_code=?',
            [$companyId,'4923']
        );
        $assert((string)($finalActivity->sector_code ?? '') ===
            ($committed ? 'TRANSPORTE_LOGISTICA' : 'COMERCIAL'),
            'Sector final no coincide con el modo solicitado.');
        echo "[PASS] F3.1 baseline preservado: empresa, PCGE, membresías, períodos y asientos (incluido ID 52).\n";
        $success = true;
    }
} catch (\Throwable $e) {
    $code = $e instanceof \Illuminate\Database\QueryException
        ? (string)($e->errorInfo[0] ?? 'UNKNOWN') : 'N/A';
    fwrite(STDERR, '[FAIL] F3.1 fase='.$stage.' '.get_class($e).' SQLSTATE='
        .preg_replace('/[^A-Z0-9]/','',$code)."\n");
} finally {
    if ($inTransaction) {
        try { $db->rollBack(); echo "[ROLLBACK] F3.1 falló antes del COMMIT.\n"; }
        catch (\Throwable) { $success=false; fwrite(STDERR,"[BLOQUEO] Falló rollback.\n"); }
    }
    $db->disconnect();
    $owner->disconnect();
    config($previousDrivers);
}
if ($committed && ! $success) {
    fwrite(STDERR, "[ALERTA] COMMIT aplicado. No reintentar sin ejecutar --verify=contabilidad-dev.\n");
}
exit($success ? 0 : 1);
