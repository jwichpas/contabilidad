<?php

declare(strict_types=1);

/**
 * F0 — diagnóstico de conexión administrativa sin exponer secretos.
 *
 * Desde backend:
 *   php scripts/phase0-check-migration-connection.php
 *
 * NO abre conexión a PostgreSQL, no imprime contraseñas/URL enteras, no ejecuta DDL.
 */
require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';

/** @var \Illuminate\Contracts\Console\Kernel $kernel */
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$config = (array) config('database.connections.pgsql_migrations', []);
$url = trim((string) ($config['url'] ?? ''));
$parts = $url === '' ? [] : parse_url($url);
$parts = is_array($parts) ? $parts : [];

$host = (string) ($parts['host'] ?? ($config['host'] ?? ''));
$port = (string) ($parts['port'] ?? ($config['port'] ?? ''));
$user = rawurldecode((string) ($parts['user'] ?? ($config['username'] ?? '')));
$dbname = trim((string) ($parts['path'] ?? ($config['database'] ?? '')), '/');
$hasPassword = ($url !== '' && isset($parts['pass']))
    || ($url === '' && is_string($config['password'] ?? null) && $config['password'] !== '');

$expectedHost = $host === 'db.bnjwxphwtkhblvhsnylj.supabase.co'
    || $host === 'aws-1-ca-central-1.pooler.supabase.com';
$expectedUser = $host === 'db.bnjwxphwtkhblvhsnylj.supabase.co'
    ? $user === 'postgres'
    : $user === 'postgres.bnjwxphwtkhblvhsnylj';

$report = [
    'ambiente_laravel' => $app->environment(),
    'archivo_de_entorno' => $app->environmentFile(),
    'conexion' => 'pgsql_migrations',
    'host_efectivo' => $host,
    'puerto_efectivo' => $port,
    'usuario_efectivo' => $user,
    'base_de_datos' => $dbname,
    'db_migrations_url_tiene_precedencia' => $url !== '',
    'hay_contrasena_configurada' => $hasPassword,
    'configuracion_cacheada' => $app->configurationIsCached(),
];

foreach ($report as $name => $value) {
    echo $name, ': ', is_bool($value) ? ($value ? 'SI' : 'NO') : $value, PHP_EOL;
}

if (! $expectedHost || ! $expectedUser || $port !== '5432' || $dbname !== 'postgres'
    || ! $hasPassword) {
    fwrite(STDERR, PHP_EOL.'[BLOQUEO] Conexion administrativa no valida para Artisan.'
        .' Corrija DB_MIGRATIONS_HOST/PORT/USERNAME/PASSWORD y quite DB_MIGRATIONS_URL'
        .' si apunta a la conexion de aplicacion. No ejecute migraciones todavia.'.PHP_EOL);
    exit(1);
}

echo PHP_EOL.'[OK] Parametros administrativos coherentes; esto no comprueba'
    .' la contrasena ni que la conexion se establezca.'.PHP_EOL;
