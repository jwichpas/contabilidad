<?php

declare(strict_types=1);

/**
 * F1.4: diagnostico de CONFIGURACION administrativa, SIN abrir conexiones SQL.
 *
 * Uso en Windows:
 *   php scripts/phase1-admin-connection-preflight.php
 *
 * No imprime credenciales, tokens, URLs completas ni valores de contrasena.
 * No conecta a Supabase, no ejecuta migraciones ni altera datos.
 */
require dirname(__DIR__).'/vendor/autoload.php';

if (is_file(dirname(__DIR__).'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "[FAIL] Hay config cacheada; no diagnosticar hasta revisar bootstrap/cache/config.php.\n");
    exit(2);
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$config = \Illuminate\Support\Facades\DB::connection('pgsql_migrations')->getConfig();

$url = trim((string) ($config['url'] ?? ''));
$host = trim((string) ($config['host'] ?? ''));
$port = trim((string) ($config['port'] ?? ''));
$dbName = trim((string) ($config['database'] ?? ''));
$user = trim((string) ($config['username'] ?? ''));
$sslmode = trim((string) ($config['sslmode'] ?? ''));

echo '[INFO] DB_MIGRATIONS_URL configurada: '.($url !== '' ? 'SI' : 'NO').PHP_EOL;
echo '[INFO] Host administrativo: '.($host !== '' ? $host : '(vacio)').PHP_EOL;
echo '[INFO] Puerto administrativo: '.($port !== '' ? $port : '(vacio)').PHP_EOL;
echo '[INFO] Base administrativa: '.($dbName !== '' ? $dbName : '(vacia)').PHP_EOL;
echo '[INFO] SSLMODE: '.($sslmode !== '' ? $sslmode : '(vacio)').PHP_EOL;
echo '[INFO] Usuario configurado: '.($user !== '' ? 'SI' : 'NO').PHP_EOL;

$problems = [];

if ($url !== '') {
    $parsed = parse_url($url);
    if ($parsed === false || ! in_array((string) ($parsed['scheme'] ?? ''), ['postgres', 'postgresql', 'pgsql'], true)) {
        $problems[] = 'DB_MIGRATIONS_URL no tiene una URI PostgreSQL valida.';
    } else {
        $query = [];
        parse_str((string) ($parsed['query'] ?? ''), $query);
        if (isset($query['sslmode']) && ! in_array((string) $query['sslmode'], ['disable','allow','prefer','require','verify-ca','verify-full'], true)) {
            $problems[] = 'DB_MIGRATIONS_URL contiene sslmode invalido (revisar parametros de consulta y &).';
        }
        if (! isset($parsed['host']) || ! isset($parsed['path'])) {
            $problems[] = 'La URI de migraciones debe incluir host y nombre de base.';
        }
    }
}

if (! in_array($sslmode, ['disable','allow','prefer','require','verify-ca','verify-full'], true)) {
    $problems[] = 'DB_MIGRATIONS_SSLMODE no es uno de los valores admitidos por libpq.';
}
if ($host === '' || $port === '' || $dbName === '' || $user === '') {
    $problems[] = 'Falta host, puerto, nombre de base o usuario en la configuracion administrativa.';
}
if ($dbName !== 'postgres') {
    $problems[] = 'Se esperaba DB_MIGRATIONS_DATABASE=postgres para Supabase Dev.';
}
if ($host !== 'db.bnjwxphwtkhblvhsnylj.supabase.co'
    && $host !== 'aws-1-ca-central-1.pooler.supabase.com') {
    $problems[] = 'El host no coincide con el proyecto Supabase Dev autorizado.';
}
if ($port !== '5432') {
    $problems[] = 'Se esperaba el puerto administrativo 5432, nunca 6543 de pool transaccional.';
}

if ($problems !== []) {
    foreach ($problems as $problem) {
        fwrite(STDERR, '[FAIL] '.$problem.PHP_EOL);
    }
    fwrite(STDERR, "[INFO] Revisar backend/.env sin enviar contrasenas, URIs ni tokens. No ejecutar migrate.\n");
    exit(1);
}

echo '[PASS] Configuracion administrativa coherente; no se realizo ninguna conexion SQL.'.PHP_EOL;
echo '[SIGUIENTE] Validar conectividad y destino real antes de migrar, por separado.'.PHP_EOL;
