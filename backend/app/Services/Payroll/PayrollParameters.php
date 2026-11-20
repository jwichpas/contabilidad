<?php

declare(strict_types=1);

namespace App\Services\Payroll;

final class PayrollParameters
{
    public const OVERTIME_FIRST = '25';
    public const OVERTIME_REST = '35';
    public const ONP = '13';
    public const AFP = '10';
    public const ESSALUD = '9';
    public const BONUS_EPS = '6.75';

    public function forMonth(string $month): array
    {
        $minimum = match (true) {
            $month >= '2026-10' && $month <= '2026-12' => [
                'amount' => '1230.00', 'from' => '2026-10',
                'rule' => 'D. S. 015-2026-TR', 'url' => 'https://busquedas.elperuano.pe/dispositivo/EX/2559163-1',
            ],
            $month >= '2025-01' && $month < '2026-10' => [
                'amount' => '1130.00', 'from' => '2025-01',
                'rule' => 'D. S. 006-2024-TR', 'url' => 'https://www.gob.pe/institucion/mtpe/normas-legales/6335262-006-2024-tr',
            ],
            default => null,
        };
        $uit = match (substr($month, 0, 4)) {
            '2025' => '5350.00',
            '2026' => '5500.00',
            default => null,
        };

        return [
            'minimum' => $minimum,
            'family_allowance' => $minimum ? bcmul($minimum['amount'], '0.10', 2) : null,
            'night_minimum' => $minimum ? bcmul($minimum['amount'], '1.35', 2) : null,
            'uit' => $uit,
            'tax_deduction' => $uit ? bcmul($uit, '7', 2) : null,
            'overtime' => [self::OVERTIME_FIRST, self::OVERTIME_REST],
            'onp' => self::ONP,
            'afp_contribution' => self::AFP,
            'essalud' => self::ESSALUD,
            'gratification_bonus' => ['essalud' => self::ESSALUD, 'eps' => self::BONUS_EPS],
            'afp' => $month === '2026-11' ? [
                'source' => 'SBS · Devengue 2026-11',
                'insurance' => '1.37',
                'insurance_ceiling' => '12732.70',
                'administrators' => [
                    'HA' => ['flow' => '1.47', 'balance' => '1.25'],
                    'IN' => ['flow' => '1.55', 'balance' => '0.78'],
                    'PR' => ['flow' => '1.69', 'balance' => '0.68'],
                    'RI' => ['flow' => '1.60', 'balance' => '1.25'],
                ],
            ] : null,
        ];
    }
}
