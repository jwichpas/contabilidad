<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Models\Company;
use App\Models\User;
use App\Services\Security\AuditLoggerService;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Single Action Controller para cambiar la empresa predeterminada (`current_company_id`)
 * del usuario autenticado, validando membresía activa bajo RLS.
 */
final readonly class SwitchActiveCompanyController
{
    public function __construct(
        private TenantContext $tenantContext,
        private AuditLoggerService $auditLogger,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $started = hrtime(true);
        $membershipDurationMs = 0.0;
        $saveDurationMs = 0.0;

        /** @var array{company_id: int|string} $validated */
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'min:1'],
        ]);

        $targetCompanyId = (int) $validated['company_id'];

        /** @var User $user */
        $user = $request->user();
        $previousCompanyId = $user->current_company_id;

        $transactionStarted = hrtime(true);
        $companyData = $this->tenantContext->run($targetCompanyId, function () use ($user, $targetCompanyId, &$membershipDurationMs, &$saveDurationMs): object {
            $membershipStarted = hrtime(true);
            $membership = DB::selectOne(
                <<<'SQL'
                SELECT
                    c.id AS company_id,
                    c.uuid AS company_uuid,
                    c.tax_id,
                    c.business_name,
                    cu.role
                FROM public.company_user cu
                INNER JOIN public.companies c ON c.id = cu.company_id
                WHERE cu.company_id = ?::bigint
                  AND cu.user_id = ?::bigint
                  AND cu.is_active = true
                  AND c.is_active = true
                LIMIT 1
                SQL,
                [$targetCompanyId, $user->id]
            );
            $membershipDurationMs = (hrtime(true) - $membershipStarted) / 1000000;

            if ($membership === null) {
                throw new AccessDeniedHttpException('No pertenece a la empresa seleccionada o su acceso se encuentra inactivo.');
            }

            $saveStarted = hrtime(true);
            $user->forceFill([
                'current_company_id' => $targetCompanyId,
            ])->save();
            $saveDurationMs = (hrtime(true) - $saveStarted) / 1000000;

            return $membership;
        });
        $transactionDurationMs = (hrtime(true) - $transactionStarted) / 1000000;

        $auditStarted = hrtime(true);
        $this->auditLogger->log(
            eventType: 'TENANT_SWITCH',
            companyId: $targetCompanyId,
            userId: $user->id,
            auditableType: Company::class,
            auditableId: (string) $targetCompanyId,
            oldValues: ['previous_company_id' => $previousCompanyId],
            newValues: ['active_company_id' => $targetCompanyId],
            request: $request,
            severity: 'INFO',
        );
        $auditDurationMs = (hrtime(true) - $auditStarted) / 1000000;

        $timings = [
            'switch_membership;dur='.number_format($membershipDurationMs, 1, '.', ''),
            'switch_user_save;dur='.number_format($saveDurationMs, 1, '.', ''),
            'switch_transaction;dur='.number_format($transactionDurationMs, 1, '.', ''),
            'switch_audit;dur='.number_format($auditDurationMs, 1, '.', ''),
            'switch_controller_total;dur='.number_format((hrtime(true) - $started) / 1000000, 1, '.', ''),
        ];

        return response()->json([
            'data' => [
                'user_id' => $user->id,
                'current_company_id' => (int) $companyData->company_id,
                'company_uuid' => (string) $companyData->company_uuid,
                'tax_id' => (string) $companyData->tax_id,
                'business_name' => (string) $companyData->business_name,
                'role' => (string) $companyData->role,
            ],
        ])->header('Server-Timing', implode(', ', $timings));
    }
}
