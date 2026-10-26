<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Middleware que resuelve la empresa activa para el usuario autenticado, valida su membresía
 * vigente y ejecuta el controlador dentro de TenantContext::run().
 * El contexto PostgreSQL es LOCAL a la transacción y se revierte al finalizar la petición;
 * nunca usa set_config(..., false), que persistiría en conexiones reutilizadas.
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

        $middlewareStarted = hrtime(true);
        $membershipDurationMs = null;
        $resolvedCompanyId = $this->resolveTargetCompanyId($request, $user);

        // La validación de membresía también requiere el RLS de company_user.
        // Ejecutar la consulta de membresía Y el controlador en una sola transacción
        // de contexto local evita fugas de tenant por el pool Supavisor.
        $response = $this->tenantContext->run(
            $resolvedCompanyId,
            function () use ($request, $next, $user, $resolvedCompanyId, &$membershipDurationMs): Response {
                $membershipStarted = hrtime(true);
                $hasMembership = $this->userHasActiveMembership($user->id, $resolvedCompanyId);
                $membershipDurationMs = number_format((hrtime(true) - $membershipStarted) / 1000000, 1, '.', '');
                if (! $hasMembership) {
                    throw new AccessDeniedHttpException(
                        'No tiene acceso activo a la empresa solicitada o la empresa se encuentra inactiva.'
                    );
                }

                return $next($request);
            }
        );

        // Includes tenant authorization, SET LOCAL, controller and transaction.
        // This overlaps with controller_total, so the two values must never be
        // added together. No identity, query values or credentials are exposed.
        $elapsed = number_format((hrtime(true) - $middlewareStarted) / 1000000, 1, '.', '');
        $existing = (string) $response->headers->get('Server-Timing', '');
        $timings = ($existing !== '' ? $existing.', ' : '').'tenant_total;dur='.$elapsed;
        // This must remain a real DB check for every request, not a cached role.
        // It exposes only elapsed milliseconds, never IDs or SQL parameters.
        if ($membershipDurationMs !== null && (
            $request->is('api/v1/accounting/journal-entries/editor-bootstrap')
            || ($request->is('api/v1/accounting/journal-entries') && $request->isMethod('POST'))
        )) {
            $timings .= ', tenant_membership;dur='.$membershipDurationMs;
        }
        $response->headers->set('Server-Timing', $timings);
        return $response;
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
     * Comprueba la membresía en cada petición para que una revocación de acceso
     * tenga efecto inmediato. Sin caché de autorización que pueda quedar obsoleta.
     * Esta consulta se ejecuta con el GUC tenant ya fijado LOCAL a la transacción.
     */
    private function userHasActiveMembership(int $userId, int $companyId): bool
    {
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
    }
}
