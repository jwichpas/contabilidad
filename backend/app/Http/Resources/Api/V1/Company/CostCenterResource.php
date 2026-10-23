<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Company;

use App\Models\AccountingAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CostCenterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'is_active' => (bool) $this->is_active,
            'destination_debit_account_id' => (int) $this->destination_debit_account_id,
            'destination_credit_account_id' => (int) $this->destination_credit_account_id,
            'destination_debit_account' => $this->whenLoaded('destinationDebitAccount',
                fn () => $this->account($this->destinationDebitAccount)),
            'destination_credit_account' => $this->whenLoaded('destinationCreditAccount',
                fn () => $this->account($this->destinationCreditAccount)),
        ];
    }

    private function account(?AccountingAccount $account): ?array
    {
        return $account === null ? null : [
            'id' => (int) $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'is_active' => (bool) $account->is_active,
            'is_operational' => (bool) $account->is_operational,
        ];
    }
}
