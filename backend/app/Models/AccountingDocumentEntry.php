<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Fuente documental inmutable del asiento registrado en el libro diario. */
final class AccountingDocumentEntry extends Model
{
    use BelongsToTenant;

    protected $table = 'accounting_document_entries';

    protected $fillable = [
        'module', 'document_type', 'series', 'number', 'entity_id',
        'issue_date', 'entry_date', 'currency', 'exchange_rate',
        'taxable_amount', 'tax_amount', 'total_amount', 'journal_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer', 'company_id' => 'integer',
            'entity_id' => 'integer', 'journal_entry_id' => 'integer',
            'exchange_rate' => 'decimal:6', 'taxable_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4', 'total_amount' => 'decimal:4',
        ];
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function taxRegister(): HasOne
    {
        return $this->hasOne(AccountingTaxRegisterEntry::class, 'accounting_document_entry_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id');
    }
}
