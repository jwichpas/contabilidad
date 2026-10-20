<?php

declare(strict_types=1);

namespace App\Models\Sunat;

use Illuminate\Database\Eloquent\Model;

class SunatElectronicDocumentType extends Model
{
    protected $table = 'sunat_table_10_electronic_document_types';
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'short_name',
        'allows_tax_credit',
        'is_electronic_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'allows_tax_credit' => 'boolean',
            'is_electronic_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
