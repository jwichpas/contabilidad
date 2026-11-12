<?php

declare(strict_types=1);

/**
 * Install the existing F2 global catalog sources on the SINGLE guarded local
 * PostgreSQL 18 Docker database. This script never accepts a DB URL/host or
 * credentials as CLI arguments. PowerShell provides the private local env.
 *
 * Must only be invoked by preparar-pcge-pg18-local.ps1 -Accion CargarReferencias
 * after the container/volume/port check and an independent pg_dump backup.
 * Fail closed on every unexpected baseline, source hash or tenant count.
 */
if (PHP_SAPI !== 'cli' || $argc !== 1) {
    exit(73);
}

$backend = dirname(__DIR__);
$root = dirname($backend);
require $backend.'/vendor/autoload.php';

try {
    $manifestPath = $backend.'/database/reference_data/manifest.json';
    $raw = file_get_contents($manifestPath);
    if (! is_string($raw)) {
        throw new RuntimeException('Manifiesto de referencia ausente.');
    }
    $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['dataset'] ?? null) !== 'nexus-global-reference-2026-10-21-v1'
        || ($manifest['schema_version'] ?? null) !== 1
        || count($manifest['sources'] ?? []) !== 6
        || ($manifest['counts']['accounting_account_templates'] ?? null) !== 3401
        || ($manifest['plan_versions'] ?? null) !== [
            'PCGE_2019' => 1764, 'PCGE_2026' => 1637,
        ]) {
        throw new RuntimeException('Manifest F2 no coincide.');
    }

    $allowedSources = [
        'backend/database/seeders/CurrenciesSeeder.php',
        'backend/database/seeders/SunatCatalogSeeder.php',
        'backend/database/seeders/AccountingStandardsAndRubricsSeeder.php',
        'backend/database/seeders/MasterChartOfAccountsSeeder.php',
        'plan-contable-2019.md',
        'plan-contable-2026.md',
    ];
    $hashes = [];
    foreach ($manifest['sources'] as $source) {
        $path = $source['path'] ?? '';
        $sha = $source['git_blob_sha1'] ?? '';
        if (! in_array($path, $allowedSources, true) || isset($hashes[$path])
            || ! is_string($sha) || ! preg_match('/^[0-9a-f]{40}$/D', $sha)) {
            throw new RuntimeException('Fuente global no autorizada.');
        }
        $bytes = file_get_contents($root.'/'.$path);
        if (! is_string($bytes)) {
            throw new RuntimeException('Archivo maestro no encontrado.');
        }
        $bytes = str_replace("\r\n", "\n", $bytes);
        $blob = sha1('blob '.strlen($bytes)."\0".$bytes);
        if (! hash_equals($sha, $blob)) {
            throw new RuntimeException('Fuente global distinta al manifest F2.');
        }
        $hashes[$path] = hash('sha256', $bytes);
    }
    if (count($hashes) !== count($allowedSources)) {
        throw new RuntimeException('Fuentes F2 incompletas.');
    }

    $app = require $backend.'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $connectionOK = static function (array $cfg): bool {
        return ($cfg['driver'] ?? '') === 'pgsql'
            && ($cfg['host'] ?? '') === '127.0.0.1'
            && (string) ($cfg['port'] ?? '') === '55433'
            && ($cfg['database'] ?? '') === 'contabilidad_local'
            && ($cfg['username'] ?? '') === 'postgres'
            && ($cfg['sslmode'] ?? '') === 'disable'
            && trim((string) ($cfg['url'] ?? '')) === ''
            && is_string($cfg['password'] ?? null) && $cfg['password'] !== '';
    };
    if (! app()->environment('local')
        || config('database.default') !== 'pgsql'
        || ! $connectionOK((array) config('database.connections.pgsql'))
        || ! $connectionOK((array) config('database.connections.pgsql_migrations'))
        || is_file($backend.'/bootstrap/cache/config.php')) {
        throw new RuntimeException('Laravel no apunta exclusivamente al Docker local.');
    }

    $db = \Illuminate\Support\Facades\DB::connection('pgsql_migrations');
    $identity = $db->selectOne(<<<'SQL'
        SELECT current_database() AS db, current_user AS actor,
          current_setting('server_version_num')::integer AS pg,
          (SELECT count(*) FROM public.migrations)::integer AS migrations,
          (SELECT count(*) FROM public.companies)::integer AS companies,
          (SELECT count(*) FROM public.users)::integer AS users,
          (SELECT count(*) FROM public.accounting_accounts)::integer AS accounts,
          (SELECT count(*) FROM public.journal_entries)::integer AS journals,
          (SELECT count(*) FROM public.exchange_rates)::integer AS rates,
          (SELECT count(*) FROM pg_catalog.pg_class c
            JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname='public'
              AND c.relname IN ('company_user','journal_entries','journal_entry_lines',
                'company_subdiaries','journal_post_idempotency')
              AND c.relrowsecurity AND c.relforcerowsecurity)::integer AS rls
        SQL);
    if ($identity?->db !== 'contabilidad_local'
        || $identity?->actor !== 'postgres'
        || intdiv((int) ($identity?->pg ?? 0), 10000) !== 18
        || ! in_array((int) ($identity?->migrations ?? 0), [28, 29], true)
        || (int) $identity->companies !== 1
        || (int) $identity->users !== 1
        || (int) $identity->accounts !== 203
        || (int) $identity->journals !== 0
        || (int) $identity->rates !== 1
        || (int) $identity->rls !== 5) {
        throw new RuntimeException('Estado local previo inesperado; no importar.');
    }

    $wanted = $manifest['counts'];
    if (count($wanted) !== 13) {
        throw new RuntimeException('Inventario global inesperado.');
    }
    $counts = static function () use ($db, $wanted): array {
        $all = [];
        foreach ($wanted as $table => $number) {
            if (! preg_match('/^[a-z][a-z0-9_]+$/D', $table)) {
                throw new RuntimeException('Tabla global invalida.');
            }
            $all[$table] = (int) $db->table($table)->count();
        }
        return $all;
    };
    $initial = $counts();
    $currencyCodes = $db->table('currencies')->orderBy('code')->pluck('code')->all();
    if ($currencyCodes !== ['EUR', 'PEN', 'USD']) {
        throw new RuntimeException('Monedas locales inesperadas.');
    }

    $ledgerMigration = '2026_10_21_040000_create_reference_data_versions';
    $ledgerExists = \Illuminate\Support\Facades\Schema::connection('pgsql_migrations')
        ->hasTable('reference_data_versions');
    $alreadyInstalled = $initial === $wanted;
    if ($alreadyInstalled) {
        if (! $ledgerExists || (int) $db->table('reference_data_versions')
            ->where('dataset_key', $manifest['dataset'])->count() !== 1) {
            throw new RuntimeException('Catalogos existentes sin ledger F2 completo.');
        }
        echo "[PASS] Catalogos PCGE/SUNAT globales ya completos; sin escrituras.\n";
        exit(0);
    }
    foreach ($initial as $name => $number) {
        if ($name !== 'currencies' && $number !== 0) {
            throw new RuntimeException('Catalogos globales parciales; no sobrescribir.');
        }
    }
    if ($initial['currencies'] !== 3) {
        throw new RuntimeException('El instalador local debe tener exactamente 3 monedas.');
    }
    if ($ledgerExists) {
        if ((int) $identity->migrations !== 29
            || $db->table('reference_data_versions')->count() !== 0) {
            throw new RuntimeException('Ledger existente no concuerda con el baseline.');
        }
    } else {
        if ((int) $identity->migrations !== 28) {
            throw new RuntimeException('Historial de migraciones inesperado.');
        }
        $status = \Illuminate\Support\Facades\Artisan::call('migrate', [
            '--database' => 'pgsql_migrations',
            '--path' => 'database/portable_migrations/'.$ledgerMigration.'.php',
            '--force' => true, '--no-interaction' => true,
        ]);
        if ($status !== 0 || ! \Illuminate\Support\Facades\Schema::connection('pgsql_migrations')
            ->hasTable('reference_data_versions')) {
            throw new RuntimeException('No se pudo instalar el registro F2.');
        }
    }

    // The seeders are designed to write through the DEFAULT DB connection.
    // For this isolated CLI process it must be the verified LOCAL owner only.
    $manager = app('db');
    $oldDefault = $manager->getDefaultConnection();
    $manager->setDefaultConnection('pgsql_migrations');
    try {
        $db->transaction(static function () use ($db, $counts, $wanted, $manifest, $raw, $hashes): void {
            $db->statement("SET LOCAL lock_timeout = '10000ms'");
            $db->statement("SET LOCAL statement_timeout = '120000ms'");
            // Existing three currencies were created by the initial local
            // installer. Never modify them or their foreign keys.
            app(\Database\Seeders\SunatCatalogSeeder::class)->runForGlobalInstall();
            app(\Database\Seeders\AccountingStandardsAndRubricsSeeder::class)->runForGlobalInstall();
            app(\Database\Seeders\MasterChartOfAccountsSeeder::class)->seedTemplates();

            if ($counts() !== $wanted) {
                throw new RuntimeException('Los catalogos F2 no alcanzaron las cantidades esperadas.');
            }
            foreach ($manifest['plan_versions'] as $version => $count) {
                if ((int) $db->table('accounting_account_templates')
                    ->where('plan_version', $version)->count() !== $count) {
                    throw new RuntimeException('Version del PCGE incompleta.');
                }
            }
            $tableHashes = [];
            foreach (array_keys($wanted) as $table) {
                $digest = hash_init('sha256');
                $records = $db->table($table);
                if ($table === 'accounting_account_templates') {
                    $records->orderBy('plan_version')->orderBy('code');
                } else {
                    $records->orderBy('code');
                }
                foreach ($records->cursor() as $record) {
                    $fields = (array) $record;
                    unset($fields['id'], $fields['created_at'], $fields['updated_at']);
                    ksort($fields, SORT_STRING);
                    hash_update($digest, json_encode($fields,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
                }
                $tableHashes[$table] = hash_final($digest);
            }
            $db->table('reference_data_versions')->insert([
                'dataset_key' => $manifest['dataset'],
                'manifest_sha256' => hash('sha256', $raw),
                'source_sha256' => json_encode($hashes, JSON_THROW_ON_ERROR),
                'expected_counts' => json_encode($wanted, JSON_THROW_ON_ERROR),
                'verified_counts' => json_encode($counts(), JSON_THROW_ON_ERROR),
                'content_sha256' => json_encode($tableHashes, JSON_THROW_ON_ERROR),
                'installed_at' => now(),
            ]);
        }, 1);
    } finally {
        $manager->setDefaultConnection($oldDefault);
        $db->disconnect();
    }
    echo "[PASS] 3401 plantillas PCGE, SUNAT y NIIF instalados exclusivamente en Docker.\n";
    echo "[PASS] Empresa, usuario, cuentas ficticias y tasa existente conservados.\n";
} catch (\Throwable $e) {
    // Neither raw SQL nor driver exception text may contain passwords here.
    fwrite(STDERR, "[BLOQUEO] Carga global PG18 local no aprobada: "
        .($e instanceof RuntimeException ? $e->getMessage() : 'error interno')."\n");
    exit(1);
}
