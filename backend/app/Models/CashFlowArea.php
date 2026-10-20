<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo para las Áreas de Flujo de Efectivo (NIC 7).
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property string $activity_category
 * @property string $flow_direction
 * @property bool $is_active
 */
class CashFlowArea extends Model
{
    use BelongsToTenant;

    protected $table = 'cash_flow_areas';

    protected $fillable = [
        'code',
        'name',
        'activity_category',
        'flow_direction',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
