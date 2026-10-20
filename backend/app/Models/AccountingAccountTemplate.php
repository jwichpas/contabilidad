<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountCurrencyMode;
use App\Enums\AccountType;
use App\Enums\BalanceType;
use App\Enums\CashFlowCategory;
use App\Enums\EntityRequirementType;
use App\Enums\FinancialStatementRubric;
use Illuminate\Database\Eloquent\Model;

/**
 * Representa una cuenta del Plan Contable Matriz estándar oficial (PCGE 2019 o PCGE 2026).
 * No pertenece a ningún tenant en particular; sirve como plantilla inmutable del sistema.
 *
 * @property int $id
 * @property string $plan_version
 * @property string $code
 * @property string $name
 * @property int $level
 * @property string|null $parent_code
 * @property string $path
 * @property AccountType $account_type
 * @property BalanceType $balance_type
 * @property AccountCurrencyMode $currency_mode
 * @property EntityRequirementType $entity_requirement_type
 * @property bool $requires_cost_center
 * @property bool $has_automatic_destination
 * @property string|null $destination_debit_code
 * @property string|null $destination_credit_code
 * @property bool $requires_cash_flow_area
 * @property CashFlowCategory $cash_flow_category
 * @property FinancialStatementRubric $financial_statement_rubric
 * @property bool $is_operational
 * @property bool $is_active
 */
final class AccountingAccountTemplate extends Model
{
    protected $table = 'accounting_account_templates';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'account_type' => AccountType::class,
            'balance_type' => BalanceType::class,
            'currency_mode' => AccountCurrencyMode::class,
            'entity_requirement_type' => EntityRequirementType::class,
            'requires_cost_center' => 'boolean',
            'has_automatic_destination' => 'boolean',
            'requires_cash_flow_area' => 'boolean',
            'cash_flow_category' => CashFlowCategory::class,
            'financial_statement_rubric' => FinancialStatementRubric::class,
            'is_operational' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
