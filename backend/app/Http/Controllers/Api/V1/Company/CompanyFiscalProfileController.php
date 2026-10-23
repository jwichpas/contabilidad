<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Models\AuditLog;
use App\Models\CompanyFiscalProfile;
use App\Support\Company\CompanyEconomicProfile;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * F3.3: fiscal history is append-only. No update/delete/retroactive overwrite.
 * The active company_settings record is deliberately left untouched.
 */
final readonly class CompanyFiscalProfileController
{
    public function __construct(private TenantContext $tenantContext) {}

    public function index(Request $request): JsonResponse
    {
        $this->tenantContext->requireCompanyId(CompanyFiscalProfile::class);
        $this->requireMigrated();

        $data = $request->validate([
            'year' => ['sometimes', 'integer', 'between:2000,2100'],
        ]);

        $profiles = CompanyFiscalProfile::query()
            ->when(isset($data['year']), fn ($query) => $query->where('fiscal_year', (int)$data['year']))
            ->orderByDesc('fiscal_year')
            ->orderByDesc('revision')
            ->limit(200)
            ->get();

        return response()->json(['data' => $profiles]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = $this->tenantContext->requireCompanyId(CompanyFiscalProfile::class);
        $this->requireMigrated();

        $data = $request->validate([
            'fiscal_year' => ['required', 'integer', 'between:2000,2100'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'tax_regime' => ['required', Rule::in(['GENERAL','MYPE_TRIBUTARIO','RER','ESPECIAL','NRUS'])],
            'legal_form' => ['required', Rule::in(CompanyEconomicProfile::LEGAL_FORMS)],
            'income_reference_year' => ['nullable', 'integer', 'between:2000,2100'],
            'annual_income' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'default_vat_rate' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'is_retention_agent' => ['required', 'boolean'],
            'is_perception_agent' => ['required', 'boolean'],
            'is_good_taxpayer' => ['required', 'boolean'],
            'is_sire_obliged' => ['required', 'boolean'],
            'is_ple_obliged' => ['required', 'boolean'],
            'change_reason' => ['required', 'string', 'min:10', 'max:500'],
            'company_id' => ['prohibited'],
            'revision' => ['prohibited'],
            'created_by' => ['prohibited'],
            'id' => ['prohibited'],
            'uuid' => ['prohibited'],
        ]);

        $year = (int)$data['fiscal_year'];
        $effectiveDate = (string)$data['effective_date'];
        if (substr($effectiveDate, 0, 4) !== (string)$year) {
            throw ValidationException::withMessages([
                'effective_date' => ['La vigencia debe corresponder al ejercicio fiscal seleccionado.'],
            ]);
        }
        if (isset($data['income_reference_year']) && (int)$data['income_reference_year'] > $year) {
            throw ValidationException::withMessages([
                'income_reference_year' => ['El año de ingresos no puede ser posterior al ejercicio.'],
            ]);
        }
        CompanyEconomicProfile::assertTaxRegimeEligibility($data['tax_regime'], $data['legal_form']);

        $created = DB::transaction(function () use ($request, $data, $companyId, $year, $effectiveDate): CompanyFiscalProfile {
            // Row lock serializes revisions even for an initially empty year.
            $settings = DB::table('company_settings')
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first();
            if ($settings === null) {
                throw ValidationException::withMessages(['company' => ['Complete la configuración de empresa antes del historial fiscal.']]);
            }

            $latest = CompanyFiscalProfile::query()
                ->where('fiscal_year', $year)
                ->orderByDesc('revision')
                ->first();

            if ($latest !== null && $effectiveDate <= $latest->effective_date->format('Y-m-d')) {
                throw ValidationException::withMessages([
                    'effective_date' => ['La nueva vigencia debe ser posterior a la última revisión del ejercicio.'],
                ]);
            }

            // A retroactive policy change must never rewrite a closed/locked period.
            $hasClosedPeriods = DB::table('fiscal_periods')
                ->where('company_id', $companyId)
                ->where('year', $year)
                ->whereIn('status', ['CLOSED','LOCKED'])
                ->where('end_date', '>=', $effectiveDate)
                ->exists();
            if ($hasClosedPeriods) {
                throw ValidationException::withMessages([
                    'effective_date' => ['No se admiten revisiones que afecten períodos cerrados o bloqueados.'],
                ]);
            }

            $previous = $latest?->only([
                'fiscal_year', 'revision', 'effective_date', 'tax_regime', 'legal_form',
                'income_reference_year', 'annual_income', 'default_vat_rate',
            ]);
            $profile = CompanyFiscalProfile::query()->create(array_merge($data, [
                'revision' => ($latest?->revision ?? 0) + 1,
                'created_by' => (int)$request->user()->id,
            ]));

            // This audit is NOT best-effort: any insertion failure rolls back
            // both the revision and audit in the outer request transaction.
            AuditLog::query()->create([
                'company_id' => $companyId,
                'user_id' => $request->user()?->id,
                'event_type' => 'COMPANY_FISCAL_PROFILE_REVISED',
                'auditable_type' => CompanyFiscalProfile::class,
                'auditable_id' => (string)$profile->id,
                'old_values' => $previous,
                'new_values' => $profile->only([
                    'fiscal_year', 'revision', 'effective_date', 'tax_regime', 'legal_form',
                    'income_reference_year', 'annual_income', 'default_vat_rate', 'change_reason',
                ]),
                'severity' => 'INFO',
                'ip_address' => substr((string)$request->ip(), 0, 45),
                'user_agent' => substr((string)$request->userAgent(), 0, 500),
            ]);

            return $profile;
        });

        return response()->json([
            'message' => 'Nueva revisión fiscal registrada y auditada. La configuración actual no fue sobrescrita.',
            'data' => $created,
        ], 201);
    }

    private function requireMigrated(): void
    {
        if (! Schema::hasTable('company_fiscal_profiles')) {
            abort(503, 'Historial fiscal no disponible: aplique primero las migraciones Laravel pendientes.');
        }
    }
}
