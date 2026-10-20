<?php

declare(strict_types=1);

/**
 * Diagnóstico de F0: simula APP_ENV=testing sin abrir conexión a PostgreSQL.
 * Nunca imprime passwords ni URLs completas (podrían incluir secretos).
 *
 * Desde backend/: php scripts/phase0-check-test-connection.php
 */
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';

/** @var \Illuminate\Contracts\Console\Kernel $kernel */
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$default = (string) config('database.default');
$config = (array) config('database.connections.pgsql', []);
$connectionUrl = trim((string) ($config['url'] ?? ''));
$parsed = $connectionUrl === '' ? [] : parse_url($connectionUrl);
$parts = is_array($parsed) ? $parsed : [];

$effectiveHost = (string) ($parts['host'] ?? ($config['host'] ?? ''));
$effectivePort = (string) ($parts['port'] ?? ($config['port'] ?? ''));
$effectiveUser = rawurldecode((string) ($parts['user'] ?? ($config['username'] ?? '')));
$effectiveDatabase = trim((string) ($parts['path'] ?? ($config['database'] ?? 'postgres')), '/');

$report = [
    'ambiente_laravel' => $app->environment(),
    'archivo_de_entorno' => $app->environmentFile(),
    'conexion_por_defecto' => $default,
    'host_efectivo' => $effectiveHost,
    'puerto_efectivo' => $effectivePort,
    'usuario_efectivo' => $effectiveUser,
    'base_de_datos' => $effectiveDatabase,
    'url_db_tiene_precedencia' => $connectionUrl !== '',
    'configuracion_cacheada' => $app->configurationIsCached(),
];

foreach ($report as $key => $value) {
    echo $key, ': ', is_bool($value) ? ($value ? 'SI' : 'NO') : $value, PHP_EOL;
}

if ($default !== 'pgsql' || ! str_starts_with($effectiveUser, 'app_backend')) {
    fwrite(
        STDERR,
        PHP_EOL.'[BLOQUEO] PHPUnit debe usar la conexion pgsql con app_backend; '
        .'no ejecutar tests Feature con postgres. Revise .env.testing, DB_URL, DATABASE_URL '
        .'y variables del sistema (sin publicar credenciales).'.PHP_EOL
    );
    exit(1);
}

echo PHP_EOL.'[OK] Identidad aparente del backend: app_backend. '
    .'Este preflight NO verifico la contrasena ni se conecto a PostgreSQL.'.PHP_EOL;
