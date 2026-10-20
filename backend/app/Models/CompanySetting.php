<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo para la Configuración Contable y Tributaria de la Empresa.
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int $base_currency_id
 * @property int|null $secondary_currency_id
 * @property string $tax_regime
 * @property string $default_vat_rate
 * @property bool $is_retention_agent
 * @property bool $is_perception_agent
 * @property bool $is_good_taxpayer
 * @property bool $is_sire_obliged
 * @property bool $is_ple_obliged
 * @property string|null $legal_representative_name
 * @property string|null $legal_rep_identity_doc_code
 * @property string|null $legal_representative_id_number
 * @property string|null $chief_accountant_name
 * @property string|null $chief_accountant_cpc_number
 * @property int|null $sales_vat_account_id
 * @property int|null $purchase_vat_account_id
 * @property int|null $fx_gain_account_id
 * @property int|null $fx_loss_account_id
 * @property int|null $cost_absorption_account_id
 * @property int|null $retained_earnings_account_id
 */
class CompanySetting extends Model
{
    use BelongsToTenant;

    protected $table = 'company_settings';

    protected $fillable = [
        'base_currency_id',
        'secondary_currency_id',
        'tax_regime',
        'default_vat_rate',
        'is_retention_agent',
        'is_perception_agent',
        'is_good_taxpayer',
        'is_sire_obliged',
        'is_ple_obliged',
        'legal_representative_name',
        'legal_rep_identity_doc_code',
        'legal_representative_id_number',
        'chief_accountant_name',
        'chief_accountant_cpc_number',
        'sales_vat_account_id',
        'purchase_vat_account_id',
        'fx_gain_account_id',
        'fx_loss_account_id',
        'cost_absorption_account_id',
        'retained_earnings_account_id',
    ];

    protected function casts(): array
    {
        return [
            'base_currency_id' => 'integer',
            'secondary_currency_id' => 'integer',
            'default_vat_rate' => 'string', // Decimal exacto
            'is_retention_agent' => 'boolean',
            'is_perception_agent' => 'boolean',
            'is_good_taxpayer' => 'boolean',
            'is_sire_obliged' => 'boolean',
            'is_ple_obliged' => 'boolean',
            'sales_vat_account_id' => 'integer',
            'purchase_vat_account_id' => 'integer',
            'fx_gain_account_id' => 'integer',
            'fx_loss_account_id' => 'integer',
            'cost_absorption_account_id' => 'integer',
            'retained_earnings_account_id' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function baseCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'base_currency_id');
    }

    public function secondaryCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'secondary_currency_id');
    }

    public function salesVatAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'sales_vat_account_id');
    }

    public function purchaseVatAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'purchase_vat_account_id');
    }

    public function fxGainAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'fx_gain_account_id');
    }

    public function fxLossAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'fx_loss_account_id');
    }

    public function costAbsorptionAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'cost_absorption_account_id');
    }

    public function retainedEarningsAccount(): BelongsTo
    {
        return $this->belongsTo(AccountingAccount::class, 'retained_earnings_account_id');
    }
}
