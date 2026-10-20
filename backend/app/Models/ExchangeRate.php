<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Math\Decimal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Modelo Eloquent para tipos de cambio diarios (`exchange_rates`) con escala `NUMERIC(14,6)`.
 *
 * @property int $id
 * @property int $company_id
 * @property Carbon $rate_date
 * @property string $source_currency
 * @property string $target_currency
 * @property string $buy_rate
 * @property string $sell_rate
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class ExchangeRate extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'exchange_rates';

    protected $fillable = [
        'company_id',
        'rate_date',
        'source_currency',
        'target_currency',
        'buy_rate',
        'sell_rate',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'company_id' => 'integer',
            'rate_date' => 'immutable_date',
            'source_currency' => 'string',
            'target_currency' => 'string',
            'buy_rate' => 'decimal:6',
            'sell_rate' => 'decimal:6',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function buyRateDecimal(): Decimal
    {
        return Decimal::ofExchangeRate((string) $this->buy_rate);
    }

    public function sellRateDecimal(): Decimal
    {
        return Decimal::ofExchangeRate((string) $this->sell_rate);
    }
}
