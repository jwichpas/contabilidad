<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Middleware que resuelve la empresa activa para el usuario autenticado, valida su membresía
 * vigente, fija `TenantContext` y ejecuta `set_config('app.current_company_id', ?, true)`
 * dentro de una transacción por petición compatible con Supavisor en modo transacción.
 */
final readonly class SetTenantContext
{
    public function __construct(
        private TenantContext $tenantContext,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            throw new AccessDeniedHttpException('Se requiere autenticación para establecer el contexto de empresa.');
        }

        $resolvedCompanyId = $this->resolveTargetCompanyId($request, $user);

        $this->tenantContext->setCompanyId($resolvedCompanyId);

        try {
            DB::statement(
                "SELECT set_config('app.current_company_id', ?, false)",
                [(string) $resolvedCompanyId]
            );

            if (! $this->userHasActiveMembership($user->id, $resolvedCompanyId)) {
                throw new AccessDeniedHttpException('No tiene acceso activo a la empresa solicitada o la empresa se encuentra inactiva.');
            }

            return $next($request);
        } finally {
            $this->tenantContext->clear();
        }
    }

    private function resolveTargetCompanyId(Request $request, User $user): int
    {
        $headerValue = $request->header('X-Company-Id');

        if ($headerValue !== null && $headerValue !== '') {
            if (! ctype_digit((string) $headerValue) || (int) $headerValue <= 0) {
                throw new BadRequestHttpException('El encabezado X-Company-Id debe ser un identificador numérico positivo.');
            }

            return (int) $headerValue;
        }

        if ($user->current_company_id !== null) {
            return (int) $user->current_company_id;
        }

        throw new BadRequestHttpException(
            'Debe especificar una empresa activa mediante el encabezado X-Company-Id o seleccionar una empresa predeterminada.'
        );
    }

    /**
     * Verifica la membresía activa del usuario en la empresa dentro de la transacción activa.
     * Almacena en caché local por 60 segundos para evitar un viaje de red innecesario en cada petición.
     */
    private function userHasActiveMembership(int $userId, int $companyId): bool
    {
        $cacheKey = "tenant_active_membership:{$userId}:{$companyId}";

        return (bool) Cache::remember($cacheKey, 60, function () use ($companyId, $userId): bool {
            $row = DB::selectOne(
                <<<'SQL'
                SELECT 1
                FROM public.company_user cu
                INNER JOIN public.companies c ON c.id = cu.company_id
                WHERE cu.company_id = ?::bigint
                  AND cu.user_id = ?::bigint
                  AND cu.is_active = true
                  AND c.is_active = true
                LIMIT 1
                SQL,
                [$companyId, $userId]
            );

            return $row !== null;
        });
    }
}
