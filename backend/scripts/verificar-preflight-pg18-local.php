<?php

declare(strict_types=1);

// This CLI preflight is invoked solely by the controlled Docker installer.
// It never prints database connection credentials, host URLs, SQL or token.
if (PHP_SAPI !== 'cli') {
    exit(73);
}

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';

try {
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $admin = (array) config('database.connections.pgsql_migrations', []);
    $traffic = (array) config('database.connections.pgsql', []);

    $expected = static function (array $config): bool {
        return (string) ($config['host'] ?? '') === '127.0.0.1'
            && (string) ($config['port'] ?? '') === '55433'
            && (string) ($config['database'] ?? '') === 'contabilidad_local'
            && (string) ($config['username'] ?? '') === 'postgres'
            && (string) ($config['sslmode'] ?? '') === 'disable'
            && trim((string) ($config['url'] ?? '')) === '';
    };

    if (! app()->environment('testing')
        || config('database.default') !== 'pgsql'
        || ! $expected($admin)
        || ! $expected($traffic)
        || ! \App\Support\Database\LocalPg18InstallGuard::approved()) {
        fwrite(STDERR, "[BLOQUEO] Identidad Laravel local PG18 no validada.\n");

        exit(73);
    }

    fwrite(STDOUT, "[PASS] Laravel solo puede usar PostgreSQL 18 local autorizado\n");
} catch (\Throwable) {
    // Exception messages can disclose driver credentials or SQL bindings.
    fwrite(STDERR, "[BLOQUEO] Preflight Laravel no completado.\n");

    exit(73);
}
