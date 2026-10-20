<?php

declare(strict_types=1);

namespace App\Models\Sunat;

use Illuminate\Database\Eloquent\Model;

class SunatIdentityDocumentType extends Model
{
    protected $table = 'sunat_table_02_identity_document_types';
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'abbreviation',
        'exact_length',
        'max_length',
        'is_numeric_only',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'exact_length' => 'integer',
            'max_length' => 'integer',
            'is_numeric_only' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
