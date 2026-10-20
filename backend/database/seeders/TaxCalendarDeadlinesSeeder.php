<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\TaxCalendarDeadline;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Seeder del Cronograma Oficial de Vencimientos SUNAT para el Ejercicio 2026.
 * Incluye:
 * - Libros Electrónicos / SIRE (Registro de Ventas RVIE y Registro de Compras RCE).
 * - Declaraciones y Pagos Mensuales (PDT 621 IGV-Renta, PLAME Planilla Electrónica).
 * Cubre los dígitos 0 al 9 y Buenos Contribuyentes (BC) para los 12 periodos mensuales.
 */
class TaxCalendarDeadlinesSeeder extends Seeder
{
    public function run(): void
    {
        $year = 2026;
        $resolution = 'R.S. N.° 000281-2025/SUNAT';

        // Definición base de fechas de inicio de vencimientos mensuales para el siguiente mes calendario:
        // Periodo fiscal => [Año de vencimiento, Mes de vencimiento, Día base para dígito 0]
        $scheduleBases = [
            1  => ['year' => 2026, 'month' => 2,  'base_day' => 13], // Enero -> Feb 2026
            2  => ['year' => 2026, 'month' => 3,  'base_day' => 13], // Febrero -> Mar 2026
            3  => ['year' => 2026, 'month' => 4,  'base_day' => 14], // Marzo -> Abr 2026
            4  => ['year' => 2026, 'month' => 5,  'base_day' => 14], // Abril -> May 2026
            5  => ['year' => 2026, 'month' => 6,  'base_day' => 12], // Mayo -> Jun 2026
            6  => ['year' => 2026, 'month' => 7,  'base_day' => 14], // Junio -> Jul 2026
            7  => ['year' => 2026, 'month' => 8,  'base_day' => 13], // Julio -> Ago 2026
            8  => ['year' => 2026, 'month' => 9,  'base_day' => 14], // Agosto -> Set 2026
            9  => ['year' => 2026, 'month' => 10, 'base_day' => 13], // Setiembre -> Oct 2026
            10 => ['year' => 2026, 'month' => 11, 'base_day' => 13], // Octubre -> Nov 2026
            11 => ['year' => 2026, 'month' => 12, 'base_day' => 14], // Noviembre -> Dic 2026
            12 => ['year' => 2027, 'month' => 1,  'base_day' => 14], // Diciembre -> Ene 2027
        ];

        // Secuencia de dígitos SUNAT y días hábiles de desfase
        $digitOffsets = [
            '0'  => 0,
            '1'  => 1,
            '2'  => 2,
            '3'  => 3,
            '4'  => 4,
            '5'  => 5,
            '6'  => 6,
            '7'  => 7,
            '8'  => 8,
            '9'  => 9,
            'BC' => 10, // Buenos Contribuyentes
        ];

        $records = [];
        $now = now();

        foreach ($scheduleBases as $fiscalMonth => $base) {
            $baseDate = CarbonImmutable::create($base['year'], $base['month'], $base['base_day']);

            foreach ($digitOffsets as $digit => $offset) {
                // Avanzar considerando sólo días hábiles (evitando sábados y domingos)
                $current = $baseDate;
                $daysAdded = 0;
                while ($daysAdded < $offset) {
                    $current = $current->addDay();
                    if (! $current->isWeekend()) {
                        $daysAdded++;
                    }
                }

                // Si la fecha cae en fin de semana, trasladar al siguiente lunes hábil
                if ($current->isWeekend()) {
                    $current = $current->next(CarbonImmutable::MONDAY);
                }

                $taxReturnDeadline = $current;

                // Para SIRE / Libros Electrónicos, la fecha límite es 1 día hábil antes de la declaración
                $booksDeadline = $taxReturnDeadline->subDay();
                if ($booksDeadline->isWeekend()) {
                    $booksDeadline = $booksDeadline->previous(CarbonImmutable::FRIDAY);
                }

                $records[] = [
                    'fiscal_year' => $year,
                    'fiscal_month' => $fiscalMonth,
                    'last_digit_ruc' => $digit,
                    'electronic_books_deadline' => $booksDeadline->format('Y-m-d'),
                    'tax_returns_deadline' => $taxReturnDeadline->format('Y-m-d'),
                    'legal_resolution' => $resolution,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        TaxCalendarDeadline::query()->upsert(
            $records,
            ['fiscal_year', 'fiscal_month', 'last_digit_ruc'],
            ['electronic_books_deadline', 'tax_returns_deadline', 'legal_resolution', 'updated_at']
        );
    }
}
