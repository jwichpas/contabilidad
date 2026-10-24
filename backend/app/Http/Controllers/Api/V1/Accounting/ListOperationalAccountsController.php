<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Models\AccountingAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Retorna el catálogo plano de cuentas operativas (seis dígitos) activas
 * de la empresa activa para digitación rápida en asientos contables.
 * Consulta requisitos vigentes para no reutilizar reglas anteriores de digitación.
 */
final readonly class ListOperationalAccountsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $accounts = AccountingAccount::query()
            ->whereRaw('accounting_accounts.is_operational IS TRUE')
            ->whereRaw('accounting_accounts.is_active IS TRUE')
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
                'entity_requirement_type',
                'requires_cash_flow_area',
                'cash_flow_category',
            ])
            ->orderBy('code')
            ->get();

        return response()->json([
            'data' => $accounts,
        ]);
    }
}
