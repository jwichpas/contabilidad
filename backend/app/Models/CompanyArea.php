<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo para las Áreas Organizacionales de la Empresa.
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string|null $manager_name
 * @property bool $is_active
 */
class CompanyArea extends Model
{
    use BelongsToTenant;

    protected $table = 'company_areas';

    protected $fillable = [
        'code',
        'name',
        'description',
        'manager_name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
