<?php

declare(strict_types=1);

namespace App\DTOs\Accounting;

use App\Enums\AccountCurrencyMode;
use App\Enums\AccountType;
use App\Enums\BalanceType;
use App\Enums\CashFlowCategory;
use App\Enums\EntityRequirementType;
use App\Enums\FinancialStatementRubric;

/**
 * DTO inmutable para la actualización de una cuenta contable existente.
 */
final readonly class UpdateAccountingAccountData
{
    public function __construct(
        public string $name,
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
        public bool $isActive,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidatedArray(array $validated): self
    {
        $currencyMode = isset($validated['currency_mode'])
            ? AccountCurrencyMode::from((string) $validated['currency_mode'])
            : (((bool) $validated['requires_currency']) ? AccountCurrencyMode::Usd : AccountCurrencyMode::Pen);

        $entityRequirement = isset($validated['entity_requirement_type'])
            ? EntityRequirementType::from((string) $validated['entity_requirement_type'])
            : (((bool) $validated['requires_auxiliary']) ? EntityRequirementType::AnyThirdParty : EntityRequirementType::None);

        $hasAutoDest = (bool) ($validated['has_automatic_destination'] ?? false);

        return new self(
            name: trim((string) $validated['name']),
            accountType: AccountType::from((string) $validated['account_type']),
            balanceType: BalanceType::from((string) $validated['balance_type']),
            currencyMode: $currencyMode,
            requiresCurrency: (bool) $validated['requires_currency'],
            requiresAuxiliary: (bool) $validated['requires_auxiliary'],
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
            isOperational: (bool) $validated['is_operational'],
            isActive: (bool) $validated['is_active'],
        );
    }
}
