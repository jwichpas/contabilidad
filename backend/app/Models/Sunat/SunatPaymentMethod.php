<?php

declare(strict_types=1);

namespace App\Models\Sunat;

use Illuminate\Database\Eloquent\Model;

class SunatPaymentMethod extends Model
{
    protected $table = 'sunat_table_01_payment_methods';
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'requires_financial_entity',
        'requires_operation_number',
        'is_foreign_trade',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'requires_financial_entity' => 'boolean',
            'requires_operation_number' => 'boolean',
            'is_foreign_trade' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
