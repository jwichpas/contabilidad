<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Registro tributario local: pendiente de cotejo SUNAT, no equivale a SIRE generado. */
final class AccountingTaxRegisterEntry extends Model
{
    use BelongsToTenant;

    protected $table = 'accounting_tax_register_entries';

    protected $fillable = [
        'accounting_document_entry_id', 'module', 'tax_period', 'source',
        'reconciliation_status', 'counterparty_identity_type',
        'counterparty_tax_id', 'counterparty_name', 'due_date',
    ];

    protected function casts(): array
    {
        return ['id' => 'integer', 'company_id' => 'integer', 'accounting_document_entry_id' => 'integer'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(AccountingDocumentEntry::class, 'accounting_document_entry_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(AccountingTaxRegisterAllocation::class, 'tax_register_entry_id');
    }
}
