<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Company-owned accounting subdiary, never deleted after journal use. */
final class CompanySubdiary extends Model
{
    use BelongsToTenant;

    protected $table = 'company_subdiaries';

    protected $fillable = ['code', 'name', 'is_active'];

    protected function casts(): array
    {
        return ['id' => 'integer', 'company_id' => 'integer', 'is_active' => 'boolean'];
    }
}
