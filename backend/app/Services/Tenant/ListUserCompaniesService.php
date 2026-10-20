<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Lee las empresas activas vinculadas al usuario autenticado de Laravel.
 *
 * La consulta abarca multiples tenants, por lo que NO usa
 * app.current_company_id. Su alcance RLS proviene exclusivamente de
 * app.current_user_id, establecido con set_config(..., true) dentro de
 * una transaccion en la conexion operacional app_backend.
 *
 * No otorgar a clientes la capacidad de suministrar user_id manualmente.
 */
final readonly class ListUserCompaniesService
{
    /**
     * @return list<array{
     *   id: int, uuid: string, tax_id: string, business_name: string,
     *   legal_address: string, fiscal_regime: string, role: string
     * }>
     */
    public function forUser(User $user): array
    {
        return DB::transaction(static function () use ($user): array {
            DB::selectOne(
                "SELECT set_config('app.current_user_id', ?, true)",
                [(string) $user->id]
            );

            // PostgreSQL BOOLEAN no admite las comparaciones "= 1" que
            // pueden generar los parametros PDO con emulacion habilitada.
            $memberships = $user->companies()
                ->whereRaw('companies.is_active IS TRUE')
                ->whereRaw('company_user.is_active IS TRUE')
                ->get();

            return $memberships->map(static function (Company $company): array {
                return [
                    'id' => (int) $company->id,
                    'uuid' => (string) $company->uuid,
                    'tax_id' => (string) $company->tax_id,
                    'business_name' => (string) $company->business_name,
                    'legal_address' => (string) $company->legal_address,
                    'fiscal_regime' => 'Régimen General · PRICO',
                    'role' => $company->pivot->role?->value ?? (string) $company->pivot->role,
                ];
            })->values()->all();
        });
    }
}
