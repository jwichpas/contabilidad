<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Models\AccountingAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Retorna el catálogo plano de cuentas operativas (seis dígitos) activas
 * de la empresa activa para digitación rápida en asientos contables.
 * Consulta requisitos vigentes para no reutilizar reglas anteriores de digitación.
 */
final readonly class ListOperationalAccountsController
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! Schema::hasColumn('accounting_accounts', 'requires_company_area')) {
            return response()->json(['message' => 'Falta aplicar la migración aditiva de requisitos organizativos por cuenta.'], 503);
        }
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
                'has_automatic_destination',
                'requires_company_area',
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
