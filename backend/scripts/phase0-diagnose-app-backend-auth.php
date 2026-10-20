<?php

declare(strict_types=1);

/**
 * F0 — diagnóstico no destructivo de credenciales para app_backend.
 * Uso desde backend/: php scripts/phase0-diagnose-app-backend-auth.php
 *
 * Verifica que DB_PASSWORD coincida con DB_APP_BACKEND_NEW_PASSWORD y,
 * solo si coinciden, intenta conectar directamente (IPv6) con PostgreSQL
 * para descartar una caché antigua de Supavisor.
 *
 * No escribe en PostgreSQL ni imprime secretos, hashes, DSN o excepciones PDO.
 */

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
/** @var \Illuminate\Contracts\Console\Kernel $kernel */
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$settings = (array) config('database.connections.pgsql', []);
$url = trim((string) ($settings['url'] ?? ''));
$host = (string) ($settings['host'] ?? '');
$port = (string) ($settings['port'] ?? '');
$user = (string) ($settings['username'] ?? '');
$name = (string) ($settings['database'] ?? '');

$expected = $url === ''
    && $host === 'aws-1-ca-central-1.pooler.supabase.com'
    && $port === '6543'
    && $user === 'app_backend.bnjwxphwtkhblvhsnylj'
    && $name === 'postgres';

if (! $expected) {
    fwrite(STDERR, '[BLOQUEO] Conexion pgsql no es el rol app_backend del proyecto '
        .'contabilidad-database o existe DB_URL/DATABASE_URL prioritaria.'.PHP_EOL);
    exit(1);
}

$password = $settings['password'] ?? null;
$rotationPassword = env('DB_APP_BACKEND_NEW_PASSWORD');

if (! is_string($password) || strlen($password) < 32) {
    fwrite(STDERR, '[BLOQUEO] DB_PASSWORD no contiene una clave valida de 32 o mas caracteres.'.PHP_EOL);
    exit(1);
}

if (! is_string($rotationPassword) || $rotationPassword === '') {
    fwrite(STDERR, '[BLOQUEO] DB_APP_BACKEND_NEW_PASSWORD ya no esta disponible.'
        .' No se puede comprobar la igualdad de claves; no reintroduzca la variable'
        .' ni gire el rol sin verificar antes la clave que se uso.'.PHP_EOL);
    exit(1);
}

$matching = hash_equals($password, $rotationPassword);
echo 'DB_PASSWORD y DB_APP_BACKEND_NEW_PASSWORD coinciden: ',
    $matching ? 'SI' : 'NO', PHP_EOL;
if (! $matching) {
    fwrite(STDERR, '[BLOQUEO] Hay una discrepancia local entre las dos claves.'
        .' Corrija backend/.env sin compartir contrasenas y NO rote nuevamente.'.PHP_EOL);
    exit(1);
}

echo 'Probando conexion DIRECTA IPv6 a Postgres con app_backend (solo SELECT)...', PHP_EOL;
try {
    $pdo = new \PDO(
        'pgsql:host=db.bnjwxphwtkhblvhsnylj.supabase.co;port=5432;dbname=postgres;sslmode=require;connect_timeout=6',
        'app_backend',
        $password,
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
    );
    $role = $pdo->query('SELECT current_user')->fetchColumn();
    $pdo = null;
    if ($role !== 'app_backend') {
        fwrite(STDERR, '[BLOQUEO] La conexion directa no obtuvo el rol app_backend.'.PHP_EOL);
        exit(1);
    }
    echo '[OK] Conexion DIRECTA exitosa con app_backend. Si Supavisor 6543 falla,'
        .' puede deberse a su cache temporal de contrasenas.'.PHP_EOL;
} catch (\PDOException $e) {
    $message = $e->getMessage();
    if (str_contains($message, 'password authentication failed') || (string) $e->getCode() === '28P01') {
        echo '[FALLO_AUTH_DIRECTA] Postgres directo rechazo la clave nueva.'
            .' No es un problema exclusivo de cache de Supavisor.'.PHP_EOL;
    } else {
        echo '[SIN_CONCLUSION_DIRECTA] No se pudo establecer conexion IPv6 directa.'
            .' SQLSTATE=', preg_replace('/[^A-Z0-9]/', '', (string) $e->getCode()), '.'
            .' Esto NO demuestra que la clave este equivocada; puede ser la red IPv6.'.PHP_EOL;
    }
    exit(2);
}
