<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Company;

use Illuminate\Foundation\Http\FormRequest;

final class SaveCostCenterRequest extends FormRequest
{
    public function authorize(): bool
    {
        // RequireCompanyRole and SetTenantContext authorize every route.
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        $presence = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'id' => ['missing'],
            'uuid' => ['missing'],
            'company_id' => ['missing'],
            'is_active' => ['missing'],
            'code' => [$presence, 'required', 'string', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/D'],
            'name' => [$presence, 'required', 'string', 'max:150'],
            'destination_debit_account_id' => [$presence, 'required', 'integer', 'min:1'],
            'destination_credit_account_id' => [$presence, 'required', 'integer', 'min:1'],
        ];
    }
}
