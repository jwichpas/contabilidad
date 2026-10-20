<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountCurrencyMode;
use App\Enums\AccountType;
use App\Enums\BalanceType;
use App\Enums\CashFlowCategory;
use App\Enums\EntityRequirementType;
use App\Enums\FinancialStatementRubric;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Types\AccountId;
use App\Support\Types\AccountLevelShort;
use App\Support\Types\CompanyId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Modelo Eloquent para una cuenta del Plan Contable (`accounting_accounts`).
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int|null $parent_id
 * @property string $code
 * @property string $name
 * @property int $level
 * @property string $path
 * @property AccountType $account_type
 * @property BalanceType $balance_type
 * @property AccountCurrencyMode $currency_mode
 * @property bool $requires_currency
 * @property bool $requires_auxiliary
 * @property EntityRequirementType $entity_requirement_type
 * @property bool $requires_cost_center
 * @property bool $has_automatic_destination
 * @property string|null $destination_debit_code
 * @property string|null $destination_credit_code
 * @property bool $requires_cash_flow_area
 * @property CashFlowCategory $cash_flow_category
 * @property FinancialStatementRubric $financial_statement_rubric
 * @property bool $is_operational
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read AccountingAccount|null $parent
 * @property-read Collection<int, AccountingAccount> $children
 * @property-read Collection<int, AccountingAccount> $childrenRecursive
 */
final class AccountingAccount extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $table = 'accounting_accounts';

    protected $fillable = [
        'parent_id',
        'code',
        'name',
        'level',
        'path',
        'account_type',
        'balance_type',
        'currency_mode',
        'requires_currency',
        'requires_auxiliary',
        'entity_requirement_type',
        'requires_cost_center',
        'has_automatic_destination',
        'destination_debit_code',
        'destination_credit_code',
        'requires_cash_flow_area',
        'cash_flow_category',
        'financial_statement_rubric',
        'is_operational',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $account): void {
            if ($account->company_id) {
                Cache::forget("accounting_operational_accounts_{$account->company_id}");
            }
        });

        static::deleted(function (self $account): void {
            if ($account->company_id) {
                Cache::forget("accounting_operational_accounts_{$account->company_id}");
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'uuid' => 'string',
            'company_id' => 'integer',
            'parent_id' => 'integer',
            'code' => 'string',
            'name' => 'string',
            'level' => 'integer',
            'path' => 'string',
            'account_type' => AccountType::class,
            'balance_type' => BalanceType::class,
            'currency_mode' => AccountCurrencyMode::class,
            'requires_currency' => 'boolean',
            'requires_auxiliary' => 'boolean',
            'entity_requirement_type' => EntityRequirementType::class,
            'requires_cost_center' => 'boolean',
            'has_automatic_destination' => 'boolean',
            'destination_debit_code' => 'string',
            'destination_credit_code' => 'string',
            'requires_cash_flow_area' => 'boolean',
            'cash_flow_category' => CashFlowCategory::class,
            'financial_statement_rubric' => FinancialStatementRubric::class,
            'is_operational' => 'boolean',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Accesores de fuerte tipado al estilo struct de C# (`AccountId`, `CompanyId`, `AccountLevelShort`).
     */
    public function typedId(): AccountId
    {
        return new AccountId((int) $this->id);
    }

    public function typedCompanyId(): CompanyId
    {
        return new CompanyId((int) $this->company_id);
    }

    public function typedParentId(): ?AccountId
    {
        return $this->parent_id !== null ? new AccountId((int) $this->parent_id) : null;
    }

    public function typedLevel(): AccountLevelShort
    {
        return new AccountLevelShort((int) $this->level);
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('code');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function childrenRecursive(): HasMany
    {
        return $this->children()->with('childrenRecursive');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOperationalActive(Builder $query): Builder
    {
        return $query->where('is_operational', true)->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWhereCodePrefix(Builder $query, string $prefix): Builder
    {
        $sanitized = preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?? '';

        return $query->where('code', 'LIKE', $sanitized . '%');
    }
}
