<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Configura las extensiones de PostgreSQL, el rol de aplicación `app_backend` (sin BYPASSRLS)
 * y la función de contexto RLS fail-closed `public.app_current_company_id()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS "pgcrypto";');

        $password = (string) config('database.app_backend_password', env('DB_APP_BACKEND_ROLE_PASSWORD', 'app_backend_local_secret'));
        $escapedPassword = str_replace("'", "''", $password);

        // Crea el rol `app_backend` sin privilegios de superusuario y explícitamente con NOBYPASSRLS.
        DB::unprepared(<<<SQL
        DO $$
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
                CREATE ROLE app_backend WITH
                    LOGIN
                    NOSUPERUSER
                    NOCREATEDB
                    NOCREATEROLE
                    NOINHERIT
                    NOREPLICATION
                    NOBYPASSRLS
                    PASSWORD '{$escapedPassword}';
            ELSE
                ALTER ROLE app_backend WITH
                    LOGIN
                    NOSUPERUSER
                    NOCREATEDB
                    NOCREATEROLE
                    NOINHERIT
                    NOREPLICATION
                    NOBYPASSRLS;
            END IF;
        END
        $$;
        SQL);

        DB::statement('GRANT USAGE ON SCHEMA public TO app_backend;');

        /*
         * Función helper para políticas RLS con comportamiento fail-closed:
         * - Lee `app.current_company_id` con `missing_ok = true`.
         * - Si no fue fijada en la transacción actual o es cadena vacía, retorna NULL.
         * - Si contiene un valor no numérico, captura la excepción y retorna NULL.
         * En SQL, `company_id = NULL` evalúa a UNKNOWN (falso en WHERE y CHECK), bloqueando toda fila.
         */
        DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION public.app_current_company_id()
        RETURNS bigint
        LANGUAGE plpgsql
        STABLE
        AS $$
        DECLARE
            v_raw text;
        BEGIN
            v_raw := nullif(current_setting('app.current_company_id', true), '');
            IF v_raw IS NULL THEN
                RETURN NULL;
            END IF;

            RETURN v_raw::bigint;
        EXCEPTION
            WHEN OTHERS THEN
                RETURN NULL;
        END;
        $$;

        GRANT EXECUTE ON FUNCTION public.app_current_company_id() TO app_backend;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS public.app_current_company_id();');
    }
};
