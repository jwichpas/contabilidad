<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo para los Rubros de Estados Financieros (SMV / NIIF 18).
 *
 * @property string $code
 * @property string $statement_type
 * @property string $group_name
 * @property string $name
 * @property string|null $description
 * @property string $normal_balance
 * @property int $presentation_order
 * @property bool $is_active
 */
class FinancialStatementRubricModel extends Model
{
    protected $table = 'financial_statement_rubrics';
    protected $primaryKey = 'code';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'statement_type',
        'group_name',
        'name',
        'description',
        'normal_balance',
        'presentation_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'presentation_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
