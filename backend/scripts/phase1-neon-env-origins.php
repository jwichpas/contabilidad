<?php

declare(strict_types=1);

/**
 * Diagnostico de origen de variables para Neon. SIN SQL ni escrituras.
 * Separa estado de proceso ANTES de Laravel, .env en disco y config Laravel.
 * Imprime solo estados, nunca valores (host, usuario, passwords o tokens).
 *
 * Ejecutar: php scripts/phase1-neon-env-origins.php
 */

$backend = dirname(__DIR__);
$keys = [
    'APP_ENV', 'APP_KEY', 'DB_CONNECTION', 'DB_URL', 'DATABASE_URL',
    'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
    'DB_SSLMODE', 'DB_EMULATE_PREPARES', 'DB_MIGRATIONS_URL',
    'DB_MIGRATIONS_HOST', 'DB_MIGRATIONS_PORT', 'DB_MIGRATIONS_DATABASE',
    'DB_MIGRATIONS_USERNAME', 'DB_MIGRATIONS_PASSWORD',
    'DB_MIGRATIONS_SSLMODE', 'DB_APP_BACKEND_ROLE_PASSWORD',
    'NEON_TEST_BRANCH_NAME', 'NEON_TEST_BRANCH_HOST', 'NEON_USE_POOLING',
];

/** @return string */
function presence(mixed $value, bool $exists = true): string
{
    if (! $exists) {
        return 'AUSENTE';
    }

    return trim((string) $value) === '' ? 'VACIO' : 'CON_VALOR';
}

$before = [];
foreach ($keys as $key) {
    $process = getenv($key);
    $before[$key] = [
        'proceso' => presence($process, $process !== false),
        '_ENV' => presence($_ENV[$key] ?? null, array_key_exists($key, $_ENV)),
        '_SERVER' => presence($_SERVER[$key] ?? null, array_key_exists($key, $_SERVER)),
    ];
}

$file = $backend.'/.env';
$fileState = [];
$duplicates = [];
$rawKey = null;
$credentialStates = [];
if (is_file($file)) {
    $contents = file_get_contents($file);
    if ($contents === false) {
        fwrite(STDERR, "[FAIL] No fue posible leer el archivo .env privado.\n");
        exit(1);
    }

    foreach (preg_split('/\r\n|\n|\r/', $contents) as $line) {
        if (! preg_match('/^[ \t]*(?:export[ \t]+)?([A-Z][A-Z0-9_]*)[ \t]*=(.*)$/', $line, $parts)) {
            continue;
        }
        $key = $parts[1];
        if (! in_array($key, $keys, true)) {
            continue;
        }
        if (isset($fileState[$key])) {
            $duplicates[$key] = true;
        }
        $value = trim($parts[2]);
        if (strlen($value) >= 2
            && (($value[0] === '"' && str_ends_with($value, '"'))
                || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        $fileState[$key] = presence($value);
        if ($key === 'APP_KEY') {
            $rawKey = $value;
        }
        if (in_array($key, ['DB_PASSWORD','DB_APP_BACKEND_ROLE_PASSWORD','DB_APP_BACKEND_NEW_PASSWORD'], true)) {
            $credentialStates[$key] = $value;
        }
    }
    unset($contents, $value);
}

echo '[ORIGEN] Estado ANTES de iniciar Laravel (no son valores secretos):'.PHP_EOL;
echo '[ORIGEN] .env existe: '.(is_file($file) ? 'SI' : 'NO').PHP_EOL;
foreach ($keys as $key) {
    $expected = $fileState[$key] ?? 'AUSENTE';
    $sources = $before[$key];
    // Solo se muestran todas las fuentes para claves faltantes/ocultadas.
    if ($expected === 'CON_VALOR'
        && $sources['proceso'] === 'AUSENTE'
        && $sources['_ENV'] === 'AUSENTE'
        && $sources['_SERVER'] === 'AUSENTE') {
        continue;
    }
    if ($expected === 'AUSENTE' && $sources['proceso'] === 'AUSENTE'
        && $sources['_ENV'] === 'AUSENTE' && $sources['_SERVER'] === 'AUSENTE') {
        continue;
    }
    echo '  '.$key.' .env='.$expected
        .' proceso='.$sources['proceso']
        .' _ENV='.$sources['_ENV']
        .' _SERVER='.$sources['_SERVER'].PHP_EOL;
}
if ($duplicates !== []) {
    echo '[ALERTA] Entradas duplicadas en .env: '.implode(', ', array_keys($duplicates)).PHP_EOL;
}
if ($rawKey !== null) {
    $validKey = str_starts_with($rawKey, 'base64:')
        ? (strlen((string) base64_decode(substr($rawKey, 7), true)) === 32)
        : strlen($rawKey) === 32;
    echo '[INFO] APP_KEY con longitud valida para Laravel: '.($validKey ? 'SI' : 'NO').PHP_EOL;
}
unset($rawKey);

// Comparaciones en memoria: no se muestran claves, valores ni hashes.
$role = (string) ($credentialStates['DB_APP_BACKEND_ROLE_PASSWORD'] ?? '');
$appPassword = (string) ($credentialStates['DB_PASSWORD'] ?? '');
$newPassword = (string) ($credentialStates['DB_APP_BACKEND_NEW_PASSWORD'] ?? '');
echo '[INFO] DB_APP_BACKEND_ROLE_PASSWORD presente (archivo): '.($role !== '' ? 'SI' : 'NO').PHP_EOL;
echo '[INFO] DB_PASSWORD coincide con DB_APP_BACKEND_ROLE_PASSWORD: '
    .($role !== '' && $appPassword !== '' && hash_equals($role,$appPassword) ? 'SI' : 'NO').PHP_EOL;
echo '[INFO] DB_APP_BACKEND_NEW_PASSWORD coincide con rol (solo rotacion): '
    .($role !== '' && $newPassword !== '' && hash_equals($role,$newPassword) ? 'SI' : 'NO').PHP_EOL;
unset($role,$appPassword,$newPassword,$credentialStates);

if (! is_file($backend.'/vendor/autoload.php')) {
    fwrite(STDERR, "[FAIL] Falta vendor/autoload.php; ejecutar composer install en entorno seguro.\n");
    exit(1);
}

try {
    require $backend.'/vendor/autoload.php';
    $app = require $backend.'/bootstrap/app.php';
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    echo '[LARAVEL] Archivo de entorno seleccionado: '.$app->environmentFile().PHP_EOL;
    echo '[LARAVEL] Entorno resuelto: '.presence($app->environment()).PHP_EOL;
    echo '[LARAVEL] Configuracion de BD cargada: '
        .(app()->bound('config') && config()->has('database.connections.pgsql_migrations') ? 'SI' : 'NO')
        .PHP_EOL;

    foreach (['APP_ENV','DB_CONNECTION','DB_HOST','DB_MIGRATIONS_HOST',
              'DB_MIGRATIONS_DATABASE','NEON_TEST_BRANCH_NAME','NEON_TEST_BRANCH_HOST'] as $key) {
        $resolved = env($key, null);
        echo '  '.$key.' .env='.($fileState[$key] ?? 'AUSENTE')
            .' env()='.presence($resolved, $resolved !== null)
            .' preProceso='.$before[$key]['proceso']
            .' pre_ENV='.$before[$key]['_ENV']
            .' pre_SERVER='.$before[$key]['_SERVER'].PHP_EOL;
    }
    $loadedKey = (string) env('APP_KEY','');
    $validLoadedKey = false;
    if (str_starts_with($loadedKey,'base64:')) {
        $decoded = base64_decode(substr($loadedKey,7),true);
        $validLoadedKey = $decoded !== false && strlen($decoded) === 32;
    } else {
        $validLoadedKey = strlen($loadedKey) === 32;
    }
    echo '[LARAVEL] APP_KEY cargada tiene 32 bytes validos: '.($validLoadedKey ? 'SI' : 'NO').PHP_EOL;
    unset($loadedKey,$decoded);
    $admin = (array) config('database.connections.pgsql_migrations', []);
    $traffic = (array) config('database.connections.pgsql', []);
    foreach ([
        'config.pgsql.host' => $traffic['host'] ?? null,
        'config.pgsql.database' => $traffic['database'] ?? null,
        'config.pgsql_migrations.host' => $admin['host'] ?? null,
        'config.pgsql_migrations.database' => $admin['database'] ?? null,
    ] as $label => $value) {
        echo '[LARAVEL] '.$label.'='.presence($value, $value !== null).PHP_EOL;
    }
    echo '[FIN] Solo diagnostico: no se realizaron conexiones SQL ni se modificaron archivos.'.PHP_EOL;
} catch (\Throwable $exception) {
    // No imprimir excepciones que pudieran incluir cadenas de conexion.
    fwrite(STDERR, '[FAIL] No fue posible terminar el bootstrap de Laravel; revisar configuracion sin compartir secretos.'.PHP_EOL);
    exit(1);
}
