<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Requests\Api\V1\Accounting\UpdateAccountingAccountRequest;
use App\Http\Resources\Api\V1\Accounting\AccountingAccountResource;
use App\Models\AccountingAccount;
use App\Services\Security\AuditLoggerService;

/**
 * Single Action Controller para editar una cuenta contable existente del tenant activo.
 */
final readonly class UpdateAccountingAccountController
{
    public function __construct(
        private AuditLoggerService $auditLogger,
    ) {}

    public function __invoke(UpdateAccountingAccountRequest $request, int $accountId): AccountingAccountResource
    {
        $account = AccountingAccount::query()->findOrFail($accountId);
        $oldValues = [
            'name' => $account->name,
            'is_operational' => $account->is_operational,
            'is_active' => $account->is_active,
            'currency_mode' => $account->currency_mode->value,
        ];

        $dto = $request->toDto();

        $account->update([
            'name' => $dto->name,
            'account_type' => $dto->accountType,
            'balance_type' => $dto->balanceType,
            'currency_mode' => $dto->currencyMode,
            'requires_currency' => $dto->requiresCurrency,
            'requires_auxiliary' => $dto->requiresAuxiliary,
            'entity_requirement_type' => $dto->entityRequirementType,
            'requires_cost_center' => $dto->requiresCostCenter,
            'has_automatic_destination' => $dto->hasAutomaticDestination,
            'destination_debit_code' => $dto->destinationDebitCode,
            'destination_credit_code' => $dto->destinationCreditCode,
            'requires_cash_flow_area' => $dto->requiresCashFlowArea,
            'cash_flow_category' => $dto->cashFlowCategory,
            'financial_statement_rubric' => $dto->financialStatementRubric,
            'is_operational' => $dto->isOperational,
            'is_active' => $dto->isActive,
        ]);

        $this->auditLogger->log(
            eventType: 'ACCOUNT_UPDATED',
            companyId: $account->company_id,
            userId: $request->user()?->id,
            auditableType: AccountingAccount::class,
            auditableId: (string) $account->id,
            oldValues: $oldValues,
            newValues: [
                'name' => $dto->name,
                'is_operational' => $dto->isOperational,
                'is_active' => $dto->isActive,
                'currency_mode' => $dto->currencyMode->value,
            ],
            request: $request,
            severity: 'INFO',
        );

        return new AccountingAccountResource($account->fresh() ?? $account);
    }
}
