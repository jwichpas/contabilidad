<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Models\AccountingAccount;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Retorna el catálogo plano de cuentas operativas (nivel 4+) activas
 * de la empresa activa para digitación rápida en asientos contables.
 * Utiliza caché local indexado por company_id para acelerar respuestas subsiguientes.
 */
final readonly class ListOperationalAccountsController
{
    public function __construct(
        private TenantContext $tenantContext,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $companyId = $this->tenantContext->getCompanyId();
        $cacheKey = "accounting_operational_accounts_{$companyId}";

        $accounts = Cache::remember($cacheKey, 3600, function () {
            return AccountingAccount::query()
                ->where('is_operational', true)
                ->where('is_active', true)
                ->select([
                    'id',
                    'uuid',
                    'code',
                    'name',
                    'level',
                    'account_type',
                    'balance_type',
                    'currency_mode',
                    'requires_cost_center',
                    'requires_auxiliary',
                    'requires_cash_flow_area',
                ])
                ->orderBy('code')
                ->get();
        });

        return response()->json([
            'data' => $accounts,
        ]);
    }
}
