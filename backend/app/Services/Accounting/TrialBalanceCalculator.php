<?php

declare(strict_types=1);

namespace App\Services\Accounting;

/** Formato interno de comprobación, no archivo PLE 3.17 validado. */
final class TrialBalanceCalculator
{
    /** @param list<array<string,mixed>> $accounts */
    public function calculate(array $accounts): array
    {
        $keys = ['opening_debit','opening_credit','movement_debit','movement_credit',
            'closing_debit','closing_credit'];
        $totals = array_fill_keys($keys, '0.0000');
        $rows = [];
        foreach ($accounts as $a) {
            $opening = bcsub($a['opening_debit'], $a['opening_credit'], 4);
            $movement = bcsub($a['period_debit'], $a['period_credit'], 4);
            $closing = bcadd($opening, $movement, 4);
            $row = [
                'account_id' => $a['id'], 'code' => $a['code'], 'name' => $a['name'],
                'opening_debit' => self::positive($opening),
                'opening_credit' => self::negative($opening),
                'movement_debit' => $a['period_debit'], 'movement_credit' => $a['period_credit'],
                'closing_debit' => self::positive($closing),
                'closing_credit' => self::negative($closing),
            ];
            foreach ($keys as $key) {
                $totals[$key] = bcadd($totals[$key], $row[$key], 4);
            }
            $rows[] = $row;
        }
        return [
            'rows' => $rows, 'totals' => $totals,
            'differences' => [
                'opening' => bcsub($totals['opening_debit'], $totals['opening_credit'], 4),
                'movements' => bcsub($totals['movement_debit'], $totals['movement_credit'], 4),
                'closing' => bcsub($totals['closing_debit'], $totals['closing_credit'], 4),
            ],
            'balanced' => bccomp($totals['opening_debit'], $totals['opening_credit'], 4) === 0
                && bccomp($totals['movement_debit'], $totals['movement_credit'], 4) === 0
                && bccomp($totals['closing_debit'], $totals['closing_credit'], 4) === 0,
            'is_preliminary' => true,
            'official_sunat_file' => false,
        ];
    }

    public static function positive(string $value): string
    {
        return bccomp($value, '0', 4) > 0 ? $value : '0.0000';
    }
    public static function negative(string $value): string
    {
        return bccomp($value, '0', 4) < 0 ? bcsub('0', $value, 4) : '0.0000';
    }
}
