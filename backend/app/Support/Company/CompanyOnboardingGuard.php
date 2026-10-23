<?php

declare(strict_types=1);

namespace App\Support\Company;

use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Throwable;

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

        // Fail closed with a single SQL snapshot of the installed F3.1
        // schema and exact SUNAT snapshot row counts. Previously each
        // Schema::hasColumn/hasTable and COUNT needed a separate network RTT.
        try {
            $installed = DB::selectOne(<<<'SQL'
                SELECT
                    (SELECT COUNT(*)::integer FROM information_schema.columns
                     WHERE table_schema = 'public'
                       AND table_name = 'company_settings'
                       AND column_name IN (
                         'legal_form','initial_fiscal_year',
                         'income_reference_year','annual_income',
                         'initial_pcge_version','sunat_contributor_type_code'
                       )) AS required_columns,
                    (to_regclass('public.company_economic_activities') IS NOT NULL
                     AND to_regclass('public.company_tax_codes') IS NOT NULL
                    ) AS tenant_tables,
                    (SELECT COUNT(*)::integer FROM public.sunat_ruc_contributor_types)
                        AS contributor_types,
                    (SELECT COUNT(*)::integer FROM public.sunat_ciiu_rev4_classes)
                        AS ciiu_classes,
                    (SELECT COUNT(*)::integer FROM public.sunat_ruc_tax_codes)
                        AS tax_codes
                SQL);
            if ($installed === null
                || (int) $installed->required_columns !== 6
                || ! in_array($installed->tenant_tables, [true, 1, '1', 't'], true)
                || (int) $installed->contributor_types !== 51
                || (int) $installed->ciiu_classes !== 417
                || (int) $installed->tax_codes !== 38) {
                return [
                    'can_create' => false,
                    'reason' => 'El perfil fiscal o los tres catálogos SUNAT RUC no están completos. Verifique las migraciones 23 y 24.',
                ];
            }
        } catch (Throwable) {
            // Missing tables, wrong privileges or inaccessible catalogs.
            // Never treat a failed query as evidence that the schema is ready.
            return [
                'can_create' => false,
                'reason' => 'No se pudo verificar el perfil fiscal y los catálogos SUNAT de la empresa.',
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
