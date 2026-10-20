<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Clasificación de área para Estado de Flujos de Efectivo (NIC 7), especialmente para cuentas del Elemento 10.
 */
enum CashFlowCategory: string
{
    case None = 'NONE';
    case CashEquivalent = 'CASH_EQUIVALENT';
    case Operating = 'OPERATING';
    case Investing = 'INVESTING';
    case Financing = 'FINANCING';

    public function label(): string
    {
        return match ($this) {
            self::None => 'No aplica a Flujo de Efectivo',
            self::CashEquivalent => 'Efectivo y Equivalentes de Efectivo (Cuentas 10)',
            self::Operating => 'Actividades de Operación',
            self::Investing => 'Actividades de Inversión',
            self::Financing => 'Actividades de Financiamiento',
        };
    }
}
