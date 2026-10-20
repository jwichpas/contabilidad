<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo para monedas oficiales (ISO 4217 y Tabla 4 de SUNAT).
 *
 * @property int $id
 * @property string $code
 * @property string $sunat_code
 * @property string $name
 * @property string $symbol
 * @property int $decimal_places
 * @property int $accounting_precision
 * @property bool $is_base_currency
 * @property bool $is_active
 */
class Currency extends Model
{
    protected $table = 'currencies';

    protected $fillable = [
        'code',
        'sunat_code',
        'name',
        'symbol',
        'decimal_places',
        'accounting_precision',
        'is_base_currency',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'accounting_precision' => 'integer',
            'is_base_currency' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
