<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de Entidad / Anexo exigido por la cuenta contable al imputar un asiento.
 */
enum EntityRequirementType: string
{
    case None = 'NONE';
    case Customer = 'CUSTOMER';
    case Supplier = 'SUPPLIER';
    case Employee = 'EMPLOYEE';
    case Shareholder = 'SHAREHOLDER';
    case FinancialInstitution = 'FINANCIAL_INSTITUTION';
    case TaxAuthority = 'TAX_AUTHORITY';
    case AnyThirdParty = 'ANY_THIRD_PARTY';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No exige entidad',
            self::Customer => 'Cliente (RUC/DNI)',
            self::Supplier => 'Proveedor (RUC)',
            self::Employee => 'Trabajador / Planilla',
            self::Shareholder => 'Accionista / Socio',
            self::FinancialInstitution => 'Entidad Financiera / Banco',
            self::TaxAuthority => 'Administración Tributaria (SUNAT / AFP / EsSalud)',
            self::AnyThirdParty => 'Cualquier Entidad / Tercero',
        };
    }

    public function requiresEntity(): bool
    {
        return $this !== self::None;
    }
}
