<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Retorna las empresas activas a las que pertenece el usuario autenticado desde la base de datos PostgreSQL.
 */
final readonly class ListUserCompaniesController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->load(['companies' => static function ($query): void {
            $query->where('companies.is_active', true)
                ->wherePivot('is_active', true);
        }]);

        $companies = $user->companies->map(static function (Company $company): array {
            return [
                'id' => $company->id,
                'uuid' => $company->uuid,
                'tax_id' => $company->tax_id,
                'business_name' => $company->business_name,
                'legal_address' => $company->legal_address,
                'fiscal_regime' => 'Régimen General · PRICO',
                'role' => $company->pivot->role?->value ?? (string) $company->pivot->role,
            ];
        })->values()->all();

        return response()->json([
            'data' => $companies,
        ]);
    }
}
