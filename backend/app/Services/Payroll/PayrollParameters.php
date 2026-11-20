<?php

declare(strict_types=1);

namespace App\Services\Payroll;

final readonly class PayrollParameters
{
    public const OVERTIME_FIRST = '25';
    public const OVERTIME_REST = '35';
    public const ONP = '13';
    public const AFP = '10';
    public const ESSALUD = '9';
    public const BONUS_EPS = '6.75';

    public function __construct(private PayrollLedger $ledger) {}

    public function forMonth(string $month): array
    {
        $records = $this->entries($this->ledger->records('parameter'));
        $minimum = $this->effective($records, 'rmv', $month);
        $uit = $this->effective($records, 'uit', substr($month, 0, 4));
        $afp = $this->effective($records, 'afp', $month);
        $amount = $minimum['data']['amount'] ?? null;
        $taxUnit = $uit['data']['amount'] ?? null;
        $revisions = [];
        foreach ([$minimum, $uit, $afp] as $entry) {
            if ($entry && $entry['revision'] > 0) {
                $revisions[$entry['data']['kind']] = $entry['reference'].'#'.$entry['revision'];
            }
        }

        return [
            'minimum' => $minimum ? [
                'amount' => $amount, 'from' => $minimum['data']['period'],
                'rule' => $minimum['data']['source'], 'url' => $minimum['data']['url'],
            ] : null,
            'family_allowance' => $amount ? bcmul($amount, '0.10', 2) : null,
            'night_minimum' => $amount ? bcmul($amount, '1.35', 2) : null,
            'uit' => $taxUnit,
            'tax_deduction' => $taxUnit ? bcmul($taxUnit, '7', 2) : null,
            'overtime' => [self::OVERTIME_FIRST, self::OVERTIME_REST],
            'onp' => self::ONP,
            'afp_contribution' => $afp['data']['contribution'] ?? self::AFP,
            'essalud' => self::ESSALUD,
            'gratification_bonus' => ['essalud' => self::ESSALUD, 'eps' => self::BONUS_EPS],
            'afp' => $afp ? array_intersect_key($afp['data'], array_flip([
                'insurance', 'insurance_ceiling', 'administrators', 'contribution', 'source', 'url',
            ])) : null,
            'revisions' => $revisions,
        ];
    }

    public function history(): array
    {
        return array_merge($this->ledger->parameterHistory(), array_values($this->defaults()));
    }

    private function effective(array $records, string $kind, string $period): ?array
    {
        if ($kind !== 'rmv') {
            return $records["$kind:$period"] ?? null;
        }
        foreach (array_reverse($records, true) as $entry) {
            $data = $entry['data'];
            if ($data['kind'] === 'rmv' && $data['period'] <= $period) {
                return $entry['revision'] > 0 || $period <= '2026-12' ? $entry : null;
            }
        }

        return null;
    }

    private function entries(array $stored): array
    {
        $records = $stored + $this->defaults();
        ksort($records, SORT_STRING);

        return $records;
    }

    private function defaults(): array
    {
        $records = [];
        foreach ([
            ['kind' => 'rmv', 'period' => '2025-01', 'amount' => '1130.00', 'source' => 'D. S. 006-2024-TR',
                'url' => 'https://www.gob.pe/institucion/mtpe/normas-legales/6335262-006-2024-tr'],
            ['kind' => 'rmv', 'period' => '2026-10', 'amount' => '1230.00', 'source' => 'D. S. 015-2026-TR',
                'url' => 'https://busquedas.elperuano.pe/dispositivo/EX/2559163-1'],
            ['kind' => 'uit', 'period' => '2025', 'amount' => '5350.00', 'source' => 'D. S. 260-2024-EF',
                'url' => 'https://www.mef.gob.pe/es/valor-de-la-uit'],
            ['kind' => 'uit', 'period' => '2026', 'amount' => '5500.00', 'source' => 'D. S. 301-2025-EF',
                'url' => 'https://www.mef.gob.pe/es/valor-de-la-uit'],
            ['kind' => 'afp', 'period' => '2026-11', 'source' => 'SBS · Devengue 2026-11',
                'url' => 'https://www.sbs.gob.pe/app/spp/empleadores/comisiones_spp/Paginas/comision_prima.aspx',
                'contribution' => self::AFP, 'insurance' => '1.37', 'insurance_ceiling' => '12732.70',
                'administrators' => [
                    'HA' => ['flow' => '1.47', 'balance' => '1.25'],
                    'IN' => ['flow' => '1.55', 'balance' => '0.78'],
                    'PR' => ['flow' => '1.69', 'balance' => '0.68'],
                    'RI' => ['flow' => '1.60', 'balance' => '1.25'],
                ],
            ],
        ] as $entry) {
            $key = $entry['kind'].':'.$entry['period'];
            $records[$key] = ['reference' => $key, 'revision' => 0, 'data' => $entry, 'created_by' => null, 'created_at' => null];
        }

        return $records;
    }
}
