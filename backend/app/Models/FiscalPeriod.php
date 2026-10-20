<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Modelo Eloquent para periodos contables mensuales (`fiscal_periods`).
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $year
 * @property int $month
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property string $status
 * @property Carbon|null $closed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class FiscalPeriod extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'fiscal_periods';

    protected $fillable = [
        'company_id',
        'year',
        'month',
        'start_date',
        'end_date',
        'status',
        'closed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'uuid' => 'string',
            'company_id' => 'integer',
            'year' => 'integer',
            'month' => 'integer',
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'status' => 'string',
            'closed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === 'OPEN';
    }
}
