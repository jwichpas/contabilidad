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

        // No habilitar el wizard con migraciones presentes pero SUNAT/PCGE vacíos.
        // El ledger de versiones pertenece al rol migrador, no al rol API.
        if (($issue = $this->globalReferences->issue()) !== null) {
            return ['can_create' => false, 'reason' => $issue];
        }

        return ['can_create' => true, 'reason' => null];
    }
}
