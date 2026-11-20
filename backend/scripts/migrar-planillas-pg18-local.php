<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\ConsoleOutput;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
$profilePath = $root.'/.env.pg18.local';
if (! is_file($root.'/vendor/autoload.php') || ! is_readable($profilePath)) {
    fwrite(STDERR, "Se requieren las dependencias de Laravel y el perfil privado backend/.env.pg18.local.\n");
    exit(1);
}
require $root.'/vendor/autoload.php';

$target = [
    'DB_MIGRATIONS_HOST' => '127.0.0.1',
    'DB_MIGRATIONS_PORT' => '55433',
    'DB_MIGRATIONS_DATABASE' => 'contabilidad_local',
    'DB_MIGRATIONS_USERNAME' => 'postgres',
    'DB_MIGRATIONS_SSLMODE' => 'disable',
];
$phase = 'leer el perfil privado de PostgreSQL local';

try {
    $contents = file_get_contents($profilePath);
    if ($contents === false) {
        throw new RuntimeException('Perfil ilegible.');
    }
    $profile = Dotenv::parse($contents);
    if (array_intersect_key($profile, $target) != $target || ($profile['DB_MIGRATIONS_PASSWORD'] ?? '') === '') {
        fwrite(STDERR, "El perfil debe identificar PostgreSQL local en 127.0.0.1:55433/contabilidad_local y contener la credencial de postgres.\n");
        exit(1);
    }

    $phase = 'verificar PostgreSQL 18 local en 127.0.0.1:55433/contabilidad_local';
    $app = require $root.'/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.pg18.local');
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    // Replace the effective CLI connection, including URL and cached settings.
    // Artisan and the schema operations use this connection for this process.
    $connection = 'pgsql_migrations';
    config([
        'database.default' => $connection,
        'database.connections' => [$connection => [
            'driver' => 'pgsql',
            'host' => $target['DB_MIGRATIONS_HOST'],
            'port' => $target['DB_MIGRATIONS_PORT'],
            'database' => $target['DB_MIGRATIONS_DATABASE'],
            'username' => $target['DB_MIGRATIONS_USERNAME'],
            'sslmode' => $target['DB_MIGRATIONS_SSLMODE'],
            'password' => $profile['DB_MIGRATIONS_PASSWORD'],
            'charset' => 'utf8',
            'search_path' => 'public',
            'prefix' => '',
        ]],
    ]);
    DB::purge($connection);
    $db = DB::connection($connection);
    $identity = $db->selectOne(<<<'SQL'
        SELECT current_database() AS database_name, current_user AS username,
            current_setting('server_version_num')::integer AS version,
            to_regclass('public.migrations') IS NOT NULL
                AND to_regclass('public.companies') IS NOT NULL
                AND to_regclass('public.users') IS NOT NULL
                AND to_regprocedure('public.app_current_company_id()') IS NOT NULL AS has_schema
        SQL);
    if ($identity?->database_name !== $target['DB_MIGRATIONS_DATABASE']
        || $identity->username !== $target['DB_MIGRATIONS_USERNAME']
        || intdiv((int) $identity->version, 10000) !== 18
        || ! $identity->has_schema
        || ! $db->table('migrations')->exists()) {
        fwrite(STDERR, "La conexión no corresponde a PostgreSQL 18 local con el esquema contable instalado.\n");
        exit(1);
    }
    fwrite(STDOUT, "[PASS] Destino verificado: 127.0.0.1:55433/contabilidad_local.\n");

    $phase = 'aplicar las migraciones de Planillas';
    $migrations = [
        '2026_11_24_000000_create_company_payroll_revisions',
        '2026_11_24_000001_enable_payroll_parameter_history',
        '2026_11_24_000002_enable_payroll_inputs',
    ];
    foreach ($migrations as $migration) {
        if (! is_file($root.'/database/migrations/'.$migration.'.php')) {
            fwrite(STDERR, "Falta el archivo de migración $migration. Actualiza el repositorio.\n");
            exit(1);
        }
    }
    $exitCode = $kernel->call('migrate', [
        '--database' => $connection,
        '--path' => array_map(fn (string $migration): string => 'database/migrations/'.$migration.'.php', $migrations),
        '--force' => true,
        '--no-interaction' => true,
    ], new ConsoleOutput());
    if ($exitCode !== 0 || $db->table('migrations')->whereIn('migration', $migrations)->count() !== count($migrations)) {
        throw new RuntimeException('Migraciones pendientes.');
    }

    fwrite(STDOUT, "[PASS] Las migraciones de Planillas están registradas en la base local.\n");
} catch (Throwable) {
    fwrite(STDERR, "No se pudo $phase. Verifica que Docker esté activo y el perfil local sea el correcto.\n");
    exit(1);
}
