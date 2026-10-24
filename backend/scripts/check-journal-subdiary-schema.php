<?php

declare(strict_types=1);

/**
 * Read-only preflight for the journal subdiary migration.
 *
 * Usage: php scripts/check-journal-subdiary-schema.php --check
 *
 * Queries the migrations registry and the journal_entries schema using the
 * admin DEV connection. Never changes, inserts or deletes database data.
 * Never prints credentials or connection details.
 */
if ($argc !== 2 || ($argv[1] ?? null) !== '--check') {
    fwrite(STDERR, "[USO] php scripts/check-journal-subdiary-schema.php --check\n");
    exit(2);
}

$root = dirname(__DIR__);
if (! is_file($root.'/vendor/autoload.php') || ! is_file($root.'/bootstrap/app.php')) {
    fwrite(STDERR, "[FAIL] Faltan dependencias o bootstrap de Laravel.\n");
    exit(1);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$admin = (array) config('database.connections.pgsql_migrations', []);
$expectedHost = (string) env('NEON_TEST_BRANCH_HOST', '');
$expectedBranch = (string) env('NEON_TEST_BRANCH_NAME', '');
if (
    ! $app->environment('local')
    || $expectedBranch !== 'contabilidad-dev'
    || $expectedHost !== 'ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech'
    || ($admin['host'] ?? null) !== $expectedHost
    || (string) ($admin['database'] ?? '') !== 'neondb'
    || (string) ($admin['username'] ?? '') !== 'neondb_owner'
    || (string) ($admin['port'] ?? '') !== '5432'
    || trim((string) ($admin['url'] ?? '')) !== ''
) {
    fwrite(STDERR, "[BLOQUEO] Configuracion distinta de Neon DEV esperada; no se consulta SQL.\n");
    exit(1);
}

try {
    $connection = \Illuminate\Support\Facades\DB::connection('pgsql_migrations');
    $schema = $connection->getSchemaBuilder();
    if (! $schema->hasTable('journal_entries') || ! $schema->hasTable('migrations')) {
        fwrite(STDERR, "[BLOQUEO] Falta alguna tabla esperada; no continuar con migraciones.\n");
        exit(1);
    }

    // Both operations are SELECTs. There is no DDL, write transaction or migration.
    $hasColumn = $schema->hasColumn('journal_entries', 'subdiary_code');
    $isRan = $connection->table('migrations')
        ->where('migration', '2026_10_24_130000_add_journal_entry_subdiary')
        ->exists();

    echo '[INFO] Migracion de subdiarios: '.($isRan ? 'Ran' : 'Pending').PHP_EOL;
    echo '[INFO] Columna journal_entries.subdiary_code: '.($hasColumn ? 'true' : 'false').PHP_EOL;

    if (! $isRan && ! $hasColumn) {
        echo "[PASS] Consistente para reintentar SOLO la migracion indicada, previa confirmacion de respaldo DEV.\n";
        exit(0);
    }
    if ($isRan && $hasColumn) {
        echo "[PASS] La migracion y la columna ya existen. NO reejecutar.\n";
        exit(0);
    }

    fwrite(STDERR, "[BLOQUEO] Historial y esquema no coinciden. NO reintentar migracion.\n");
    exit(1);
} catch (\Throwable $exception) {
    fwrite(STDERR, "[FAIL] No se pudo completar lectura SQL. No se han realizado escrituras.\n");
    exit(1);
}
