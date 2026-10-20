<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Naturaleza del saldo contable de una cuenta.
 */
enum BalanceType: string
{
    case Debit = 'DEBIT';
    case Credit = 'CREDIT';

    public function label(): string
    {
        return match ($this) {
            self::Debit => 'Deudor',
            self::Credit => 'Acreedor',
        };
    }
}
