<?php

declare(strict_types=1);

// F3.3: read-only verification of the existing persistent onboarding.
// Never creates companies, users, accounts, periods or journal entries.
$mode = $argv[1] ?? '--plan';
if ($argc !== 2 || ! in_array($mode, ['--plan', '--verify=contabilidad-dev'], true)) {
    fwrite(STDERR, "[USO] --plan | --verify=contabilidad-dev\n");
    exit(2);
}
if ($mode === '--plan') {
    echo "[PLAN] F3.3 audita la empresa persistente ID=4 sin escribir datos.\n";
    echo "[PLAN] Comprueba onboarding, PCGE de seis dígitos, RLS y ausencia de movimientos.\n";
    exit(0);
}

$root = dirname(__DIR__);
if (! is_file($root.'/.env') || ! is_file($root.'/vendor/autoload.php')
    || is_file($root.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "[BLOQUEO] Requiere .env privado, vendor y configuracion no cacheada.\n");
    exit(1);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$expectedHost = 'ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$connection = (array) config('database.connections.pgsql', []);
$adminConfig = (array) config('database.connections.pgsql_migrations', []);
$safe = static fn (array $c, string $role): bool =>
    ($c['host'] ?? null) === $expectedHost
    && (string) ($c['port'] ?? '') === '5432'
    && ($c['database'] ?? null) === 'neondb'
    && ($c['username'] ?? null) === $role
    && trim((string) ($c['url'] ?? '')) === ''
    && in_array((string) ($c['sslmode'] ?? ''), ['require', 'verify-ca', 'verify-full'], true);

if (! app()->environment('local') || config('database.default') !== 'pgsql'
    || env('NEON_TEST_BRANCH_NAME') !== 'contabilidad-dev'
    || env('NEON_TEST_BRANCH_HOST') !== $expectedHost
    || ! $safe($connection, 'app_backend') || ! $safe($adminConfig, 'neondb_owner')
    || filter_var(env('NEON_USE_POOLING', false), FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, "[BLOQUEO] Este ensayo solo admite Neon contabilidad-dev con conexion directa y SSL.\n");
    exit(1);
}

$admin = \Illuminate\Support\Facades\DB::connection('pgsql_migrations');
$db = \Illuminate\Support\Facades\DB::connection('pgsql');
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new \RuntimeException($message);
    }
};
$companyId = 4; // Persisted during the previous F3 onboarding; NEVER recreate it.
$ok = false;
try {
    $identity = $admin->selectOne(
        'SELECT current_user AS actor, current_database() AS db, current_setting(\'server_version_num\')::integer AS pg'
    );
    $assert($identity && $identity->actor === 'neondb_owner'
        && $identity->db === 'neondb' && intdiv((int) $identity->pg, 10000) === 18,
        'Conexion ADMIN inesperada.');
    $actor = $db->selectOne('SELECT current_user AS actor');
    $assert($actor && $actor->actor === 'app_backend', 'La conexion APP no usa el rol restringido.');

    $baseline = $admin->selectOne(<<<'SQL'
        SELECT
            (SELECT count(*) FROM public.companies)::integer AS companies,
            (SELECT count(*) FROM public.company_user)::integer AS memberships,
            (SELECT count(*) FROM public.journal_entries)::integer AS journals,
            (SELECT count(*) FROM public.audit_logs)::integer AS audits
        SQL);
    $company = $admin->selectOne(
        'SELECT id FROM public.companies WHERE id = ? AND is_active IS TRUE',
        [$companyId]
    );
    $assert($company !== null, 'Empresa persistente ID=4 no existe o no esta activa.');
    $other = $admin->selectOne('SELECT id FROM public.companies WHERE id <> ? ORDER BY id LIMIT 1', [$companyId]);
    $assert($other !== null, 'Falta un segundo tenant para comprobar aislamiento.');

    $db->beginTransaction();
    try {
        $db->selectOne("SELECT set_config('transaction_read_only', 'on', true)");
        $db->selectOne("SELECT set_config('app.current_company_id', ?, true)", [(string) $companyId]);
        $detail = $db->selectOne(<<<'SQL'
            SELECT
                (SELECT count(*) FROM public.company_user WHERE company_id=? AND role='ADMIN' AND is_active IS TRUE)::integer AS admins,
                (SELECT count(*) FROM public.company_settings WHERE company_id=?)::integer AS settings,
                (SELECT count(*) FROM public.company_currencies WHERE company_id=?)::integer AS currencies,
                (SELECT count(*) FROM public.company_economic_activities WHERE company_id=? AND is_primary IS TRUE)::integer AS principal_activities,
                (SELECT count(*) FROM public.company_tax_codes WHERE company_id=?)::integer AS tax_codes,
                (SELECT count(*) FROM public.company_areas WHERE company_id=?)::integer AS areas,
                (SELECT count(*) FROM public.cash_flow_areas WHERE company_id=?)::integer AS cash_flows,
                (SELECT count(*) FROM public.accounting_accounts WHERE company_id=?)::integer AS accounts,
                (SELECT count(*) FROM public.accounting_accounts WHERE company_id=? AND length(code)=6 AND is_operational IS TRUE)::integer AS operational_six,
                (SELECT count(*) FROM public.audit_logs WHERE company_id=? AND event_type='COMPANY_ONBOARDED')::integer AS onboarding_audits,
                (SELECT count(*) FROM public.journal_entries WHERE company_id=?)::integer AS journals
            SQL, array_fill(0, 11, $companyId));
        $assert($detail && $detail->admins >= 1 && $detail->settings === 1
            && $detail->currencies >= 1 && $detail->principal_activities === 1
            && $detail->tax_codes >= 1 && $detail->areas === 6
            && $detail->cash_flows === 9 && $detail->accounts >= 1764
            && $detail->operational_six > 0 && $detail->onboarding_audits >= 1,
            'Configuracion de empresa persistente incompleta.');
        echo "[PASS] F3.3 empresa ID=4: ADMIN, settings, monedas, CIIU, tributos, areas, flujos, PCGE y auditoria.\n";
        echo "[METRICA] F3.3 PCGE: {$detail->accounts} cuentas; {$detail->operational_six} operativas de seis digitos.\n";

        // A foreign tenant is inaccessible via raw SQL under app_backend with RLS.
        $foreign = $db->selectOne('SELECT count(*)::integer AS n FROM public.accounting_accounts WHERE company_id=?', [(int) $other->id]);
        $assert($foreign && (int) $foreign->n === 0, 'RLS permitio leer cuentas de otra empresa.');
        echo "[PASS] F3.3 aislamiento RLS: cuentas de empresa ajena invisibles para app_backend.\n";
        echo "[METRICA] F3.3 asientos persistidos en empresa 4: {$detail->journals}.\n";
    } finally {
        // Rollback also clears SET LOCAL; no changes survive the read-only transaction.
        $db->rollBack();
    }

    $after = $admin->selectOne(<<<'SQL'
        SELECT
            (SELECT count(*) FROM public.companies)::integer AS companies,
            (SELECT count(*) FROM public.company_user)::integer AS memberships,
            (SELECT count(*) FROM public.journal_entries)::integer AS journals,
            (SELECT count(*) FROM public.audit_logs)::integer AS audits
        SQL);
    $assert($after && $baseline->companies === $after->companies
        && $baseline->memberships === $after->memberships
        && $baseline->journals === $after->journals
        && $baseline->audits === $after->audits,
        'Las cantidades del baseline cambiaron durante la verificacion.');
    echo "[PASS] F3.3 solo lectura: baseline de empresas, membresias, asientos y auditorias preservado.\n";
    $ok = true;
} catch (\Throwable $e) {
    fwrite(STDERR, '[FAIL] F3.3: '.get_class($e).': '.$e->getMessage()."\n");
} finally {
    $db->disconnect();
    $admin->disconnect();
}
exit($ok ? 0 : 1);
