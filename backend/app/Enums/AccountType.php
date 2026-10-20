<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Clasificación contable fundamental según la naturaleza del elemento del plan de cuentas.
 */
enum AccountType: string
{
    case Asset = 'ASSET';
    case Liability = 'LIABILITY';
    case Equity = 'EQUITY';
    case Revenue = 'REVENUE';
    case Expense = 'EXPENSE';
    case Cost = 'COST';
    case Memorandum = 'MEMORANDUM';

    public function label(): string
    {
        return match ($this) {
            self::Asset => 'Activo',
            self::Liability => 'Pasivo',
            self::Equity => 'Patrimonio',
            self::Revenue => 'Ingresos',
            self::Expense => 'Gastos',
            self::Cost => 'Costos',
            self::Memorandum => 'Cuentas de Orden',
        };
    }

    /**
     * Naturaleza de saldo esperada por defecto según teoría contable estándar.
     */
    public function defaultBalanceType(): BalanceType
    {
        return match ($this) {
            self::Asset, self::Expense, self::Cost, self::Memorandum => BalanceType::Debit,
            self::Liability, self::Equity, self::Revenue => BalanceType::Credit,
        };
    }
}
