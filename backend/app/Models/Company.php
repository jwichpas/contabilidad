<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Modelo Eloquent para la entidad `companies` (Tenant).
 *
 * @property int $id
 * @property string $uuid
 * @property string $tax_id
 * @property string $business_name
 * @property string $legal_address
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class Company extends Model
{
    use HasFactory;

    protected $table = 'companies';

    protected $fillable = [
        'tax_id',
        'business_name',
        'legal_address',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'uuid' => 'string',
            'tax_id' => 'string',
            'business_name' => 'string',
            'legal_address' => 'string',
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user', 'company_id', 'user_id')
            ->using(CompanyUser::class)
            ->withPivot(['id', 'role', 'is_active'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<CompanyUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyUser::class, 'company_id');
    }

    /**
     * @return HasMany<AccountingAccount, $this>
     */
    public function accountingAccounts(): HasMany
    {
        return $this->hasMany(AccountingAccount::class, 'company_id');
    }

    public function settings(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(CompanySetting::class, 'company_id');
    }

    public function areas(): HasMany
    {
        return $this->hasMany(CompanyArea::class, 'company_id');
    }

    public function costCenters(): HasMany
    {
        return $this->hasMany(CostCenter::class, 'company_id');
    }

    public function cashFlowAreas(): HasMany
    {
        return $this->hasMany(CashFlowArea::class, 'company_id');
    }

    public function companyCurrencies(): HasMany
    {
        return $this->hasMany(CompanyCurrency::class, 'company_id');
    }

    public function currencies(): BelongsToMany
    {
        return $this->belongsToMany(Currency::class, 'company_currencies', 'company_id', 'currency_id')
            ->withPivot(['id', 'is_base', 'is_active'])
            ->withTimestamps();
    }

    public function entities(): HasMany
    {
        return $this->hasMany(Entity::class, 'company_id');
    }
}
