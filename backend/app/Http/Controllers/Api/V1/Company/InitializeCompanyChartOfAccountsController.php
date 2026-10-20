<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Services\Accounting\InitializeCompanyChartOfAccountsService;
use App\Services\Security\AuditLoggerService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inicialización o reinicialización del Plan Contable de la empresa a partir del catálogo matriz.
 */
final readonly class InitializeCompanyChartOfAccountsController
{
    public function __construct(
        private InitializeCompanyChartOfAccountsService $service,
        private AuditLoggerService $auditLogger,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_version' => ['required', 'string', 'in:PCGE_2019,PCGE_2026'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $companyId = (int) $request->user()->current_company_id;
        $planVersion = (string) $validated['plan_version'];
        $force = (bool) ($validated['force'] ?? false);

        try {
            $result = $this->service->initialize($companyId, $planVersion, $force);

            $this->auditLogger->log(
                eventType: 'PLAN_INITIALIZED',
                companyId: $companyId,
                userId: $request->user()?->id,
                auditableType: 'accounting_accounts',
                auditableId: (string) $companyId,
                oldValues: null,
                newValues: $result,
                request: $request,
                severity: 'INFO',
            );

            return response()->json([
                'message' => "Plan contable ({$planVersion}) inicializado exitosamente para la empresa.",
                'data' => $result,
            ], Response::HTTP_CREATED);
        } catch (DomainException $e) {
            $this->auditLogger->log(
                eventType: 'PLAN_INITIALIZATION_REJECTED',
                companyId: $companyId,
                userId: $request->user()?->id,
                auditableType: 'accounting_accounts',
                auditableId: (string) $companyId,
                oldValues: null,
                newValues: ['error' => $e->getMessage(), 'version' => $planVersion],
                request: $request,
                severity: 'WARNING',
            );

            return response()->json([
                'message' => $e->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
    }
}
