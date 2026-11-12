<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Cada comprobante puede incluir categorías tributarias diferentes. */
final class AccountingTaxRegisterAllocation extends Model
{
    use BelongsToTenant;

    protected $table = 'accounting_tax_register_allocations';

    protected $fillable = ['tax_register_entry_id', 'category', 'base_amount', 'tax_amount', 'creditable_tax_amount'];

    protected function casts(): array
    {
        return [
            'id' => 'integer', 'company_id' => 'integer',
            'tax_register_entry_id' => 'integer', 'base_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4', 'creditable_tax_amount' => 'decimal:4',
        ];
    }
}
