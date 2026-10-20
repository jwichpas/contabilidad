<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 1 / R01: cerrar el acceso accidental de PostgREST a las tablas Laravel.
 *
 * La API pública del sistema es Laravel Sanctum; el frontend consulta /api/v1/*,
 * NO supabase-js/PostgREST. Los roles anon/authenticated de Supabase no necesitan
 * leer, modificar, truncar ni gestionar secuencias del esquema contable.
 *
 * El rol app_backend conserva exactamente sus GRANT explícitos y sus políticas RLS.
 * Ejecución EXCLUSIVA mediante php artisan migrate --database=pgsql_migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public
                FROM anon, authenticated;
            REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public
                FROM anon, authenticated;

            -- Laravel usa la conexión administrativa postgres para crear tablas.
            -- Evitar que sus migraciones futuras vuelvan a otorgar permisos Data API.
            ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public
                REVOKE ALL PRIVILEGES ON TABLES FROM anon, authenticated;
            ALTER DEFAULT PRIVILEGES FOR ROLE postgres IN SCHEMA public
                REVOKE ALL PRIVILEGES ON SEQUENCES FROM anon, authenticated;
            SQL);
    }

    public function down(): void
    {
        // No volver a exponer tablas con tokens y datos empresariales al revertir.
        // Restaurar una ACL histórica requiere inventario y aprobación explícitos.
    }
};
