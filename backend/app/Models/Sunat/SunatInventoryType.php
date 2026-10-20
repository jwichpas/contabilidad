<?php

declare(strict_types=1);

namespace App\Models\Sunat;

use Illuminate\Database\Eloquent\Model;

class SunatInventoryType extends Model
{
    protected $table = 'sunat_table_05_inventory_types';
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'related_account_element',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
