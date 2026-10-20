<?php

declare(strict_types=1);

namespace App\Models\Sunat;

use Illuminate\Database\Eloquent\Model;

class SunatFinancialInstitution extends Model
{
    protected $table = 'sunat_table_03_financial_institutions';
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'short_name',
        'tax_id',
        'is_bank',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_bank' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
