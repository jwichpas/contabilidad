<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrenciesSeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            [
                'code' => 'PEN',
                'sunat_code' => '1',
                'name' => 'Soles',
                'symbol' => 'S/',
                'decimal_places' => 2,
                'accounting_precision' => 4,
                'is_base_currency' => true,
                'is_active' => true,
            ],
            [
                'code' => 'USD',
                'sunat_code' => '2',
                'name' => 'Dólares Americanos',
                'symbol' => '$',
                'decimal_places' => 2,
                'accounting_precision' => 4,
                'is_base_currency' => false,
                'is_active' => true,
            ],
            [
                'code' => 'EUR',
                'sunat_code' => '9',
                'name' => 'Euros',
                'symbol' => '€',
                'decimal_places' => 2,
                'accounting_precision' => 4,
                'is_base_currency' => false,
                'is_active' => true,
            ],
        ];

        foreach ($currencies as $currency) {
            Currency::query()->updateOrCreate(
                ['code' => $currency['code']],
                $currency
            );
        }
    }
}
