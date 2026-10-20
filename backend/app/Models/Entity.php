<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Sunat\SunatFinancialInstitution;
use App\Models\Sunat\SunatIdentityDocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo para Terceros / Anexos (Clientes, Proveedores, Trabajadores, Bancos, etc.).
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property string $identity_document_type_code
 * @property string $tax_id
 * @property string $legal_name
 * @property string|null $commercial_name
 * @property string|null $fiscal_address
 * @property string $country_code
 * @property string|null $email
 * @property string|null $phone
 * @property bool $is_client
 * @property bool $is_supplier
 * @property bool $is_employee
 * @property bool $is_financial_institution
 * @property bool $is_shareholder
 * @property bool $is_tax_authority
 * @property string|null $bank_code
 * @property string|null $bank_account_number
 * @property string|null $bank_cci_number
 * @property bool $is_related_party
 * @property bool $is_retention_agent
 * @property bool $is_good_taxpayer
 * @property bool $has_detraction_account
 * @property string|null $detraction_account_number
 * @property bool $is_active
 */
class Entity extends Model
{
    use BelongsToTenant;

    protected $table = 'entities';

    protected $fillable = [
        'identity_document_type_code',
        'tax_id',
        'legal_name',
        'commercial_name',
        'fiscal_address',
        'country_code',
        'email',
        'phone',
        'is_client',
        'is_supplier',
        'is_employee',
        'is_financial_institution',
        'is_shareholder',
        'is_tax_authority',
        'bank_code',
        'bank_account_number',
        'bank_cci_number',
        'is_related_party',
        'is_retention_agent',
        'is_good_taxpayer',
        'has_detraction_account',
        'detraction_account_number',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_client' => 'boolean',
            'is_supplier' => 'boolean',
            'is_employee' => 'boolean',
            'is_financial_institution' => 'boolean',
            'is_shareholder' => 'boolean',
            'is_tax_authority' => 'boolean',
            'is_related_party' => 'boolean',
            'is_retention_agent' => 'boolean',
            'is_good_taxpayer' => 'boolean',
            'has_detraction_account' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function identityDocumentType(): BelongsTo
    {
        return $this->belongsTo(SunatIdentityDocumentType::class, 'identity_document_type_code', 'code');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(SunatFinancialInstitution::class, 'bank_code', 'code');
    }
}
