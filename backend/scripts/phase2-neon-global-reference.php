<?php

declare(strict_types=1);

/**
 * F2 — Bootstrap versionado de DATOS GLOBALES en Neon contabilidad-dev.
 *
 * --plan                Ninguna conexión SQL, sin cargar Laravel.
 * --verify              Solo SELECT sobre PostgreSQL y archivos Git.
 * --apply=contabilidad-dev
 *                       DDL de ledger explícito + carga global en UNA
 *                       transacción; NO modifica empresas ni usuarios.
 *
 * Esta aplicación es la FASE GLOBAL, no el instalador de empresas.
 * Las tablas históricas Nexus/PLE que NO tienen mapeo siguen pendientes.
 * No usar php artisan db:seed general ni migrate:fresh.
 */

$backend = dirname(__DIR__);
$repoRoot = dirname($backend);
$manifestFile = $backend.'/database/reference_data/manifest.json';
$ledgerMigration = '2026_10_21_040000_create_reference_data_versions';
$migrationPath = 'database/portable_migrations/'.$ledgerMigration.'.php';
$expectedHost = 'ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';
$mode = $argv[1] ?? '--plan';
if ($argc > 2 || ! in_array($mode, ['--plan','--verify','--apply=contabilidad-dev'], true)) {
    fwrite(STDERR, "[FAIL] Uso: php scripts/phase2-neon-global-reference.php --plan|--verify|--apply=contabilidad-dev\n");
    exit(2);
}

try {
    if (! is_file($manifestFile) || ! is_file($backend.'/'.$migrationPath)) {
        throw new RuntimeException('Manifiesto o migracion del registro de versiones ausentes.');
    }
    $raw = file_get_contents($manifestFile);
    $manifest = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
    if (($manifest['schema_version'] ?? null) !== 1
        || ($manifest['dataset'] ?? '') !== 'nexus-global-reference-2026-10-21-v1'
        || ! is_array($manifest['sources'] ?? null)
        || ! is_array($manifest['counts'] ?? null)
        || count($manifest['sources']) !== 6 || count($manifest['counts']) !== 13) {
        throw new RuntimeException('Formato/version del manifiesto global inesperados.');
    }

    $expectedTables = [
        'currencies'=>3,
        'accounting_standards'=>43,
        'financial_statement_rubrics'=>48,
        'sunat_table_01_payment_methods'=>20,
        'sunat_table_02_identity_document_types'=>5,
        'sunat_table_03_financial_institutions'=>33,
        'sunat_table_05_inventory_types'=>6,
        'sunat_table_06_measurement_units'=>16,
        'sunat_table_08_electronic_books'=>20,
        'sunat_table_10_electronic_document_types'=>24,
        'sunat_table_11_customs_dependencies'=>22,
        'sunat_table_12_operation_types'=>17,
        'accounting_account_templates'=>3401,
    ];
    if ($manifest['counts'] !== $expectedTables
        || ($manifest['plan_versions']['PCGE_2019'] ?? null) !== 1764
        || ($manifest['plan_versions']['PCGE_2026'] ?? null) !== 1637) {
        throw new RuntimeException('Recuento/planes alterados sin nueva version autorizada.');
    }

    $sourceHashes = [];
    foreach ($manifest['sources'] as $source) {
        $relative = $source['path'] ?? '';
        $expectedBlob = $source['git_blob_sha1'] ?? '';
        $allow = [
            'backend/database/seeders/CurrenciesSeeder.php',
            'backend/database/seeders/SunatCatalogSeeder.php',
            'backend/database/seeders/AccountingStandardsAndRubricsSeeder.php',
            'backend/database/seeders/MasterChartOfAccountsSeeder.php',
            'plan-contable-2019.md','plan-contable-2026.md',
        ];
        if (! in_array($relative, $allow, true)
            || ! is_string($expectedBlob)
            || ! preg_match('/^[0-9a-f]{40}$/D', $expectedBlob)) {
            throw new RuntimeException('Archivo/hash no autorizado en el manifiesto.');
        }
        $file = $repoRoot.'/'.$relative;
        if (! is_file($file)) {
            throw new RuntimeException('Fuente versionada no encontrada: '.$relative);
        }
        // Reproducible incluso si Git tiene autocrlf en una maquina Windows.
        $bytes = str_replace("\r\n", "\n", (string) file_get_contents($file));
        $blob = sha1('blob '.strlen($bytes)."\0".$bytes);
        if (! hash_equals($expectedBlob, $blob)) {
            throw new RuntimeException('Cambio sin versionar en archivo maestro: '.$relative);
        }
        $sourceHashes[$relative] = hash('sha256', $bytes);
    }
    if (count($sourceHashes) !== 6) {
        throw new RuntimeException('Fuentes duplicadas en manifiesto.');
    }
    // Conservar ambas fuentes historicas SIN tratarlas como codigos SUNAT
    // vigentes: el 100 % de las filas sigue pendiente de validacion oficial.
    $historical = $manifest['historical_sources'] ?? null;
    $expectedArchives = [
        'backend/database/reference_data/historical/ple_234_tablas_2026-10-21.json'
            => [247, 'HISTORICAL_NOT_VERIFIED'],
        'backend/database/reference_data/historical/sunat_book_catalog_codes_draft_2026-10-21.json'
            => [23, 'DRAFT_NOT_VALIDATED'],
    ];
    if (! is_array($historical) || count($historical) !== 2) {
        throw new RuntimeException('Manifiesto historico PLE incompleto.');
    }
    $visitedArchives = [];
    foreach ($historical as $archive) {
        $relative = $archive['path'] ?? '';
        if (! isset($expectedArchives[$relative]) || isset($visitedArchives[$relative])) {
            throw new RuntimeException('Archivo historico duplicado o no autorizado.');
        }
        $visitedArchives[$relative] = true;
        [$count, $status] = $expectedArchives[$relative];
        $blob = $archive['git_blob_sha1'] ?? '';
        if (! is_string($blob) || ! preg_match('/^[0-9a-f]{40}$/D', $blob)
            || ($archive['rows'] ?? null) !== $count
            || ($archive['status'] ?? '') !== $status
            || ($archive['loaded_operationally'] ?? null) !== false) {
            throw new RuntimeException('Metadatos PLE historicos alterados.');
        }
        $file = $repoRoot.'/'.$relative;
        if (! is_file($file)) {
            throw new RuntimeException('Archivo historico no encontrado.');
        }
        $bytes = str_replace("\r\n","\n",(string) file_get_contents($file));
        if (! hash_equals($blob, sha1('blob '.strlen($bytes)."\0".$bytes))) {
            throw new RuntimeException('Hash historico PLE alterado sin version.');
        }
        $data = json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
        if (($data['classification'] ?? '') !== $status
            || ($data['row_count'] ?? null) !== $count
            || ! is_array($data['rows'] ?? null)
            || count($data['rows']) !== $count) {
            throw new RuntimeException('Contenido del archivo PLE historico no coincide.');
        }
        $keys = [];
        foreach ($data['rows'] as $row) {
            if ($status === 'HISTORICAL_NOT_VERIFIED') {
                $key = ($row['table_number'] ?? '').':'.($row['source_row_number'] ?? '');
                if (($row['validity_review_status'] ?? '') === 'VERIFIED'
                    || ($row['code_review_status'] ?? '') === 'APPROVED') {
                    throw new RuntimeException('Fuente PLE historica contiene aprobacion inesperada.');
                }
            } else {
                $key = ($row['catalog_version_id'] ?? '').':'.($row['code'] ?? '');
                if (($row['review_status'] ?? '') !== 'DRAFT') {
                    throw new RuntimeException('Codigo historico fuera de estado DRAFT.');
                }
            }
            if (isset($keys[$key])) {
                throw new RuntimeException('Codigo historico duplicado.');
            }
            $keys[$key] = true;
        }
    }
    $manifestSha = hash('sha256', (string) $raw);
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] Integridad de fuentes Git/manifiesto global no aprobada: '.$error->getMessage().PHP_EOL);
    exit(1);
}

if ($mode === '--plan') {
    echo '[PLAN] Fuentes Git verificadas por SHA Git blob; SHA256 maestro calculado.'.PHP_EOL;
    echo '[PLAN] Historial PLE aislado: 247 filas sin validar y 23 codigos DRAFT; solo archivados.'.PHP_EOL;
    echo '[PLAN] DATASET '.$manifest['dataset'].' con '.count($expectedTables).' tablas globales.'.PHP_EOL;
    echo '[PLAN] 3 monedas; 163 entradas SUNAT; 43 NIC/NIIF; 48 rubros; 3401 plantillas PCGE.'.PHP_EOL;
    echo '[PLAN] Crea ledger portable de versiones como migracion 18 (si falta).'.PHP_EOL;
    echo '[PLAN] INSERT transaccional exclusivo de datos globales, sin TRUNCATE.'.PHP_EOL;
    echo '[PLAN] SHA256 del contenido guardado por tabla; reejecucion se valida sin escribir.'.PHP_EOL;
    echo '[PLAN] Conserva tres empresas, usuarios, membresias y todas sus credenciales.'.PHP_EOL;
    echo '[AVISO] Catalogos PLE/Nexus custom, CLP y configuracion por empresa: fase posterior.'.PHP_EOL;
    echo '[DRY-RUN] No se ha conectado a Neon ni ejecutado SQL.'.PHP_EOL;
    exit(0);
}

if (! is_file($backend.'/.env') || is_file($backend.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, '[BLOQUEO] Entorno privado ausente o configuracion cacheada.'.PHP_EOL);
    exit(1);
}
$seen = [];
$duplicates = [];
$lines = file($backend.'/.env', FILE_IGNORE_NEW_LINES);
foreach ($lines === false ? [] : $lines as $line) {
    if (! preg_match('/^[ \t]*(?:export[ \t]+)?([A-Z][A-Z0-9_]*)[ \t]*=/', $line, $match)) {
        continue;
    }
    $name = $match[1];
    if (! str_starts_with($name,'DB_') && ! str_starts_with($name,'NEON_')
        && ! in_array($name,['APP_KEY','APP_ENV','DATABASE_URL'], true)) {
        continue;
    }
    if (isset($seen[$name])) {
        $duplicates[] = $name;
    }
    $seen[$name] = true;
}
if ($duplicates !== []) {
    fwrite(STDERR,'[BLOQUEO] Variables repetidas: '.implode(', ',array_unique($duplicates)).PHP_EOL);
    exit(1);
}

require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$admin = (array) config('database.connections.pgsql_migrations', []);
$traffic = (array) config('database.connections.pgsql', []);
$appPassword = (string) ($traffic['password'] ?? '');
$rolePassword = (string) env('DB_APP_BACKEND_ROLE_PASSWORD', '');
if (! $app->environment('local')
    || config('database.default') !== 'pgsql'
    || env('NEON_TEST_BRANCH_NAME', '') !== 'contabilidad-dev'
    || env('NEON_TEST_BRANCH_HOST', '') !== $expectedHost
    || (string) ($admin['host'] ?? '') !== $expectedHost
    || (string) ($traffic['host'] ?? '') !== $expectedHost
    || (string) ($admin['port'] ?? '') !== '5432'
    || (string) ($traffic['port'] ?? '') !== '5432'
    || (string) ($admin['database'] ?? '') !== 'neondb'
    || (string) ($traffic['database'] ?? '') !== 'neondb'
    || (string) ($admin['username'] ?? '') !== 'neondb_owner'
    || (string) ($traffic['username'] ?? '') !== 'app_backend'
    || trim((string) ($admin['url'] ?? '')) !== ''
    || trim((string) ($traffic['url'] ?? '')) !== ''
    || ! in_array((string) ($admin['sslmode'] ?? ''), ['require','verify-ca','verify-full'], true)
    || ! in_array((string) ($traffic['sslmode'] ?? ''), ['require','verify-ca','verify-full'], true)
    || filter_var(env('NEON_USE_POOLING', false), FILTER_VALIDATE_BOOLEAN)
    || strlen($rolePassword) < 20 || $appPassword === ''
    || ! hash_equals($rolePassword, $appPassword)) {
    fwrite(STDERR, '[BLOQUEO] Solo Neon contabilidad-dev directo, admin restringido por destino.'.PHP_EOL);
    exit(1);
}
unset($appPassword, $rolePassword);

// Modelos/seeders antiguos emplean DB sin conexion explicita.
// Nunca permitir que sus escrituras se dirijan al rol de trafico app_backend.
$manager = app('db');
$defaultConnection = $manager->getDefaultConnection();
$manager->setDefaultConnection('pgsql_migrations');
$db = $manager->connection('pgsql_migrations');

$canonicalMap = static function (array $value): array {
    ksort($value, SORT_STRING);
    return $value;
};
$getCounts = static function () use ($db, $expectedTables): array {
    $counts = [];
    foreach (array_keys($expectedTables) as $table) {
        $counts[$table] = (int) $db->table($table)->count();
    }
    return $counts;
};
$getHashes = static function () use ($db, $expectedTables): array {
    $hashes = [];
    foreach (array_keys($expectedTables) as $table) {
        $digest = hash_init('sha256');
        $query = $db->table($table);
        if ($table === 'accounting_account_templates') {
            $query->orderBy('plan_version')->orderBy('code');
        } else {
            $query->orderBy('code');
        }
        foreach ($query->cursor() as $row) {
            $fields = (array) $row;
            unset($fields['id'],$fields['created_at'],$fields['updated_at']);
            ksort($fields, SORT_STRING);
            hash_update($digest, json_encode($fields,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        }
        $hashes[$table] = hash_final($digest);
    }
    return $hashes;
};
$ok = false;
try {
    $state = $db->selectOne(<<<'SQL'
        SELECT current_user AS user_name, current_database() AS db_name,
               current_setting('server_version_num')::integer AS pg_version,
               (SELECT count(*) FROM public.migrations)::integer AS migrations,
               (SELECT count(*) FROM public.companies)::integer AS companies,
               (SELECT count(*) FROM public.users)::integer AS users,
               (SELECT count(*) FROM public.company_user)::integer AS memberships,
               CASE WHEN to_regclass('public.reference_data_versions') IS NOT NULL THEN 1 ELSE 0 END AS ledger_exists
        SQL);
    if ($state === null || $state->user_name !== 'neondb_owner'
        || $state->db_name !== 'neondb'
        || intdiv((int) $state->pg_version, 10000) !== 18
        || ! in_array((int) $state->migrations, [17,18], true)
        || (int) $state->companies !== 3 || (int) $state->users !== 3
        || (int) $state->memberships !== 8) {
        throw new RuntimeException('Identidad PostgreSQL o empresas persistentes inesperadas.');
    }

    $ledgerExists = (int) ($state->ledger_exists ?? 0) === 1;
    $counts = $getCounts();
    $records = $ledgerExists ? $db->table('reference_data_versions')
        ->where('dataset_key', $manifest['dataset'])->first() : null;
    $currentHashes = $getHashes();

    if ($mode === '--verify') {
        echo '[PASS] Neon PG18 contabilidad-dev: 3 empresas, 3 usuarios, 8 membresias intactas.'.PHP_EOL;
        echo '[INFO] Migraciones registradas: '.(int) $state->migrations.PHP_EOL;
        foreach ($expectedTables as $name => $wanted) {
            echo sprintf('[INFO] %s: %d / %d', $name, $counts[$name], $wanted).PHP_EOL;
        }
        if (! $ledgerExists || $records === null) {
            echo '[PENDIENTE] No existe registro de instalacion global completo.'.PHP_EOL;
            $ok = true;
        } else {
            $savedCounts = json_decode((string) $records->verified_counts,true,512,JSON_THROW_ON_ERROR);
            $savedHashes = json_decode((string) $records->content_sha256,true,512,JSON_THROW_ON_ERROR);
            $savedSources = json_decode((string) $records->source_sha256,true,512,JSON_THROW_ON_ERROR);
            if ($counts !== $expectedTables
                || $canonicalMap($savedCounts) !== $canonicalMap($expectedTables)
                || $canonicalMap($savedHashes) !== $canonicalMap($currentHashes)
                || $canonicalMap($savedSources) !== $canonicalMap($sourceHashes)
                || (string) $records->manifest_sha256 !== $manifestSha) {
                throw new RuntimeException('Drift global detectado: contenido/manifiesto diferentes.');
            }
            echo '[PASS] GLOBAL_REFERENCE_OK: versiones, cantidades y SHA256 por tabla coinciden.'.PHP_EOL;
            $ok = true;
        }
    } else {
        if ($ledgerExists && $records !== null) {
            $savedHashes = json_decode((string)$records->content_sha256,true,512,JSON_THROW_ON_ERROR);
            $savedCounts = json_decode((string)$records->verified_counts,true,512,JSON_THROW_ON_ERROR);
            $savedSources = json_decode((string)$records->source_sha256,true,512,JSON_THROW_ON_ERROR);
            if ($counts !== $expectedTables
                || $canonicalMap($savedCounts) !== $canonicalMap($expectedTables)
                || $canonicalMap($savedSources) !== $canonicalMap($sourceHashes)
                || $canonicalMap($savedHashes) !== $canonicalMap($currentHashes)
                || (string)$records->manifest_sha256 !== $manifestSha) {
                throw new RuntimeException('Instalacion previa diferente: no sobrescribir.');
            }
            echo '[PASS] Datos globales ya instalados: segunda aplicacion sin escrituras.'.PHP_EOL;
            $ok = true;
        } else {
            if (array_sum($counts) !== 0
                || (! $ledgerExists && (int) $state->migrations !== 17)
                || ($ledgerExists && (int) $state->migrations !== 18)
                || $records !== null) {
                throw new RuntimeException('Hay carga global parcial o migracion inesperada; no aplicar a ciegas.');
            }

            if (! $ledgerExists) {
                echo '[PASS] Preflight: 17 migraciones, globales vacios y 3 empresas intactas.'.PHP_EOL;
                $result = \Illuminate\Support\Facades\Artisan::call('migrate', [
                    '--database'=>'pgsql_migrations',
                    '--path'=>$migrationPath,
                    '--force'=>true,
                    '--no-interaction'=>true,
                ]);
                if ($result !== 0 || ! \Illuminate\Support\Facades\Schema::hasTable('reference_data_versions')) {
                    throw new RuntimeException('Migracion de ledger no aprobada; revisar estado antes de reintentar.');
                }
                echo '[PASS] Ledger portable instalado como migracion 18.'.PHP_EOL;
            } else {
                // Recuperacion segura tras DDL exitoso y DML fallido:
                // el ledger existe sin filas y todos los catalogos continuan vacios.
                echo '[PASS] Ledger 18 ya instalado sin datos parciales; reanudando fase transaccional.'.PHP_EOL;
            }

            // Todas las inserciones globales y el registro de version se
            // confirman o revierten juntas. Nunca TRUNCATE ni db:seed general.
            // Los seeders de referencia usan un modo batched INSERT-ONLY que
            // conserva la fuente y los defaults SQL, evitando centenares de
            // llamadas updateOrCreate a Neon en conexiones WAN.
            $step = static function (string $name, callable $action): void {
                $started = microtime(true);
                echo '[CARGANDO] '.$name.' (transaccion pendiente, sin COMMIT)'.PHP_EOL;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
                $action();
                echo '[ETAPA OK] '.$name.'; '.round(microtime(true)-$started, 2).' s. Sin COMMIT.'.PHP_EOL;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            $db->transaction(static function () use (
                $db, $getCounts, $getHashes, $expectedTables, $manifest,
                $manifestSha, $sourceHashes, $step
            ): void {
                // Limites por consulta, no por transaccion. En caso de timeout
                // se deshacen TODAS las filas del dataset F2.
                $db->statement("SET LOCAL lock_timeout = '10000ms'");
                $db->statement("SET LOCAL statement_timeout = '60000ms'");

                $step('Monedas', static fn () => app(\Database\Seeders\CurrenciesSeeder::class)->runForGlobalInstall());
                $step('9 catalogos SUNAT', static fn () => app(\Database\Seeders\SunatCatalogSeeder::class)->runForGlobalInstall());
                $step('Normas NIC/NIIF y rubros', static fn () => app(\Database\Seeders\AccountingStandardsAndRubricsSeeder::class)->runForGlobalInstall());
                $step('Matrices PCGE 2019 y 2026', static fn () => app(\Database\Seeders\MasterChartOfAccountsSeeder::class)->seedTemplates());

                $actual = $getCounts();
                if ($actual !== $expectedTables) {
                    throw new RuntimeException('Carga global incompleta: la transaccion sera revertida.');
                }
                foreach ($manifest['plan_versions'] as $version => $wanted) {
                    $found = (int) $db->table('accounting_account_templates')
                        ->where('plan_version', $version)->count();
                    if ($found !== $wanted) {
                        throw new RuntimeException('Cantidad PCGE inesperada en '.$version);
                    }
                }
                echo '[COMPROBANDO] 13 tablas, hashes SHA256 y versiones PCGE (sin COMMIT).'.PHP_EOL;
                flush();
                $hashes = $getHashes();
                $db->table('reference_data_versions')->insert([
                    'dataset_key'=>$manifest['dataset'],
                    'manifest_sha256'=>$manifestSha,
                    'source_sha256'=>json_encode($sourceHashes,JSON_THROW_ON_ERROR),
                    'expected_counts'=>json_encode($expectedTables,JSON_THROW_ON_ERROR),
                    'verified_counts'=>json_encode($actual,JSON_THROW_ON_ERROR),
                    'content_sha256'=>json_encode($hashes,JSON_THROW_ON_ERROR),
                    'installed_at'=>now(),
                ]);
            }, 1);
            $after = $getCounts();
            if ($after !== $expectedTables || $db->table('reference_data_versions')
                ->where('dataset_key', $manifest['dataset'])->count() !== 1) {
                throw new RuntimeException('Verificacion poscommit de referencias no aprobada.');
            }
            $identityAfter = $db->selectOne(<<<'SQL'
                SELECT (SELECT count(*) FROM public.migrations)::integer AS migrations,
                       (SELECT count(*) FROM public.companies)::integer AS companies,
                       (SELECT count(*) FROM public.users)::integer AS users,
                       (SELECT count(*) FROM public.company_user)::integer AS memberships
                SQL);
            if ((int) $identityAfter->migrations !== 18
                || (int) $identityAfter->companies !== 3
                || (int) $identityAfter->users !== 3
                || (int) $identityAfter->memberships !== 8) {
                throw new RuntimeException('Estado multiempresa alterado inesperadamente.');
            }
            echo '[COMMIT] Carga global F2 confirmada en una sola transaccion.'.PHP_EOL;
            echo '[PASS] GLOBAL_REFERENCE_OK: 13 catálogos, 3401 plantillas PCGE; datos y hashes confirmados.'.PHP_EOL;
            echo '[PASS] 3 empresas, 3 usuarios y 8 membresias preservados.'.PHP_EOL;
            $ok = true;
        }
    }
} catch (Throwable $error) {
    // No exponer SQL, contrasenas, tokens ni detalles de PDO.
    fwrite(STDERR, '[FAIL] F2: '.($error instanceof RuntimeException
        ? $error->getMessage()
        : 'error del proceso de carga (inspeccionar localmente sin reintentar)').PHP_EOL);
} finally {
    $manager->setDefaultConnection($defaultConnection);
    $db->disconnect();
}
exit($ok ? 0 : 1);
