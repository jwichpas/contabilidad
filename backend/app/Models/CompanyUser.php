<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CompanyRole;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Modelo Pivote `company_user` con aislamiento multi-tenant (`BelongsToTenant` + RLS en BD).
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property CompanyRole $role
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
final class CompanyUser extends Pivot
{
    use BelongsToTenant;

    public $incrementing = true;

    protected $table = 'company_user';

    protected $fillable = [
        'company_id',
        'user_id',
        'role',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'company_id' => 'integer',
            'user_id' => 'integer',
            'role' => CompanyRole::class,
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
