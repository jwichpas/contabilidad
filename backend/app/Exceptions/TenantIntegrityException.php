<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * Excepción lanzada cuando se intenta asignar manualmente un `company_id` distinto
 * al del tenant activo o modificar el `company_id` de un registro existente.
 */
final class TenantIntegrityException extends DomainException
{
    public static function mismatchedCompanyId(int $expectedCompanyId, int $givenCompanyId): self
    {
        return new self(sprintf(
            'Violación de aislamiento multi-tenant: Se intentó asignar company_id [%d] cuando el contexto activo pertenece a company_id [%d].',
            $givenCompanyId,
            $expectedCompanyId
        ));
    }

    public static function immutableCompanyId(): self
    {
        return new self('Violación de integridad contable: El atributo company_id es inmutable una vez creado el registro.');
    }
}
