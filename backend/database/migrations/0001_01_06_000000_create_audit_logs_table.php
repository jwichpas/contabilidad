<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de Auditoría Inmutable y Trazabilidad de Seguridad (ISO/IEC 27001:2023 Control 8.15).
 *
 * Registra eventos críticos de seguridad, autenticación y transacciones contables
 * con inmutabilidad estricta a nivel de motor (sin permisos de UPDATE o DELETE para app_backend).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 60);
            $table->string('auditable_type', 120)->nullable();
            $table->string('auditable_id', 50)->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('severity', 20)->default('INFO'); // INFO, WARNING, CRITICAL
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['company_id', 'created_at'], 'idx_audit_logs_company_date');
            $table->index(['user_id', 'created_at'], 'idx_audit_logs_user_date');
            $table->index(['event_type', 'created_at'], 'idx_audit_logs_event_date');
            $table->index(['auditable_type', 'auditable_id'], 'idx_audit_logs_target');
        });

        DB::unprepared(<<<'SQL'
        -- 1. Restricción CHECK de severidad
        ALTER TABLE public.audit_logs
            ADD CONSTRAINT chk_audit_logs_severity
            CHECK (severity IN ('INFO', 'WARNING', 'CRITICAL'));

        -- 2. Habilitar y forzar Row Level Security (RLS)
        ALTER TABLE public.audit_logs ENABLE ROW LEVEL SECURITY;
        ALTER TABLE public.audit_logs FORCE ROW LEVEL SECURITY;

        -- 3. Políticas RLS:
        -- Inserción permitida para app_backend
        CREATE POLICY audit_logs_insert_policy
            ON public.audit_logs
            AS PERMISSIVE
            FOR INSERT
            TO app_backend
            WITH CHECK (true);

        -- Lectura permitida sólo para el tenant activo o registros globales del usuario
        CREATE POLICY audit_logs_select_policy
            ON public.audit_logs
            AS PERMISSIVE
            FOR SELECT
            TO app_backend
            USING (
                company_id IS NULL
                OR company_id = public.app_current_company_id()
            );

        -- 4. Otorgar exclusivamente INSERT y SELECT al rol app_backend (inmutabilidad estricta ISO 27001)
        GRANT SELECT, INSERT ON public.audit_logs TO app_backend;
        GRANT USAGE, SELECT ON SEQUENCE public.audit_logs_id_seq TO app_backend;
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
