<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\TenantIntegrityException;
use App\Models\Company;
use App\Models\Scopes\TenantScope;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait reutilizable para todos los modelos discriminados por `company_id`.
 *
 * - Registra automáticamente `TenantScope`.
 * - En `creating`: inyecta el `company_id` del `TenantContext` activo y rechaza cualquier intento
 *   de fijar manualmente un `company_id` perteneciente a otra empresa.
 * - En `updating`: bloquea de forma absoluta cualquier mutación sobre `company_id`.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (Model $model): void {
            /** @var TenantContext $context */
            $context = app(TenantContext::class);

            $activeCompanyId = $context->requireCompanyId($model::class);
            $currentAttribute = $model->getAttribute('company_id');

            if ($currentAttribute !== null && (int) $currentAttribute !== $activeCompanyId) {
                throw TenantIntegrityException::mismatchedCompanyId(
                    expectedCompanyId: $activeCompanyId,
                    givenCompanyId: (int) $currentAttribute
                );
            }

            $model->setAttribute('company_id', $activeCompanyId);
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('company_id')) {
                $original = (int) $model->getOriginal('company_id');
                $new = (int) $model->getAttribute('company_id');

                if ($original !== $new) {
                    throw TenantIntegrityException::immutableCompanyId();
                }
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
