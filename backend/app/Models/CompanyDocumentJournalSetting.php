<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Configuración independiente de compras y ventas, siempre por empresa activa. */
final class CompanyDocumentJournalSetting extends Model
{
    use BelongsToTenant;

    protected $table = 'company_document_journal_settings';

    protected $fillable = [
        'module', 'subdiary_code', 'main_account_id', 'tax_account_id',
        'settlement_account_id', 'default_tax_rate',
    ];

    protected function casts(): array
    {
        return [
            'id' => 'integer', 'company_id' => 'integer',
            'main_account_id' => 'integer', 'tax_account_id' => 'integer',
            'settlement_account_id' => 'integer', 'default_tax_rate' => 'decimal:4',
        ];
    }
}
