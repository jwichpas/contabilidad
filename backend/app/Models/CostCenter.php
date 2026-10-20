<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo para los Centros de Costos con transferencia automática a Cuentas 94 / 95 contra 79.
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property int $destination_debit_account_id
 * @property int $destination_credit_account_id
 * @property bool $is_active
 */
class CostCenter extends Model
{
    use BelongsToTenant;

    protected $table = 'cost_centers';

    protected $fillable = [
        'code',
        'name',
        'destination_debit_account_id',
        'destination_credit_account_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'destination_debit_account_id' => 'integer',
            'destination_credit_account_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function destinationDebitAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'destination_debit_account_id');
    }

    public function destinationCreditAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'destination_credit_account_id');
    }
}
