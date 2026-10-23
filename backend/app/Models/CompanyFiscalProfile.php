<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable snapshot of the company's fiscal rules at one effective date.
 * Update/delete intentionally forbidden in PostgreSQL and not supported in API.
 */
final class CompanyFiscalProfile extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'company_fiscal_profiles';

    protected $fillable = [
        'fiscal_year', 'revision', 'effective_date', 'tax_regime', 'legal_form',
        'income_reference_year', 'annual_income', 'default_vat_rate',
        'is_retention_agent', 'is_perception_agent', 'is_good_taxpayer',
        'is_sire_obliged', 'is_ple_obliged', 'change_reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'revision' => 'integer',
            'effective_date' => 'date:Y-m-d',
            'income_reference_year' => 'integer',
            'annual_income' => 'string',
            'default_vat_rate' => 'string',
            'is_retention_agent' => 'boolean',
            'is_perception_agent' => 'boolean',
            'is_good_taxpayer' => 'boolean',
            'is_sire_obliged' => 'boolean',
            'is_ple_obliged' => 'boolean',
            'created_by' => 'integer',
        ];
    }
}
