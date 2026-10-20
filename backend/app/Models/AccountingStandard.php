<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo para las Normas Internacionales de Información Financiera (NIIF)
 * y Normas Internacionales de Contabilidad (NIC) vigentes en el Perú (Resolución N.° 003-2026-EF/30).
 *
 * @property string $code
 * @property string $standard_type
 * @property int $standard_number
 * @property string $title
 * @property string|null $summary
 * @property string $legal_base
 * @property string $status
 * @property bool $affects_balance_sheet
 * @property bool $affects_income_statement
 * @property bool $affects_cash_flow
 * @property bool $is_active
 */
class AccountingStandard extends Model
{
    protected $table = 'accounting_standards';
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'standard_type',
        'standard_number',
        'title',
        'summary',
        'legal_base',
        'status',
        'affects_balance_sheet',
        'affects_income_statement',
        'affects_cash_flow',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'standard_number' => 'integer',
            'affects_balance_sheet' => 'boolean',
            'affects_income_statement' => 'boolean',
            'affects_cash_flow' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
