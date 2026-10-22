<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrenciesSeeder extends Seeder
{
    private bool $bulkGlobalInstall = false;

    /** Only the F2 guarded installer may request insert-only, batched loading. */
    public function runForGlobalInstall(): void
    {
        if ($this->bulkGlobalInstall) {
            throw new \RuntimeException('Carga global ya iniciada.');
        }

        $this->bulkGlobalInstall = true;
        try {
            $this->run();
        } finally {
            $this->bulkGlobalInstall = false;
        }
    }

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

        if ($this->bulkGlobalInstall) {
            \App\Support\Reference\GlobalReferenceBatchWriter::insert(Currency::class, $currencies);
            return;
        }

        foreach ($currencies as $currency) {
            Currency::query()->updateOrCreate(
                ['code' => $currency['code']],
                $currency
            );
        }
    }
}
