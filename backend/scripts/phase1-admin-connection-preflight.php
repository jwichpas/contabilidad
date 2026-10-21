<?php

declare(strict_types=1);

/**
 * F1.5: diagnostico local SIN SQL de pgsql_migrations.
 * Nunca muestra contrasenas ni URLs completas ni establece conexion remota.
 *
 * php scripts/phase1-admin-connection-preflight.php
 */
$backend = dirname(__DIR__);
require $backend.'/vendor/autoload.php';

if (is_file($backend.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "[FAIL] Configuracion Laravel cacheada. Revisar bootstrap/cache/config.php antes de continuar.\n");
    exit(2);
}

$app = require $backend.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$config = (array) config('database.connections.pgsql_migrations', []);
$envExists = is_file($backend.'/.env');
$environment = (string) $app->environment();
$url = trim((string) ($config['url'] ?? ''));
$host = trim((string) ($config['host'] ?? ''));
$port = trim((string) ($config['port'] ?? ''));
$database = trim((string) ($config['database'] ?? ''));
$username = trim((string) ($config['username'] ?? ''));
$password = (string) ($config['password'] ?? '');
$sslmode = trim((string) ($config['sslmode'] ?? ''));
$issues = [];

echo '[INFO] APP_ENV: '.$environment.PHP_EOL;
echo '[INFO] backend/.env existe: '.($envExists ? 'SI' : 'NO').PHP_EOL;
echo '[INFO] backend/.env.testing existe: '.(is_file($backend.'/.env.testing') ? 'SI' : 'NO').PHP_EOL;
echo '[INFO] DB_MIGRATIONS_URL: '.($url !== '' ? 'CONFIGURADA' : 'NO CONFIGURADA').PHP_EOL;

if ($environment !== 'local') {
    $issues[] = 'Este preflight para Supabase Dev requiere APP_ENV=local.';
}
if (! $envExists) {
    $issues[] = 'Falta backend/.env: crear un archivo privado partiendo de .env.example, sin sobrescribir un .env existente.';
}

if ($url !== '') {
    $parsed = parse_url($url);
    if ($parsed === false || ! in_array((string) ($parsed['scheme'] ?? ''), ['postgres', 'postgresql', 'pgsql'], true)) {
        $issues[] = 'DB_MIGRATIONS_URL tiene una URI PostgreSQL invalida.';
    } else {
        $queryString = (string) ($parsed['query'] ?? '');
        $query = [];
        parse_str($queryString, $query);
        if (str_contains($queryString, '?') || str_contains($queryString, ';')) {
            $issues[] = 'La URI administrativa contiene separadores de parametros incorrectos: usar & entre los parametros.';
        }
        if (isset($query['sslmode']) && ! in_array((string) $query['sslmode'], ['disable','allow','prefer','require','verify-ca','verify-full'], true)) {
            $issues[] = 'El sslmode de DB_MIGRATIONS_URL es invalido.';
        }
        $host = (string) ($parsed['host'] ?? $host);
        $port = (string) ($parsed['port'] ?? $port);
        $database = ltrim((string) ($parsed['path'] ?? $database), '/');
        $username = (string) ($parsed['user'] ?? $username);
        $password = (string) ($parsed['pass'] ?? $password);
        $sslmode = (string) ($query['sslmode'] ?? $sslmode);
    }
}

echo '[INFO] Host: '.($host !== '' ? $host : '(vacio)').PHP_EOL;
echo '[INFO] Puerto: '.($port !== '' ? $port : '(vacio)').PHP_EOL;
echo '[INFO] Base: '.($database !== '' ? $database : '(vacia)').PHP_EOL;
echo '[INFO] Usuario administrativo: '.($username !== '' ? 'CONFIGURADO' : 'NO CONFIGURADO').PHP_EOL;
echo '[INFO] Contrasena administrativa: '.($password !== '' ? 'CONFIGURADA' : 'NO CONFIGURADA').PHP_EOL;
echo '[INFO] SSLMODE: '.($sslmode !== '' ? $sslmode : '(vacio)').PHP_EOL;

$direct = $host === 'db.bnjwxphwtkhblvhsnylj.supabase.co' && $username === 'postgres';
$sessionPool = $host === 'aws-1-ca-central-1.pooler.supabase.com'
    && $username === 'postgres.bnjwxphwtkhblvhsnylj';

if (! $direct && ! $sessionPool) {
    $issues[] = 'El host/usuario debe ser del proyecto contabilidad-database: postgres directo o postgres.<ref> en pooler de sesion.';
}
if ($port !== '5432') {
    $issues[] = 'Se requiere puerto administrativo 5432 (no 6543 de transacciones).';
}
if ($database !== 'postgres') {
    $issues[] = 'DB_MIGRATIONS_DATABASE debe ser postgres.';
}
if ($password === '') {
    $issues[] = 'Falta la contrasena administrativa en DB_MIGRATIONS_PASSWORD (o URI).';
}
if (! in_array($sslmode, ['require', 'verify-ca', 'verify-full'], true)) {
    $issues[] = 'SSL administrativo invalido: utilizar require, verify-ca o verify-full.';
}

if ($issues !== []) {
    foreach (array_unique($issues) as $issue) {
        fwrite(STDERR, '[FAIL] '.$issue.PHP_EOL);
    }
    fwrite(STDERR, "[BLOQUEO] No ejecutar migrate. Revisar el archivo backend/.env local; no compartir secretos.\n");
    exit(1);
}

echo '[PASS] Configuracion administrativa coherente para Supabase Dev. No se realizo SQL.'.PHP_EOL;
echo '[PENDIENTE] Comprobar conexion e identidad reales antes de aplicar DDL.'.PHP_EOL;
