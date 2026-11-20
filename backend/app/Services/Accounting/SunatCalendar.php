<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use Carbon\CarbonImmutable;

final class SunatCalendar
{
    public const RESOLUTION = 'R.S. N.º 281-2022/SUNAT';
    public const ANNEX = 'https://www.sunat.gob.pe/legislacion/superin/2006/234.htm';
    private const AMENDMENT = 'https://www.sunat.gob.pe/legislacion/superin/2025/000257-2025.pdf';
    private const INVENTORY = 'https://www.sunat.gob.pe/legislacion/superin/2015/169-2015.pdf';
    public const CATALOG = [
        '1' => ['Libro Caja y Bancos', 'quarter'],
        '2' => ['Libro de Ingresos y Gastos', 'ten'],
        '3' => ['Libro de Inventarios y Balances', 'annual'],
        '4' => ['Libro de Retenciones del artículo 34, incisos e) y f)', 'ten'],
        '5' => ['Libro Diario', 'quarter'],
        '5-A' => ['Libro Diario de Formato Simplificado', 'quarter'],
        '6' => ['Libro Mayor', 'quarter'],
        '7' => ['Registro de Activos Fijos', 'annual'],
        '8' => ['Registro de Compras (RCE electrónico)', 'published'],
        '9' => ['Registro de Consignaciones', 'ten'],
        '10' => ['Registro de Costos', 'annual'],
        '11' => ['Registro de Huéspedes', 'ten'],
        '12' => ['Inventario Permanente en Unidades Físicas', 'month'],
        '13' => ['Inventario Permanente Valorizado', 'valued'],
        '14' => ['Registro de Ventas e Ingresos (RVIE electrónico)', 'published'],
        '15' => ['Registro de Ventas e Ingresos · art. 23 de la R.S. 266-2004', 'ten'],
        '16' => ['Registro del Régimen de Percepciones', 'ten'],
        '17' => ['Registro del Régimen de Retenciones', 'ten'],
        '18' => ['Registro IVAP', 'trigger'],
        '19' => ['Auxiliar de Adquisiciones · R.S. 022-98', 'ten'],
        '20' => ['Auxiliar de Adquisiciones · R.S. 021-99', 'ten'],
        '21' => ['Auxiliar de Adquisiciones · R.S. 142-2001', 'ten'],
        '22' => ['Auxiliar de Adquisiciones · R.S. 256-2004', 'ten'],
        '23' => ['Auxiliar de Adquisiciones · R.S. 257-2004', 'ten'],
        '24' => ['Auxiliar de Adquisiciones · R.S. 258-2004', 'ten'],
        '25' => ['Auxiliar de Adquisiciones · R.S. 259-2004', 'ten'],
    ];

    public function edition(int $year): ?array
    {
        $path = database_path("reference_data/sunat_due_dates_$year.json");
        return is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : null;
    }

    public function group(string $digit): int
    {
        return match ($digit) {
            '0' => 0, '1' => 1, '2', '3' => 2, '4', '5' => 3, '6', '7' => 4, '8', '9' => 5, 'BC' => 6,
        };
    }

    public function company(int $year, string $digit, array $regimes, array $profile): array
    {
        $data = $this->edition($year);
        $group = $this->group($profile['monthly_group'] === 'BC' ? 'BC' : $digit);
        $events = [];
        $add = static function (string $code, string $label, string $type, string $period, ?string $date, string $source, string $basis) use (&$events): void {
            $events["$code:$period"] = compact('code', 'label', 'type', 'period', 'date', 'source', 'basis');
        };

        $yearBooks = [];
        for ($month = 1; $month <= 12; $month++) {
            $regime = $regimes[$month];
            $books = $this->booksFor($regime, $profile);
            $yearBooks = array_values(array_unique([...$yearBooks, ...$books]));
            $period = sprintf('%d-%02d', $year, $month);
            $taxDate = $data['tax'][$month][$group] ?? null;
            $booksDate = $data['books'][$month][$group] ?? null;
            if ($regime !== null) {
                $add('MONTHLY', $regime === 'NRUS' ? 'Cuota mensual Nuevo RUS' : 'Declaración y pago mensual de IGV y Renta (FV 621)',
                    'tax', $period, $taxDate, $data['tax_source'] ?? '', 'Cronograma mensual por RUC');
            }
            if ($profile['plame']) {
                $add('PLAME', 'Planilla Electrónica PLAME', 'tax', $period, $taxDate, $data['tax_source'] ?? '', 'Cronograma mensual por RUC');
            }
            if (($month === 3 && $profile['itan'] !== 'NONE') || ($month > 3 && $month <= 11 && $profile['itan'] === 'INSTALLMENTS')) {
                $label = $month === 3 ? 'ITAN · Declaración y primera cuota o pago al contado'
                    : 'ITAN · Cuota '.($month - 2);
                $add('ITAN', $label, 'tax', $period, $taxDate,
                    'https://orientacion.sunat.gob.pe/7354-08-cronograma-para-la-presentacion-y-pago-del-itan-2020',
                    'Calendario ordinario ITAN');
            }
            $anchor = CarbonImmutable::create($year, $month, 1, 0, 0, 0, 'UTC')->addMonth();
            foreach ($books as $code) {
                [$label, $rule] = self::CATALOG[$code];
                if (in_array($rule, ['annual', 'trigger'], true)) {
                    continue;
                }
                if ($rule === 'published' && $profile['sales_electronic']) {
                    $add($code, $label, 'books', $period, $booksDate, $data['books_source'] ?? '', 'Máximo atraso de registros electrónicos por RUC');
                    continue;
                }
                if ($rule === 'published') {
                    $rule = 'ten';
                    $label = $code === '8' ? 'Registro de Compras no electrónico' : 'Registro de Ventas e Ingresos no electrónico';
                }
                if ($rule === 'valued' && $profile['inventory_electronic'] && !in_array($month, [6, 12], true)) {
                    continue;
                }
                $ruleSource = $rule === 'valued' ? self::INVENTORY : (in_array($code, ['5','5-A','6'], true) ? self::AMENDMENT : self::ANNEX);
                $add($code, $label, 'books', $period, $this->fromAnchor($anchor, $rule), $ruleSource,
                    $rule === 'ten' ? '10 días hábiles desde el primer día hábil siguiente al mes del hecho aplicable'
                    : ($rule === 'month' ? '1 mes desde el primer día hábil del mes siguiente' : '3 meses desde el primer día hábil del periodo siguiente'));
            }
        }
        foreach ($yearBooks as $code) {
            [$label, $rule] = self::CATALOG[$code];
            if ($rule === 'annual') {
                $add($code, $label, 'books', (string) $year, $this->fromAnchor(CarbonImmutable::create($year + 1, 1, 1, 0, 0, 0, 'UTC'), 'quarter'),
                    $code === '3' ? self::AMENDMENT : self::ANNEX, '3 meses desde el día hábil siguiente al cierre del ejercicio');
            } elseif ($rule === 'trigger') {
                $add($code, $label, 'books', (string) $year, null, self::ANNEX, '10 días hábiles desde el ingreso o retiro de bienes del molino');
            }
        }
        if (array_intersect($regimes, ['GENERAL', 'MYPE_TRIBUTARIO', 'MYPE']) !== []) {
            $annualGroup = $profile['annual_good'] ? 10 : (int) $digit;
            $variant = $profile['annual_schedule'];
            $annualDate = $variant === 'UNCONFIRMED' ? null : ($data['annual'][strtolower($variant)][$annualGroup] ?? null);
            $add('ANNUAL', 'Declaración y regularización anual de Renta (FV 710)', 'tax', (string) $year, $annualDate,
                $data['annual']['source'] ?? 'https://renta.sunat.gob.pe/empresas',
                $variant === 'UNCONFIRMED' ? 'Confirme qué cronograma anual aplica' : 'Cronograma anual '.($variant === 'EXTENDED' ? 'ampliado' : 'general'));
        }
        foreach ($profile['overrides'] as $override) {
            $id = $override['code'].':'.$override['period'];
            if (isset($events[$id])) {
                $events[$id]['date'] = $override['date'];
                $events[$id]['basis'] = 'Fecha particular registrada por la empresa · '.$override['reference'];
            } else {
                $add($override['code'], $override['label'], $override['type'], $override['period'], $override['date'], '',
                    'Fecha registrada por la empresa · '.$override['reference']);
            }
        }
        uasort($events, static fn (array $a, array $b) => [$a['date'] ?? '9999', $a['period'], $a['label']] <=> [$b['date'] ?? '9999', $b['period'], $b['label']]);

        return array_values($events);
    }

    private function booksFor(?string $regime, array $profile): array
    {
        $books = match ($regime) {
            'NRUS', null => [],
            'ESPECIAL', 'RER' => ['8', '14'],
            'GENERAL', 'MYPE_TRIBUTARIO', 'MYPE' => match ($profile['income_band']) {
                'UP_TO_300' => ['8', '14', '5-A'],
                'UP_TO_500' => ['8', '14', '5', '6'],
                'UP_TO_1700' => ['8', '14', '5', '6', '3'],
                'OVER_1700' => ['8', '14', '5', '6', '3', '1'],
                default => ['8', '14'],
            },
            default => [],
        };
        return array_values(array_diff(array_unique([...$books, ...$profile['extra_books']]), $profile['excluded_books']));
    }

    private function fromAnchor(CarbonImmutable $anchor, string $rule): string
    {
        $date = $this->workingDay($anchor);
        if ($rule === 'ten') {
            for ($offset = 1; $offset < 10; $offset++) {
                $date = $this->workingDay($date->addDay());
            }
            return $date->toDateString();
        }
        return $this->workingDay($date->addMonthsNoOverflow($rule === 'month' ? 1 : 3))->toDateString();
    }

    public function workingDay(CarbonImmutable $date): CarbonImmutable
    {
        $fixed = ['01-01', '05-01', '06-07', '06-29', '07-23', '07-28', '07-29', '08-06',
            '08-30', '10-08', '11-01', '12-08', '12-09', '12-25'];
        $movable = ['2025-04-17','2025-04-18','2026-04-02','2026-04-03','2027-03-25','2027-03-26'];
        while ($date->isWeekend() || in_array($date->format('m-d'), $fixed, true)
            || in_array($date->toDateString(), $movable, true)) {
            $date = $date->addDay();
        }
        return $date;
    }
}
