<?php

declare(strict_types=1);

namespace App\Support\Company;

use Illuminate\Support\Facades\Schema;

/** Catálogos iniciales de naturaleza jurídica y macrosectores; CIIU es un dato independiente. */
final class CompanyEconomicProfile
{
    public const LEGAL_FORMS = [
        'PERSONA_NATURAL', 'EIRL', 'SAC', 'SRL', 'SA', 'SAA', 'SACS', 'OTRA',
    ];

    public const SECTORS = [
        'COMERCIAL', 'SERVICIOS', 'INDUSTRIAL', 'AGRICOLA', 'GANADERIA',
        'PESCA', 'CONSTRUCCION', 'EXTRACTIVA', 'TRANSPORTE_LOGISTICA', 'OTROS',
    ];

    /** Permite desplegar API/Vue antes de que Laravel aplique la migración pendiente. */
    public static function isReady(): bool
    {
        return Schema::hasColumn('company_settings', 'legal_form')
            && Schema::hasTable('company_economic_activities');
    }
}
