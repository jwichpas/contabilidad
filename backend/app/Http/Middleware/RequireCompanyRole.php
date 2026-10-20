<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\CompanyRole;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Autoriza operaciones de escritura según la membresía ACTIVA de la empresa
 * validada por SetTenantContext. Ejecutar dentro de esa misma transacción RLS.
 *
 * Uso: ->middleware(RequireCompanyRole::class.':ADMIN,CHIEF_ACCOUNTANT')
 * Ningún permiso se hereda de otra empresa o de una empresa seleccionada antes.
 */
final readonly class RequireCompanyRole
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next, string ...$allowedRoles): Response
    {
        $user = $request->user();
        $companyId = $this->tenantContext->getCompanyId();

        if (! $user instanceof User || $companyId === null) {
            throw new AccessDeniedHttpException('Se requiere una empresa activa y autenticada.');
        }

        // Error de configuración de rutas => denegar por defecto, nunca permitir.
        if ($allowedRoles === []) {
            throw new AccessDeniedHttpException('No hay roles autorizados para esta operación.');
        }
        foreach ($allowedRoles as $role) {
            if (CompanyRole::tryFrom($role) === null) {
                throw new AccessDeniedHttpException('Rol de autorización no válido.');
            }
        }

        // No confiar en claims del token ni en roles seleccionados en el frontend.
        // La consulta está dentro de SET LOCAL app.current_company_id (middleware exterior).
        $membership = DB::selectOne(<<<'SQL'
            SELECT cu.role
            FROM public.company_user cu
            JOIN public.companies c ON c.id = cu.company_id
            WHERE cu.user_id = ?::bigint
              AND cu.company_id = ?::bigint
              AND cu.is_active IS TRUE
              AND c.is_active IS TRUE
            LIMIT 1
            SQL,
            [(int) $user->id, $companyId]
        );

        if ($membership === null || ! in_array((string) $membership->role, $allowedRoles, true)) {
            throw new AccessDeniedHttpException('Su rol no permite realizar esta operación en la empresa.');
        }

        return $next($request);
    }
}
