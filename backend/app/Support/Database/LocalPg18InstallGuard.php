<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Authorize only a guarded, one-time migration session against the explicitly
 * named local Docker PostgreSQL 18 development database.
 *
 * This guard is additional to, never a replacement for, the migrations'
 * existing Neon/Supabase and disposable CI allowlists. No HTTP path invokes it.
 */
final class LocalPg18InstallGuard
{
    public static function approved(): bool
    {
        if (! app()->environment('testing')) {
            return false;
        }

        $config = (array) config('database.connections.pgsql_migrations', []);
        $traffic = (array) config('database.connections.pgsql', []);
        $mark = env('LOCAL_PG18_INSTALL_TOKEN');

        if (! is_string($mark) || preg_match('/^[a-f0-9]{32}$/D', $mark) !== 1
            || ! in_array((string) config('database.default'), ['pgsql', 'pgsql_migrations'], true)
            || (string) ($config['host'] ?? '') !== '127.0.0.1'
            || (string) ($traffic['host'] ?? '') !== '127.0.0.1'
            || (string) ($config['port'] ?? '') !== '55433'
            || (string) ($traffic['port'] ?? '') !== '55433'
            || (string) ($config['database'] ?? '') !== 'contabilidad_local'
            || (string) ($traffic['database'] ?? '') !== 'contabilidad_local'
            || (string) ($config['username'] ?? '') !== 'postgres'
            || (string) ($traffic['username'] ?? '') !== 'postgres'
            || (string) ($config['sslmode'] ?? '') !== 'disable'
            || (string) ($traffic['sslmode'] ?? '') !== 'disable'
            || trim((string) ($config['url'] ?? '')) !== ''
            || trim((string) ($traffic['url'] ?? '')) !== '') {
            return false;
        }

        try {
            $db = DB::connection('pgsql_migrations');
            $row = $db->selectOne(<<<'SQL'
                SELECT current_database() AS db,
                    current_user AS db_role,
                    current_setting('server_version_num')::integer AS version_num,
                    (SELECT token FROM public.local_pg18_install_guard LIMIT 1) AS install_token
                SQL);

            return $row !== null
                && $row->db === 'contabilidad_local'
                && $row->db_role === 'postgres'
                && intdiv((int) ($row->version_num ?? 0), 10000) === 18
                && is_string($row->install_token ?? null)
                && hash_equals($mark, $row->install_token);
        } catch (Throwable) {
            return false;
        }
    }
}
