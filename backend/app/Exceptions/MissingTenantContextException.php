<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Excepción lanzada cuando se intenta operar sobre un modelo multi-tenant
 * sin una empresa activa configurada en el contexto de ejecución (comportamiento fail-closed).
 */
final class MissingTenantContextException extends RuntimeException
{
    public static function forModel(string $modelClass): self
    {
        return new self(sprintf(
            'Operación bloqueada (Fail-Closed): No existe una empresa activa en TenantContext para consultar o persistir el modelo [%s].',
            $modelClass
        ));
    }
}
