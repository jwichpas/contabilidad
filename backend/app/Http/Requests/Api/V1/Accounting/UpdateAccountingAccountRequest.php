<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Accounting;

use App\DTOs\Accounting\UpdateAccountingAccountData;
use App\Enums\AccountCurrencyMode;
use App\Enums\AccountType;
use App\Enums\BalanceType;
use App\Enums\CashFlowCategory;
use App\Enums\EntityRequirementType;
use App\Enums\FinancialStatementRubric;
use App\Models\AccountingAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * FormRequest para actualizar una cuenta contable respetando sus restricciones jerárquicas y contables.
 */
final class UpdateAccountingAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:180'],
            'account_type' => ['required', 'string', Rule::enum(AccountType::class)],
            'balance_type' => ['required', 'string', Rule::enum(BalanceType::class)],
            'currency_mode' => ['sometimes', 'string', Rule::enum(AccountCurrencyMode::class)],
            'requires_currency' => ['required', 'boolean'],
            'requires_auxiliary' => ['required', 'boolean'],
            'entity_requirement_type' => ['sometimes', 'string', Rule::enum(EntityRequirementType::class)],
            'requires_cost_center' => ['sometimes', 'boolean'],
            'requires_company_area' => ['sometimes', 'boolean'],
            'has_automatic_destination' => ['sometimes', 'boolean'],
            'destination_debit_code' => ['nullable', 'string', 'max:20', 'regex:/^9[0-9A-Za-z]+$/'],
            'destination_credit_code' => ['nullable', 'string', 'max:20', 'regex:/^7[89][0-9A-Za-z]*$/'],
            'requires_cash_flow_area' => ['sometimes', 'boolean'],
            'cash_flow_category' => ['sometimes', 'string', Rule::enum(CashFlowCategory::class)],
            'financial_statement_rubric' => ['sometimes', 'string', Rule::enum(FinancialStatementRubric::class)],
            'is_operational' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $accountId = (int) $this->route('accountId');
                /** @var AccountingAccount|null $account */
                $account = AccountingAccount::query()->find($accountId);

                if ($account === null) {
                    return;
                }

                $isOperational = (bool) $this->input('is_operational');
                $hasAutoDest = (bool) $this->input('has_automatic_destination', false);
                if ((bool) $this->input('requires_company_area', false) && ! $isOperational) {
                    $validator->errors()->add('requires_company_area',
                        'Solo una cuenta operativa puede exigir un área organizativa.');
                }
                $destDebit = $this->input('destination_debit_code');
                $destCredit = $this->input('destination_credit_code');

                if ($isOperational && $account->level !== 4) {
                    $validator->errors()->add(
                        'is_operational',
                        'Solo las cuentas de nivel 4 (registro) pueden marcarse como operativas.'
                    );
                }

                if ($isOperational && $account->children()->exists()) {
                    $validator->errors()->add(
                        'is_operational',
                        'No se puede marcar como operativa una cuenta que ya tiene subcuentas hijas.'
                    );
                }

                if ($hasAutoDest && (empty($destDebit) || empty($destCredit))) {
                    $validator->errors()->add(
                        'destination_debit_code',
                        'Si la cuenta tiene destino automático, debe indicar la cuenta de cargo (94/95) y la cuenta de abono (79).'
                    );
                }
            },
        ];
    }

    public function toDto(): UpdateAccountingAccountData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return UpdateAccountingAccountData::fromValidatedArray($validated);
    }
}
