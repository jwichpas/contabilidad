<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Modelo Eloquent para líneas bimoneda de un asiento contable (`journal_entry_lines`).
 *
 * @property int $id
 * @property int $company_id
 * @property int $journal_entry_id
 * @property int $line_number
 * @property int $accounting_account_id
 * @property string|null $description
 * @property string $debit_local
 * @property string $credit_local
 * @property string $debit_foreign
 * @property string $credit_foreign
 * @property string $exchange_rate
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class JournalEntryLine extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'journal_entry_lines';

    protected $fillable = [
        'journal_entry_id',
        'line_number',
        'accounting_account_id',
        'description',
        'debit_local',
        'credit_local',
        'debit_foreign',
        'credit_foreign',
        'exchange_rate',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'company_id' => 'integer',
            'journal_entry_id' => 'integer',
            'line_number' => 'integer',
            'accounting_account_id' => 'integer',
            'description' => 'string',
            'debit_local' => 'decimal:4',
            'credit_local' => 'decimal:4',
            'debit_foreign' => 'decimal:4',
            'credit_foreign' => 'decimal:4',
            'exchange_rate' => 'decimal:6',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<AccountingAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'accounting_account_id');
    }
}
