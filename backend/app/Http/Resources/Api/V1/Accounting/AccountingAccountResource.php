<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Accounting;

use App\Enums\AccountCurrencyMode;
use App\Enums\CashFlowCategory;
use App\Enums\EntityRequirementType;
use App\Enums\FinancialStatementRubric;
use App\Models\AccountingAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API Resource estricto para representar cuentas contables con sus configuraciones de:
 * - Moneda (`currency_mode`)
 * - Entidad (`entity_requirement_type`)
 * - Centro de Costos y Destino Automático a 94/95 vs 79
 * - Área de Flujo de Efectivo (`cash_flow_category` para Cuenta 10)
 * - Rubro de Estados Financieros (`financial_statement_rubric`, ej. 1041 = Efectivo y Equivalentes de Efectivo)
 *
 * @mixin AccountingAccount
 */
final class AccountingAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currencyMode = $this->currency_mode ?? AccountCurrencyMode::Pen;
        $entityRequirement = $this->entity_requirement_type ?? EntityRequirementType::None;
        $cashFlowCategory = $this->cash_flow_category ?? CashFlowCategory::None;
        $fsRubric = $this->financial_statement_rubric ?? FinancialStatementRubric::None;

        return [
            'id' => $this->typedId()->toInt(),
            'uuid' => (string) $this->uuid,
            'company_id' => $this->typedCompanyId()->toInt(),
            'parent_id' => $this->typedParentId()?->toInt(),
            'code' => (string) $this->code,
            'name' => (string) $this->name,
            'level' => $this->typedLevel()->toInt(),
            'path' => (string) $this->path,
            'account_type' => $this->account_type->value,
            'account_type_label' => $this->account_type->label(),
            'balance_type' => $this->balance_type->value,
            'balance_type_label' => $this->balance_type->label(),
            'currency_mode' => $currencyMode->value,
            'currency_mode_label' => $currencyMode->label(),
            'requires_currency' => (bool) $this->requires_currency,
            'requires_auxiliary' => (bool) $this->requires_auxiliary,
            'entity_requirement_type' => $entityRequirement->value,
            'entity_requirement_label' => $entityRequirement->label(),
            'requires_cost_center' => (bool) $this->requires_cost_center,
            'requires_company_area' => (bool) $this->requires_company_area,
            'has_automatic_destination' => (bool) $this->has_automatic_destination,
            'destination_debit_code' => $this->destination_debit_code,
            'destination_credit_code' => $this->destination_credit_code,
            'requires_cash_flow_area' => (bool) $this->requires_cash_flow_area,
            'cash_flow_category' => $cashFlowCategory->value,
            'cash_flow_category_label' => $cashFlowCategory->label(),
            'financial_statement_rubric' => $fsRubric->value,
            'financial_statement_rubric_label' => $fsRubric->label(),
            'is_operational' => (bool) $this->is_operational,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
            'children' => self::collection(
                $this->whenLoaded('childrenRecursive', fn () => $this->childrenRecursive, [])
            ),
        ];
    }
}
