<?php

declare(strict_types=1);

/**
 * Diagnóstico F0 del login (SIN Tinker, SOLO SELECT, SIN contraseñas).
 *
 * Uso: php scripts/phase0-login-diagnostics.php [correo@dominio.tld]
 *
 * Nunca crear/editar usuarios, tokens, roles, migraciones ni sesiones.
 * El resultado solo indica existencia del correo, no revela hashes o IDs.
 */
require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
/** @var \Illuminate\Contracts\Console\Kernel $console */
$console = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$console->bootstrap();

use Illuminate\Support\Facades\DB;

if (count($argv) > 2 || (count($argv) === 2 && ! filter_var($argv[1], FILTER_VALIDATE_EMAIL))) {
    fwrite(STDERR, 'Uso: php scripts/phase0-login-diagnostics.php [correo@dominio.tld]'.PHP_EOL);
    exit(2);
}

$cfg = (array) config('database.connections.pgsql');
if (! $app->environment('local')
    || trim((string) ($cfg['url'] ?? '')) !== ''
    || ($cfg['host'] ?? '') !== 'aws-1-ca-central-1.pooler.supabase.com'
    || (string) ($cfg['port'] ?? '') !== '6543'
    || ($cfg['username'] ?? '') !== 'app_backend.bnjwxphwtkhblvhsnylj') {
    fwrite(STDERR, '[BLOQUEO] Conexion no coincide con el entorno local aprobado de Supabase.'.PHP_EOL);
    exit(2);
}

$driver = (string) config('cache.default', 'unknown');
$limiter = (string) (config('cache.limiter') ?: $driver);
echo '[CONFIG] APP_ENV=local'.PHP_EOL;
echo '[CONFIG] CACHE_STORE='.$driver.'; limitador='.$limiter.PHP_EOL;

try {
    $db = DB::connection('pgsql');
    $identity = $db->selectOne('SELECT current_user AS role_name, current_database() AS database_name');
    if ($identity?->role_name !== 'app_backend' || $identity?->database_name !== 'postgres') {
        throw new RuntimeException('Rol de aplicacion inesperado');
    }
    echo '[OK] Conexion PostgreSQL: rol app_backend y BD postgres.'.PHP_EOL;

    $table = $db->selectOne("SELECT to_regclass('public.cache') IS NOT NULL AS cache_exists");
    $cacheExists = filter_var((string) ($table?->cache_exists ?? false), FILTER_VALIDATE_BOOLEAN);
    if ($limiter === 'database' && ! $cacheExists) {
        echo '[WARN] El limitador usa database pero NO existe tabla public.cache; '.PHP_EOL;
        echo '       configure CACHE_STORE=file en backend/.env y ejecute php artisan config:clear.'.PHP_EOL;
    } elseif ($limiter === 'file') {
        echo '[OK] Limitador de login usa cache de archivos (sin tabla PostgreSQL).'.PHP_EOL;
    } else {
        echo '[INFO] Revisar manualmente la disponibilidad del limitador configurado.'.PHP_EOL;
    }

    if (count($argv) === 2) {
        $email = mb_strtolower(trim((string) $argv[1]));
        $exists = $db->table('users')->whereRaw('lower(email) = ?', [$email])->exists();
        echo $exists
            ? '[USUARIO] Correo existente en la BD de Laravel. Revisar credencial de forma privada.'.PHP_EOL
            : '[USUARIO] Correo NO registrado en la BD de Laravel.'.PHP_EOL;
    } else {
        echo '[USUARIO] Sin correo: no se hizo ninguna consulta por identidad.'.PHP_EOL;
    }

    echo '[OK] Solo SELECT, sin escrituras ni modificaciones de credenciales.'.PHP_EOL;
} catch (Throwable $e) {
    $code = (string) $e->getCode();
    $safeCode = preg_match('/^[A-Z0-9]{5}$/', $code) ? $code : 'UNKNOWN';
    fwrite(STDERR, '[ERROR] Diagnostico incompleto, SQLSTATE='.$safeCode
        .'. Revise storage/logs/laravel.log local, ocultando secretos.'.PHP_EOL);
    exit(1);
}
