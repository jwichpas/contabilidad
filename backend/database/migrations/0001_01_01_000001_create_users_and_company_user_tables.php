<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crea las tablas `users`, `personal_access_tokens` (Sanctum) y la tabla pivote `company_user`.
 * Aplica integridad referencial compuesta en `users.current_company_id` contra `company_user`
 * y configura Row Level Security (RLS) en `company_user`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->string('name', 150);
            $table->string('email', 255)->unique();
            $table->string('password');
            // Nullable al registrarse: un usuario puede crearse antes de asignarle o activar una empresa.
            $table->unsignedBigInteger('current_company_id')->nullable();
            $table->timestampsTz();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('company_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 30);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            // Garantiza unicidad del par (company_id, user_id) y habilita la FK compuesta desde `users`.
            $table->unique(['company_id', 'user_id'], 'uq_company_user_company_user');
            // Índice compuesto requerido para FKs inter-tenant si otras tablas referencian (company_id, id).
            $table->unique(['company_id', 'id'], 'uq_company_user_company_id');
            $table->index(['user_id', 'is_active'], 'idx_company_user_user_active');
        });

        DB::unprepared(<<<'SQL'
        -- Validación de roles mediante CHECK en lugar de CREATE TYPE ... AS ENUM para permitir
        -- evolucionar los roles mediante migraciones sin reconstruir tipos en el catálogo de PostgreSQL.
        ALTER TABLE public.company_user
            ADD CONSTRAINT chk_company_user_role
            CHECK (role IN ('ADMIN', 'CHIEF_ACCOUNTANT', 'ACCOUNTANT', 'DATA_ENTRY', 'AUDITOR'));

        -- Integridad referencial en PostgreSQL 15+:
        -- Obliga a que `users.current_company_id` (cuando no es NULL) apunte exclusivamente a una empresa
        -- a la que ese mismo `users.id` esté vinculado en `company_user`. Si se elimina la membresía,
        -- solo la columna `current_company_id` pasa a NULL sin borrar al usuario.
        ALTER TABLE public.users
            ADD CONSTRAINT fk_users_current_company_membership
            FOREIGN KEY (current_company_id, id)
            REFERENCES public.company_user (company_id, user_id)
            ON UPDATE CASCADE
            ON DELETE SET NULL (current_company_id);

        -- Activación y forzado de Row Level Security (RLS) en `company_user`
        ALTER TABLE public.company_user ENABLE ROW LEVEL SECURITY;
        ALTER TABLE public.company_user FORCE ROW LEVEL SECURITY;

        CREATE POLICY company_user_tenant_isolation_policy
            ON public.company_user
            AS PERMISSIVE
            FOR ALL
            TO app_backend
            USING (company_id = public.app_current_company_id())
            WITH CHECK (company_id = public.app_current_company_id());

        -- Privilegios mínimos para el rol `app_backend`
        GRANT SELECT, INSERT, UPDATE ON TABLE public.users TO app_backend;
        GRANT USAGE, SELECT ON SEQUENCE public.users_id_seq TO app_backend;

        GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.personal_access_tokens TO app_backend;
        GRANT USAGE, SELECT ON SEQUENCE public.personal_access_tokens_id_seq TO app_backend;

        GRANT SELECT, INSERT, UPDATE ON TABLE public.company_user TO app_backend;
        GRANT USAGE, SELECT ON SEQUENCE public.company_user_id_seq TO app_backend;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE IF EXISTS public.users DROP CONSTRAINT IF EXISTS fk_users_current_company_membership;');
        Schema::dropIfExists('company_user');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('users');
    }
};
