<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Actividad económica y CIIU de la empresa (una principal, varias secundarias). */
final class CompanyEconomicActivity extends Model
{
    use BelongsToTenant;

    protected $table = 'company_economic_activities';

    protected $fillable = [
        'sector_code',
        'ciiu_code',
        'description',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'company_id' => 'integer',
        ];
    }
}
