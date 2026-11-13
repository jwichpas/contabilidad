<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Accounting;

use App\DTOs\Accounting\CreateAccountingAccountData;
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
 * FormRequest con validación estructural, jerárquica y contable avanzada:
 * - Moneda (PEN, USD, EUR, MULTI)
 * - Entidad/Anexo (Cliente, Proveedor, Trabajador, Banco, SUNAT, etc.)
 * - Centro de Costos y Destino Automático a Cuentas 94/95 con contrapartida 79
 * - Área de Flujo de Efectivo para Cuentas 10 (NIC 7)
 * - Rubro de presentación en Estados Financieros (ej. 1041 = Efectivo y Equivalentes de Efectivo)
 */
final class CreateAccountingAccountRequest extends FormRequest
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
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'code' => ['required', 'string', 'min:1', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
            'name' => ['required', 'string', 'min:2', 'max:180'],
            'level' => ['required', 'integer', 'between:1,6'],
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
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $level = (int) $this->input('level');
                $parentId = $this->input('parent_id') !== null ? (int) $this->input('parent_id') : null;
                $code = strtoupper(trim((string) $this->input('code')));
                $isOperational = (bool) $this->input('is_operational');
                $requiresCostCenter = (bool) $this->input('requires_cost_center', false);
                $requiresCompanyArea = (bool) $this->input('requires_company_area', false);
                $hasAutoDest = (bool) $this->input('has_automatic_destination', false);
                $destDebit = $this->input('destination_debit_code');
                $destCredit = $this->input('destination_credit_code');
                $requiresCashFlowArea = (bool) $this->input('requires_cash_flow_area', false);
                $cashFlowCategory = (string) $this->input('cash_flow_category', CashFlowCategory::None->value);

                // Regla 1: Las cuentas de niveles 3 a 6 pueden ser operativas si son hojas.
                if ($isOperational && $level < 3) {
                    $validator->errors()->add(
                        'is_operational',
                        'Solo las cuentas a partir del nivel 3 pueden marcarse como operativas.'
                    );
                }

                // Regla 2: Centro de Costos y Destino Automático a Elemento 9 (Cuentas 94/95 vs 79).
                if ($requiresCompanyArea && ! $isOperational) {
                    $validator->errors()->add('requires_company_area',
                        'Solo una cuenta operativa puede exigir un área organizativa.');
                }

                if (($requiresCostCenter || $hasAutoDest) && ! $isOperational) {
                    $validator->errors()->add(
                        'requires_cost_center',
                        'Solo las cuentas operativas pueden exigir Centro de Costos o destino automático al Elemento 9.'
                    );
                }

                if ($hasAutoDest && (empty($destDebit) || empty($destCredit))) {
                    $validator->errors()->add(
                        'destination_debit_code',
                        'Si la cuenta tiene destino automático, debe indicar la cuenta de cargo en el Elemento 9 (ej. 9411 o 9511) y la cuenta de abono 79 (ej. 7911).'
                    );
                }

                // Regla 3: Área de Flujo de Efectivo (Cuenta 10).
                if ($requiresCashFlowArea && $cashFlowCategory === CashFlowCategory::None->value) {
                    $validator->errors()->add(
                        'cash_flow_category',
                        'Si la cuenta exige Área de Flujo de Efectivo, debe seleccionar su categoría de actividad (Efectivo/Operación/Inversión/Financiamiento).'
                    );
                }

                // Regla 4: Unicidad de código dentro del tenant activo.
                if (AccountingAccount::query()->where('code', $code)->exists()) {
                    $validator->errors()->add(
                        'code',
                        sprintf('Ya existe una cuenta contable con el código [%s] en la empresa activa.', $code)
                    );
                }

                // Regla 5: Validación de jerarquía padre-hijo.
                if ($level === 1 && $parentId !== null) {
                    $validator->errors()->add(
                        'parent_id',
                        'Las cuentas de nivel 1 (elemento) son cuentas raíz y no deben tener cuenta padre.'
                    );

                    return;
                }

                if ($level > 1 && $parentId === null) {
                    $validator->errors()->add(
                        'parent_id',
                        'Las cuentas de nivel 2 a 6 requieren obligatoriamente una cuenta padre.'
                    );

                    return;
                }

                if ($parentId !== null) {
                    /** @var AccountingAccount|null $parent */
                    $parent = AccountingAccount::query()->find($parentId);

                    if ($parent === null) {
                        $validator->errors()->add(
                            'parent_id',
                            'La cuenta padre seleccionada no existe en la empresa activa.'
                        );

                        return;
                    }

                    if ($parent->is_operational) {
                        $validator->errors()->add(
                            'parent_id',
                            'No se pueden crear subcuentas bajo una cuenta operativa (imputable).'
                        );
                    }

                    if ($level !== ($parent->level + 1)) {
                        $validator->errors()->add(
                            'level',
                            sprintf(
                                'El nivel de la subcuenta (%d) debe ser exactamente el nivel de la cuenta padre (%d) + 1.',
                                $level,
                                $parent->level
                            )
                        );
                    }

                    if (! str_starts_with($code, $parent->code) || strlen($code) <= strlen($parent->code)) {
                        $validator->errors()->add(
                            'code',
                            sprintf(
                                'El código de la subcuenta (%s) debe comenzar con el código de su cuenta padre (%s) y añadir dígitos adicionales.',
                                $code,
                                $parent->code
                            )
                        );
                    }
                }
            },
        ];
    }

    public function toDto(): CreateAccountingAccountData
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return CreateAccountingAccountData::fromValidatedArray($validated);
    }
}
