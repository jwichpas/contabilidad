<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F0/F1: rotación de contraseña del rol de aplicación existente.
 *
 * Requiere DB_APP_BACKEND_NEW_PASSWORD (secreto local, nunca en Git/CI).
 * Solo se aplica desde Artisan usando pgsql_migrations como postgres.
 * No altera tablas, RLS ni permisos.
 *
 * php artisan migrate --database=pgsql_migrations \
 *   --path=database/migrations/2026_10_20_000000_rotate_app_backend_credential.php --force
 */
return new class extends Migration
{
    public function up(): void
    {
        $password = env('DB_APP_BACKEND_NEW_PASSWORD');
        if (! is_string($password) || strlen($password) < 32 || strlen($password) > 256
            || in_array($password, ['app_backend_local_secret', 'password', 'postgres'], true)) {
            throw new \RuntimeException(
                'Defina DB_APP_BACKEND_NEW_PASSWORD con una clave nueva de 32 a 256 caracteres '
                .'en backend/.env antes de ejecutar esta migración. No se modificó el rol.'
            );
        }

        $db = DB::connection('pgsql_migrations');
        $configuration = $db->getConfig();
        $locator = implode(' ', [
            (string) ($configuration['host'] ?? ''),
            (string) ($configuration['url'] ?? ''),
            (string) ($configuration['username'] ?? ''),
        ]);
        if (! str_contains($locator, 'bnjwxphwtkhblvhsnylj')) {
            throw new \RuntimeException('La conexión no corresponde a contabilidad-database.');
        }

        $identity = $db->selectOne('SELECT current_user AS db_role, current_database() AS db_name');
        if ($identity === null || $identity->db_role !== 'postgres' || $identity->db_name !== 'postgres') {
            throw new \RuntimeException('Se requiere conexión pgsql_migrations como administrador postgres.');
        }

        $role = $db->selectOne(<<<'SQL'
            SELECT rolcanlogin, rolbypassrls, rolsuper
            FROM pg_catalog.pg_roles
            WHERE rolname = 'app_backend'
            SQL);

        if ($role === null || ! (bool) $role->rolcanlogin
            || (bool) $role->rolbypassrls || (bool) $role->rolsuper) {
            throw new \RuntimeException(
                'El rol app_backend no cumple las garantías LOGIN/NOBYPASSRLS/NOSUPERUSER.'
            );
        }

        // La clave nunca aparece en el repositorio. PDO::quote impide inyección SQL.
        // SCRAM se solicita para el verificador almacenado en PostgreSQL.
        $db->statement("SET LOCAL password_encryption = 'scram-sha-256'");
        $quotedPassword = $db->getPdo()->quote($password);
        if ($quotedPassword === false) {
            throw new \RuntimeException('No fue posible preparar la actualización segura del rol.');
        }

        try {
            $db->unprepared('ALTER ROLE app_backend WITH PASSWORD '.$quotedPassword);
        } catch (\Throwable) {
            // No encadenar la excepción: QueryException podría mostrar la clave en SQL.
            throw new \RuntimeException(
                'La rotación del rol falló; revise el usuario administrador y permisos.'
                .' No se muestran sentencias que contengan contraseñas.'
            );
        }
    }

    public function down(): void
    {
        // No reponer claves previas ni usar contraseñas predeterminadas.
        throw new \RuntimeException(
            'No existe rollback automático para credenciales. Realizar otra rotación auditada.'
        );
    }
};
