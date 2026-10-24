<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Modelo Eloquent para cabecera de asiento contable inmutable (`journal_entries`).
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $fiscal_period_id
 * @property string $entry_number
 * @property Carbon $entry_date
 * @property string $entry_type
 * @property string $subdiary_code
 * @property string $status
 * @property string $currency
 * @property string $exchange_rate
 * @property string $exchange_rate_type
 * @property string $description
 * @property int|null $reversed_entry_id
 * @property int $created_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, JournalEntryLine> $lines
 */
final class JournalEntry extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'journal_entries';

    protected $fillable = [
        'fiscal_period_id',
        'entry_number',
        'entry_date',
        'entry_type',
        'subdiary_code',
        'status',
        'currency',
        'exchange_rate',
        'exchange_rate_type',
        'description',
        'reversed_entry_id',
        'created_by_user_id',
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
            'fiscal_period_id' => 'integer',
            'entry_number' => 'string',
            'entry_date' => 'immutable_date',
            'entry_type' => 'string',
            'subdiary_code' => 'string',
            'status' => 'string',
            'currency' => 'string',
            'exchange_rate' => 'decimal:6',
            'exchange_rate_type' => 'string',
            'description' => 'string',
            'reversed_entry_id' => 'integer',
            'created_by_user_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<FiscalPeriod, $this>
     */
    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    /**
     * @return HasMany<JournalEntryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'journal_entry_id')->orderBy('line_number');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_entry_id');
    }
}
