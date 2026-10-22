<?php

declare(strict_types=1);

namespace App\Support\Company;

use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * No habilitar altas multiempresa sin el esquema Laravel esperado ni permiso ADMIN.
 * El endpoint no cambia de tenant ni obtiene privilegios administrativos DDL.
 */
final readonly class CompanyOnboardingGuard
{
    public function __construct(
        private TenantContext $tenantContext,
        private GlobalReferenceReadiness $globalReferences,
    ) {}

    /** @return array{can_create:bool,reason:?string} */
    public function status(User $user): array
    {
        $companyId = (int) ($user->current_company_id ?? 0);
        if ($companyId <= 0) {
            return ['can_create' => false, 'reason' => 'Seleccione una empresa activa antes de dar de alta otra.'];
        }

        $isAdmin = $this->tenantContext->run($companyId, static function () use ($companyId, $user): bool {
            return DB::table('company_user')
                ->join('companies', 'companies.id', '=', 'company_user.company_id')
                ->where('company_user.company_id', $companyId)
                ->where('company_user.user_id', $user->id)
                ->where('company_user.role', 'ADMIN')
                ->whereRaw('company_user.is_active IS TRUE')
                ->whereRaw('companies.is_active IS TRUE')
                ->exists();
        });

        if (! $isAdmin) {
            return ['can_create' => false, 'reason' => 'Solo un administrador activo puede crear empresas.'];
        }

        if (! CompanyEconomicProfile::isReady()
            || ! Schema::hasColumn('company_settings', 'initial_fiscal_year')
            || ! Schema::hasColumn('company_settings', 'income_reference_year')
            || ! Schema::hasColumn('company_settings', 'annual_income')
            || ! Schema::hasColumn('company_settings', 'initial_pcge_version')) {
            return [
                'can_create' => false,
                'reason' => 'La creación estará disponible después de aplicar las migraciones Laravel pendientes.',
            ];
        }

        // Alta guiada únicamente por los tres catálogos RUC oficiales versionados.
        // No asumir que las tablas PLE con números coincidentes son equivalentes.
        if (! Schema::hasColumn('company_settings', 'sunat_contributor_type_code')
            || ! Schema::hasTable('sunat_ruc_contributor_types')
            || ! Schema::hasTable('sunat_ciiu_rev4_classes')
            || ! Schema::hasTable('sunat_ruc_tax_codes')
            || ! Schema::hasTable('company_tax_codes')
            || (int) DB::table('sunat_ruc_contributor_types')->count() !== 51
            || (int) DB::table('sunat_ciiu_rev4_classes')->count() !== 417
            || (int) DB::table('sunat_ruc_tax_codes')->count() !== 38) {
            return [
                'can_create' => false,
                'reason' => 'Instale los 3 catálogos SUNAT de RUC, CIIU Rev. 4 y tributos (migración global 24).',
            ];
        }

        // No habilitar el wizard con migraciones presentes pero SUNAT/PCGE vacíos.
        // El ledger de versiones pertenece al rol migrador, no al rol API.
        if (($issue = $this->globalReferences->issue()) !== null) {
            return ['can_create' => false, 'reason' => $issue];
        }

        return ['can_create' => true, 'reason' => null];
    }
}
