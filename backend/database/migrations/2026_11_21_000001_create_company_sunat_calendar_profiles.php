<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_sunat_calendar_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('revision');
            $table->jsonb('settings');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['company_id', 'year', 'revision'], 'uq_company_sunat_calendar_revision');
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE public.company_sunat_calendar_profiles ADD CHECK (year BETWEEN 2000 AND 2100), ADD CHECK (revision > 0);
            ALTER TABLE public.company_sunat_calendar_profiles ENABLE ROW LEVEL SECURITY;
            ALTER TABLE public.company_sunat_calendar_profiles FORCE ROW LEVEL SECURITY;
            CREATE POLICY sunat_calendar_company ON public.company_sunat_calendar_profiles
                FOR ALL TO app_backend USING (company_id = public.app_current_company_id())
                WITH CHECK (company_id = public.app_current_company_id());
            REVOKE ALL ON TABLE public.company_sunat_calendar_profiles FROM app_backend;
            GRANT SELECT, INSERT ON TABLE public.company_sunat_calendar_profiles TO app_backend;
            GRANT USAGE, SELECT ON SEQUENCE public.company_sunat_calendar_profiles_id_seq TO app_backend;
            SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('El historial del calendario requiere migración compensatoria auditada.');
    }
};
