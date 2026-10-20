<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Retorna las empresas activas a las que pertenece el usuario autenticado desde la base de datos PostgreSQL.
 */
final readonly class ListUserCompaniesController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // La lista de empresas es una consulta transversal a los tenants del usuario.
        // Usamos un contexto local a la transacción para que la política RLS permita
        // ÚNICAMENTE las membresías del usuario autenticado, sin bypass global.
        // Con Supavisor en puerto 6543 el contexto NO puede persistir entre requests.
        $companies = DB::transaction(function () use ($user): array {
            DB::selectOne(
                "SELECT set_config('app.current_user_id', ?, true)",
                [(string) $user->id]
            );

            // PostgreSQL boolean no admite comparar con 1: where(..., true)
            // se convierte en "= 1" con PDO emulate_prepares habilitado.
            $memberships = $user->companies()
                ->whereRaw('companies.is_active IS TRUE')
                ->whereRaw('company_user.is_active IS TRUE')
                ->get();

            return $memberships->map(static function (Company $company): array {
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
        });

        return response()->json([
            'data' => $companies,
        ]);
    }
}
