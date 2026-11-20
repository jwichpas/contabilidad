<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\TaxCalendarDeadline;
use App\Services\Accounting\SunatCalendar;
use Illuminate\Database\Seeder;

final class TaxCalendarDeadlinesSeeder extends Seeder
{
    public function run(SunatCalendar $calendar): void
    {
        $rows = [];
        $now = now();
        foreach (glob(database_path('reference_data/sunat_due_dates_*.json')) as $path) {
            $edition = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            foreach ($edition['tax'] as $month => $taxDates) {
                foreach ([...array_map('strval', range(0, 9)), 'BC'] as $digit) {
                    $group = $calendar->group($digit);
                    $rows[] = [
                        'fiscal_year' => $edition['year'], 'fiscal_month' => $month, 'last_digit_ruc' => $digit,
                        'electronic_books_deadline' => $edition['books'][$month][$group],
                        'tax_returns_deadline' => $taxDates[$group], 'legal_resolution' => SunatCalendar::RESOLUTION,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
        }

        TaxCalendarDeadline::query()->upsert($rows, ['fiscal_year', 'fiscal_month', 'last_digit_ruc'],
            ['electronic_books_deadline', 'tax_returns_deadline', 'legal_resolution', 'updated_at']);
    }
}
