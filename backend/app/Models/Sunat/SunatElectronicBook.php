<?php

declare(strict_types=1);

namespace App\Models\Sunat;

use Illuminate\Database\Eloquent\Model;

class SunatElectronicBook extends Model
{
    protected $table = 'sunat_table_08_electronic_books';
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'ple_book_code',
        'is_compulsory_general_regime',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_compulsory_general_regime' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
