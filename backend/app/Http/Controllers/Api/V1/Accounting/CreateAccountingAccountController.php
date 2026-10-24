<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Requests\Api\V1\Accounting\CreateAccountingAccountRequest;
use App\Http\Resources\Api\V1\Accounting\AccountingAccountResource;
use App\Models\AccountingAccount;
use App\Services\Security\AuditLoggerService;
use Illuminate\Http\JsonResponse;

/**
 * Single Action Controller para crear una nueva cuenta contable dentro del tenant activo.
 */
final readonly class CreateAccountingAccountController
{
    public function __construct(
        private AuditLoggerService $auditLogger,
    ) {}

    public function __invoke(CreateAccountingAccountRequest $request): JsonResponse
    {
        $dto = $request->toDto();

        $parentPath = null;
        if ($dto->parentId !== null) {
            /** @var AccountingAccount $parent */
            $parent = AccountingAccount::query()->findOrFail($dto->parentId->toInt());
            $parentPath = $parent->path;
        }

        $account = AccountingAccount::query()->create([
            'parent_id' => $dto->parentId?->toInt(),
            'code' => $dto->code,
            'name' => $dto->name,
            'level' => $dto->level->toInt(),
            'path' => $parentPath !== null ? $parentPath . '.' . $dto->code : $dto->code,
            'account_type' => $dto->accountType,
            'balance_type' => $dto->balanceType,
            'currency_mode' => $dto->currencyMode,
            'requires_currency' => $dto->requiresCurrency,
            'requires_auxiliary' => $dto->requiresAuxiliary,
            'entity_requirement_type' => $dto->entityRequirementType,
            'requires_cost_center' => $dto->requiresCostCenter,
            'requires_company_area' => $dto->requiresCompanyArea,
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
            eventType: 'ACCOUNT_CREATED',
            companyId: $account->company_id,
            userId: $request->user()?->id,
            auditableType: AccountingAccount::class,
            auditableId: (string) $account->id,
            oldValues: null,
            newValues: [
                'code' => $account->code,
                'name' => $account->name,
                'level' => $account->level,
                'account_type' => $account->account_type->value,
                'is_operational' => $account->is_operational,
            ],
            request: $request,
            severity: 'INFO',
        );

        return (new AccountingAccountResource($account))
            ->response()
            ->setStatusCode(201);
    }
}
