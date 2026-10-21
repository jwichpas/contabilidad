<?php

declare(strict_types=1);

/**
 * F1.6 — Neon Postgres: comprobacion SEGURA de conexion y destino.
 *
 * Modo predeterminado: NO conecta ni ejecuta SQL.
 * --read-only: solo catalogos PG, nunca DML/DDL. Requiere superar primero
 * las barreras de entorno (rama distinta de la raiz production).
 *
 * Ejecutar dentro de backend:
 *   php scripts/phase1-neon-preflight.php
 *   php scripts/phase1-neon-preflight.php --read-only
 *   php scripts/phase1-neon-preflight.php --read-only --require-empty
 */
$backend = dirname(__DIR__);
require $backend.'/vendor/autoload.php';

$readOnly = ($argc === 2 && ($argv[1] ?? '') === '--read-only')
    || ($argc === 3 && ($argv[1] ?? '') === '--read-only' && ($argv[2] ?? '') === '--require-empty');
$requireEmpty = $argc === 3 && ($argv[2] ?? '') === '--require-empty';
if ($argc > 1 && ! $readOnly) {
    fwrite(STDERR, "Uso: php scripts/phase1-neon-preflight.php [--read-only [--require-empty]]\n");
    exit(2);
}
if (is_file($backend.'/bootstrap/cache/config.php')) {
    fwrite(STDERR, "[FAIL] Config Laravel cacheada; revisar bootstrap/cache/config.php antes de continuar.\n");
    exit(2);
}

$app = require $backend.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$admin = (array) config('database.connections.pgsql_migrations', []);
$appDb = (array) config('database.connections.pgsql', []);
$adminUrl = trim((string) ($admin['url'] ?? ''));
$appUrl = trim((string) ($appDb['url'] ?? ''));
$envExists = is_file($backend.'/.env');
$branch = trim((string) env('NEON_TEST_BRANCH_NAME', ''));
$expectedHost = trim((string) env('NEON_TEST_BRANCH_HOST', ''));
$host = trim((string) ($admin['host'] ?? ''));
$port = trim((string) ($admin['port'] ?? ''));
$dbName = trim((string) ($admin['database'] ?? ''));
$user = trim((string) ($admin['username'] ?? ''));
$password = (string) ($admin['password'] ?? '');
$ssl = trim((string) ($admin['sslmode'] ?? ''));
$appHost = trim((string) ($appDb['host'] ?? ''));
$appPort = trim((string) ($appDb['port'] ?? ''));
$appDatabase = trim((string) ($appDb['database'] ?? ''));
$appUser = trim((string) ($appDb['username'] ?? ''));
$poolingEnabled = filter_var(env('NEON_USE_POOLING', false), FILTER_VALIDATE_BOOLEAN);
$issues = [];

$productionHost = 'ep-ancient-brook-b6llhj4d.c-2.sa-east-1.aws.neon.tech';

echo '[INFO] Modo: '.($readOnly ? 'SELECT de catalogos (opcional)' : 'configuracion local sin SQL').PHP_EOL;
echo '[INFO] .env local: '.($envExists ? 'SI' : 'NO').PHP_EOL;
echo '[INFO] APP_ENV: '.$app->environment().PHP_EOL;
echo '[INFO] Rama declarada: '.($branch !== '' ? $branch : '(vacia)').PHP_EOL;
echo '[INFO] Host directo configurado: '.($host !== '' ? $host : '(vacio)').PHP_EOL;
echo '[INFO] Host pooled configurado: '.($appHost !== '' ? $appHost : '(vacio)').PHP_EOL;
echo '[INFO] Base: '.($dbName !== '' ? $dbName : '(vacia)').PHP_EOL;
echo '[INFO] Credencial admin: '.($password !== '' ? 'CONFIGURADA' : 'NO CONFIGURADA').PHP_EOL;
echo '[INFO] Credencial app_backend: '.((string) ($appDb['password'] ?? '') !== '' ? 'CONFIGURADA' : 'NO CONFIGURADA').PHP_EOL;

if (! $envExists) {
    $issues[] = 'Falta backend/.env (crear desde .env.example sin sobrescribir existente).';
}
if (! $app->environment('local')) {
    $issues[] = 'APP_ENV debe ser local para el preflight Neon de pruebas.';
}
if ($adminUrl !== '' || $appUrl !== '') {
    $issues[] = 'Vaciar DB_URL y DB_MIGRATIONS_URL, preferir HOST/PORT/USERNAME por separado para diagnosticar sin URLs sensibles.';
}
if ($branch === '' || in_array(strtolower($branch), ['production','prod','main','master'], true)) {
    $issues[] = 'NEON_TEST_BRANCH_NAME debe identificar una rama de pruebas distinta de production/main.';
}
if ($expectedHost === '' || ! preg_match('/^ep-[a-z0-9-]+[.](?:[a-z0-9-]+[.]){1,3}aws[.]neon[.]tech$/', $expectedHost)) {
    $issues[] = 'NEON_TEST_BRANCH_HOST debe ser el host directo del endpoint de pruebas copiado del panel Neon.';
}
if ($host === $productionHost || $expectedHost === $productionHost) {
    $issues[] = 'Bloqueo: el host pertenece a la rama production mostrada en la captura; usar otro endpoint de pruebas.';
}
if ($host === '' || $host !== $expectedHost || str_contains($host, '-pooler.')) {
    $issues[] = 'Host de migraciones no coincide con NEON_TEST_BRANCH_HOST o tiene pooling.';
}
// Neon permite conexion directa mientras pooling siga deshabilitado.
// Si se habilita PgBouncer, exigir el hostname derivado de la MISMA rama.
$firstDot = strpos($host, '.');
$derivedPooled = $firstDot !== false ? substr($host, 0, $firstDot).'-pooler'.substr($host, $firstDot) : '';
$expectedAppHost = $poolingEnabled ? $derivedPooled : $host;
if ($appHost === '' || $appHost !== $expectedAppHost) {
    $issues[] = 'DB_HOST no corresponde a la conexion directa/pooled elegida para la MISMA rama.';
}
$emulatePrepares = (bool) ($appDb['options'][\PDO::ATTR_EMULATE_PREPARES] ?? false);
if ($poolingEnabled !== $emulatePrepares) {
    $issues[] = 'DB_EMULATE_PREPARES debe ser false en directo y true con pooling de transacciones.';
}
if ($port !== '5432' || $appPort !== '5432') {
    $issues[] = 'Neon usa puerto 5432 en host directo y pooled.';
}
if ($dbName !== 'neondb' || $appDatabase !== $dbName) {
    $issues[] = 'Ambas conexiones deben apuntar a la misma base neondb de pruebas.';
}
if ($user !== 'neondb_owner') {
    $issues[] = 'DB_MIGRATIONS_USERNAME debe ser neondb_owner para inicializar esquema.';
}
if ($appUser !== 'app_backend') {
    $issues[] = 'DB_USERNAME debe ser app_backend (no el propietario administrativo).';
}
if ($password === '') {
    $issues[] = 'Falta DB_MIGRATIONS_PASSWORD en .env; nunca subirla a Git.';
}
if (! in_array($ssl, ['require','verify-ca','verify-full'], true)
    || ! in_array((string) ($appDb['sslmode'] ?? ''), ['require','verify-ca','verify-full'], true)) {
    $issues[] = 'SSL no valido: usar require, verify-ca o verify-full.';
}
if ((string) config('database.default') !== 'pgsql') {
    $issues[] = 'DB_CONNECTION debe ser pgsql.';
}
$rolePassword = (string) env('DB_APP_BACKEND_ROLE_PASSWORD', '');
if (strlen($rolePassword) < 20) {
    $issues[] = 'Definir DB_APP_BACKEND_ROLE_PASSWORD aleatoria de al menos 20 caracteres antes de inicializar el esquema.';
}

if ($issues !== []) {
    foreach (array_unique($issues) as $msg) {
        fwrite(STDERR, '[FAIL] '.$msg.PHP_EOL);
    }
    fwrite(STDERR, "[BLOQUEO] No ejecutar migrations ni pruebas con escrituras en Neon.\n");
    exit(1);
}
echo '[PASS] Config Neon: rama pruebas, admin directo y app '.($poolingEnabled ? 'pooled' : 'directa').'.'.PHP_EOL;
echo '[AVISO] El nombre de rama NO es verificable por SQL; confirmar visualmente el endpoint en Neon Connect.'.PHP_EOL;

if (! $readOnly) {
    echo "[PENDIENTE] Ejecutar --read-only para confirmar PostgreSQL/version/roles antes de preparar schema.\n";
    exit(0);
}

// Ninguna escritura: consulta catalogos en el endpoint permitido por preflight.
try {
    $connection = \Illuminate\Support\Facades\DB::connection('pgsql_migrations');
    $identity = $connection->selectOne(<<<'SQL'
        SELECT current_database() AS db,
               current_user AS username,
               current_setting('server_version_num')::integer AS version_num,
               (SELECT count(*) FROM pg_catalog.pg_roles
                 WHERE rolname = 'app_backend' AND NOT rolbypassrls AND NOT rolsuper) AS restricted_roles,
               (SELECT count(*) FROM pg_catalog.pg_roles WHERE rolname='neon_superuser') AS neon_roles,
               (SELECT count(*) FROM pg_catalog.pg_class c
                 JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
                 WHERE n.nspname='public' AND c.relkind='r') AS public_tables
        SQL);
    if ($identity?->db !== 'neondb' || $identity?->username !== 'neondb_owner'
        || (int) ($identity?->version_num ?? 0) < 180000
        || (int) ($identity?->neon_roles ?? 0) !== 1) {
        throw new RuntimeException('Identidad o PostgreSQL 18 inesperados, no continuar.');
    }
    echo '[PASS] Neon remoto: PG'.intdiv((int)$identity->version_num,10000)
        .', DB neondb, neondb_owner y rol neon_superuser presentes.'.PHP_EOL;
    echo '[INFO] Tablas public existentes: '.(int)$identity->public_tables.PHP_EOL;
    if ($requireEmpty && (int)$identity->public_tables !== 0) {
        throw new RuntimeException('Esquema public no esta vacio; prohibido el bootstrap inicial sobre datos existentes.');
    }
    echo '[INFO] app_backend restringido existe: '.((int)$identity->restricted_roles === 1 ? 'SI' : 'NO; requiere bootstrap posterior').PHP_EOL;
    echo '[BLOQUEO] Preflight completado; no se ejecuto ningun DML/DDL ni migrate.'.PHP_EOL;
} catch (\Throwable $e) {
    // No imprimir excepciones de PDO por si incorporan credenciales o URLs.
    fwrite(STDERR, '[FAIL] Conexion de solo lectura / identidad Neon no confirmada (revisar DNS, TLS, usuario y password).'.PHP_EOL);
    exit(1);
}
