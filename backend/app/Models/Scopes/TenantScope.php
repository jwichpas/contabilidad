<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Exceptions\MissingTenantContextException;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global Scope que garantiza que toda consulta Eloquent filtre por la empresa activa.
 *
 * Comportamiento Fail-Closed:
 * - Si no existe `company_id` activo en `TenantContext` (ni en HTTP, ni en consola/colas sin `TenantContext::run`),
 *   agrega un predicado imposible (`WHERE 1 = 0`) y lanza `MissingTenantContextException` para impedir fugas
 *   de datos por omisión de middleware o jobs mal configurados.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        if ($context->isBypassed()) {
            return;
        }

        $companyId = $context->getCompanyId();

        if ($companyId === null) {
            // Defensa doble: predicado falso en el query builder + excepción explícita fail-closed.
            $builder->whereRaw('1 = 0');

            throw MissingTenantContextException::forModel($model::class);
        }

        $builder->where($model->qualifyColumn('company_id'), '=', $companyId);
    }
}
