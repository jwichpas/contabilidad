<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Moneda de configuración de la cuenta contable.
 */
enum AccountCurrencyMode: string
{
    case Pen = 'PEN';
    case Usd = 'USD';
    case Eur = 'EUR';
    case Multi = 'MULTI';

    public function label(): string
    {
        return match ($this) {
            self::Pen => 'Soles (PEN - Moneda Local)',
            self::Usd => 'Dólares Americanos (USD)',
            self::Eur => 'Euros (EUR)',
            self::Multi => 'Bimoneda / Multimoneda',
        };
    }

    public function requiresForeignExchange(): bool
    {
        return $this === self::Usd || $this === self::Eur || $this === self::Multi;
    }
}
