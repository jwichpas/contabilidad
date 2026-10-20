<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Roles disponibles para un usuario dentro de una empresa específica (`company_user.role`).
 */
enum CompanyRole: string
{
    case Admin = 'ADMIN';
    case ChiefAccountant = 'CHIEF_ACCOUNTANT';
    case Accountant = 'ACCOUNTANT';
    case DataEntry = 'DATA_ENTRY';
    case Auditor = 'AUDITOR';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador de Empresa',
            self::ChiefAccountant => 'Contador General',
            self::Accountant => 'Contador Analista',
            self::DataEntry => 'Digitador Contable',
            self::Auditor => 'Auditor Externo / Consulta',
        };
    }
}
