<?php

declare(strict_types=1);

namespace App\Models\Sunat;

use Illuminate\Database\Eloquent\Model;

class SunatOperationType extends Model
{
    protected $table = 'sunat_table_12_operation_types';
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'operation_category',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
