<?php

declare(strict_types=1);

namespace App\DTOs\Accounting;

use App\Enums\AccountCurrencyMode;
use App\Enums\AccountType;
use App\Enums\BalanceType;
use App\Enums\CashFlowCategory;
use App\Enums\EntityRequirementType;
use App\Enums\FinancialStatementRubric;
use App\Support\Types\AccountId;
use App\Support\Types\AccountLevelShort;

/**
 * DTO inmutable con fuerte tipado numérico al estilo C# (`AccountId`, `AccountLevelShort`)
 * para la creación de una cuenta en el Plan Contable.
 */
final readonly class CreateAccountingAccountData
{
    public function __construct(
        public ?AccountId $parentId,
        public string $code,
        public string $name,
        public AccountLevelShort $level,
        public AccountType $accountType,
        public BalanceType $balanceType,
        public AccountCurrencyMode $currencyMode,
        public bool $requiresCurrency,
        public bool $requiresAuxiliary,
        public EntityRequirementType $entityRequirementType,
        public bool $requiresCostCenter,
        public bool $hasAutomaticDestination,
        public ?string $destinationDebitCode,
        public ?string $destinationCreditCode,
        public bool $requiresCashFlowArea,
        public CashFlowCategory $cashFlowCategory,
        public FinancialStatementRubric $financialStatementRubric,
        public bool $isOperational,
        public bool $isActive = true,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidatedArray(array $validated): self
    {
        $currencyMode = isset($validated['currency_mode'])
            ? AccountCurrencyMode::from((string) $validated['currency_mode'])
            : (((bool) ($validated['requires_currency'] ?? false)) ? AccountCurrencyMode::Usd : AccountCurrencyMode::Pen);

        $entityRequirement = isset($validated['entity_requirement_type'])
            ? EntityRequirementType::from((string) $validated['entity_requirement_type'])
            : (((bool) ($validated['requires_auxiliary'] ?? false)) ? EntityRequirementType::AnyThirdParty : EntityRequirementType::None);

        $hasAutoDest = (bool) ($validated['has_automatic_destination'] ?? false);

        return new self(
            parentId: AccountId::tryParse($validated['parent_id'] ?? null),
            code: strtoupper(trim((string) $validated['code'])),
            name: trim((string) $validated['name']),
            level: AccountLevelShort::parse((int) $validated['level']),
            accountType: AccountType::from((string) $validated['account_type']),
            balanceType: BalanceType::from((string) $validated['balance_type']),
            currencyMode: $currencyMode,
            requiresCurrency: (bool) ($validated['requires_currency'] ?? $currencyMode->requiresForeignExchange()),
            requiresAuxiliary: (bool) ($validated['requires_auxiliary'] ?? $entityRequirement->requiresEntity()),
            entityRequirementType: $entityRequirement,
            requiresCostCenter: (bool) ($validated['requires_cost_center'] ?? false),
            hasAutomaticDestination: $hasAutoDest,
            destinationDebitCode: $hasAutoDest && isset($validated['destination_debit_code'])
                ? strtoupper(trim((string) $validated['destination_debit_code']))
                : null,
            destinationCreditCode: $hasAutoDest && isset($validated['destination_credit_code'])
                ? strtoupper(trim((string) $validated['destination_credit_code']))
                : null,
            requiresCashFlowArea: (bool) ($validated['requires_cash_flow_area'] ?? false),
            cashFlowCategory: isset($validated['cash_flow_category'])
                ? CashFlowCategory::from((string) $validated['cash_flow_category'])
                : CashFlowCategory::None,
            financialStatementRubric: isset($validated['financial_statement_rubric'])
                ? FinancialStatementRubric::from((string) $validated['financial_statement_rubric'])
                : FinancialStatementRubric::None,
            isOperational: (bool) ($validated['is_operational'] ?? false),
            isActive: array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : true,
        );
    }
}
