<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        /*
         * Conexión principal de tráfico de la aplicación.
         * Conecta mediante el pooler Supavisor (puerto 6543 en modo transacción o 5432 en modo sesión)
         * autenticándose exclusivamente con el rol `app_backend` (sujeto a FORCE ROW LEVEL SECURITY).
         *
         * Implicancia técnica de PDO::ATTR_EMULATE_PREPARES => true (modo transacción):
         * Supavisor/PgBouncer en modo transacción asigna una conexión de backend de PostgreSQL distinta
         * a cada transacción; los prepared statements de protocolo (PREPARE/EXECUTE) fallan con error
         * "prepared statement does not exist". Con emulación activa, PDO reemplaza los placeholders en
         * el cliente antes de enviar el SQL. Por ello todos los parámetros en modelos, DTOs y scopes
         * deben estar estrictamente tipados en PHP (int, bool, string) y casteados en SQL (::bigint).
         */
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL', env('DATABASE_URL')),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '6543'),
            'database' => env('DB_DATABASE', 'postgres'),
            'username' => env('DB_USERNAME', 'app_backend'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'require'),
            'options' => extension_loaded('pdo_pgsql') ? [
                PDO::ATTR_EMULATE_PREPARES => filter_var(
                    env('DB_EMULATE_PREPARES', true),
                    FILTER_VALIDATE_BOOLEAN
                ),
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ] : [],
        ],

        /*
         * Conexión administrativa para migraciones (`php artisan migrate --database=pgsql_migrations`).
         * Utiliza el puerto 5432 (conexión directa a Supabase o pooler en modo sesión) con el rol
         * propietario del esquema para ejecutar DDL, creación de triggers, políticas RLS y GRANTs.
         */
        'pgsql_migrations' => [
            'driver' => 'pgsql',
            'url' => env('DB_MIGRATIONS_URL'), // No heredar DATABASE_URL de la conexión de aplicación
            'host' => env('DB_MIGRATIONS_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_MIGRATIONS_PORT', '5432'),
            'database' => env('DB_MIGRATIONS_DATABASE', env('DB_DATABASE', 'postgres')),
            'username' => env('DB_MIGRATIONS_USERNAME', 'postgres'),
            'password' => env('DB_MIGRATIONS_PASSWORD', env('DB_PASSWORD', '')),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_MIGRATIONS_SSLMODE', 'require'),
            'options' => extension_loaded('pdo_pgsql') ? [
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ] : [],
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel'), '_').'_database_'),
        ],
        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
    ],

];
