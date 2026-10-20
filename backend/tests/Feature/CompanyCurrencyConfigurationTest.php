<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\AccountingAccount;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanySetting;
use App\Models\CompanyUser;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Support\Tenant\TenantContext;

beforeEach(function (): void {
    $this->tenantContext = app(TenantContext::class);

    $this->company = Company::query()->firstOrCreate(
        ['tax_id' => '20601234567'],
        [
            'business_name' => 'Corporación Peruana de Distribución S.A.C.',
            'legal_address' => 'Av. Javier Prado Este 2450, San Borja, Lima',
            'is_active' => true,
        ]
    );

    $this->user = User::query()->firstOrCreate(
        ['email' => 'contador@corporacionperu.pe'],
        [
            'name' => 'Contador General CPC',
            'password' => 'Contabilidad2026!',
        ]
    );

    $this->tenantContext->run($this->company->id, function (): void {
        CompanyUser::query()->firstOrCreate(
            [
                'company_id' => $this->company->id,
                'user_id' => $this->user->id,
            ],
            [
                'role' => CompanyRole::ChiefAccountant,
                'is_active' => true,
            ]
        );

        $this->user->forceFill(['current_company_id' => $this->company->id])->save();
    });
});

test('puede consultar los datos y configuración de monedas de la empresa activa', function (): void {
    $response = $this->actingAs($this->user)
        ->withHeader('X-Company-ID', (string) $this->company->id)
        ->getJson('/api/v1/company/settings');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'company' => ['id', 'tax_id', 'business_name', 'legal_address'],
                'settings' => ['id', 'base_currency_id', 'tax_regime'],
                'company_currencies' => [
                    '*' => ['id', 'company_id', 'currency_id', 'is_base', 'currency'],
                ],
                'all_currencies' => [
                    '*' => ['id', 'code', 'name', 'symbol'],
                ],
            ],
        ]);
});

test('puede actualizar los datos de la empresa y configurar más de 2 monedas de trabajo', function (): void {
    $pen = Currency::query()->where('code', 'PEN')->firstOrFail();
    $usd = Currency::query()->where('code', 'USD')->firstOrFail();
    $eur = Currency::query()->where('code', 'EUR')->firstOrFail();

    // Configurar PEN como moneda base y USD + EUR como monedas secundarias
    $response = $this->actingAs($this->user)
        ->withHeader('X-Company-ID', (string) $this->company->id)
        ->putJson('/api/v1/company/settings', [
            'business_name' => 'Corporación Peruana de Distribución Multimoneda S.A.C.',
            'legal_address' => 'Calle Las Camelias 890, San Isidro, Lima',
            'base_currency_id' => $pen->id,
            'secondary_currency_ids' => [$usd->id, $eur->id],
            'tax_regime' => 'GENERAL',
            'default_vat_rate' => 18.00,
        ]);

    $response->assertOk()
        ->assertJsonPath('data.company.business_name', 'Corporación Peruana de Distribución Multimoneda S.A.C.');

    // Verificar en base de datos bajo TenantContext
    $currencies = $this->tenantContext->run($this->company->id, function () {
        return CompanyCurrency::query()
            ->where('company_id', $this->company->id)
            ->where('is_active', true)
            ->get();
    });

    expect($currencies)->toHaveCount(3);
    expect($currencies->firstWhere('currency_id', $pen->id)->is_base)->toBeTrue();
    expect($currencies->firstWhere('currency_id', $usd->id)->is_base)->toBeFalse();
    expect($currencies->firstWhere('currency_id', $eur->id)->is_base)->toBeFalse();
});

test('al registrar un asiento contable es obligatorio el tipo de cambio para cada moneda secundaria de la empresa', function (): void {
    $pen = Currency::query()->where('code', 'PEN')->firstOrFail();
    $usd = Currency::query()->where('code', 'USD')->firstOrFail();
    $eur = Currency::query()->where('code', 'EUR')->firstOrFail();

    // Asegurar que la empresa tenga USD y EUR como monedas secundarias
    $this->tenantContext->run($this->company->id, function () use ($pen, $usd, $eur): void {
        CompanyCurrency::query()->where('company_id', $this->company->id)->delete();

        CompanyCurrency::query()->create([
            'company_id' => $this->company->id,
            'currency_id' => $pen->id,
            'is_base' => true,
            'is_active' => true,
        ]);

        CompanyCurrency::query()->create([
            'company_id' => $this->company->id,
            'currency_id' => $usd->id,
            'is_base' => false,
            'is_active' => true,
        ]);

        CompanyCurrency::query()->create([
            'company_id' => $this->company->id,
            'currency_id' => $eur->id,
            'is_base' => false,
            'is_active' => true,
        ]);

        // Limpiar tipos de cambio en esa fecha de prueba
        ExchangeRate::query()
            ->where('company_id', $this->company->id)
            ->where('rate_date', '2026-10-25')
            ->delete();
    });

    // Obtener dos cuentas operativas bajo TenantContext
    [$acc1041, $acc1212] = $this->tenantContext->run($this->company->id, function () {
        $acc1 = AccountingAccount::query()->where('company_id', $this->company->id)->where('code', '1041')->first();
        $acc2 = AccountingAccount::query()->where('company_id', $this->company->id)->where('code', '1212')->first();

        if ($acc1 === null || $acc2 === null) {
            $acc1 = AccountingAccount::query()->where('company_id', $this->company->id)->where('is_operational', true)->first();
            $acc2 = AccountingAccount::query()->where('company_id', $this->company->id)->where('is_operational', true)->where('id', '<>', $acc1?->id)->first();
        }

        return [$acc1, $acc2];
    });

    // Intento 1: Sin proporcionar tipo de cambio para EUR (solo USD) -> Debe fallar con 422
    $failResponse = $this->actingAs($this->user)
        ->withHeader('X-Company-ID', (string) $this->company->id)
        ->postJson('/api/v1/accounting/journal-entries', [
            'entry_date' => '2026-10-25',
            'currency' => 'PEN',
            'description' => 'Asiento de prueba sin TC para EUR',
            'exchange_rates' => [
                [
                    'currency' => 'USD',
                    'rate' => 3.750,
                    'buy_rate' => 3.740,
                    'sell_rate' => 3.750,
                ],
            ],
            'lines' => [
                [
                    'accounting_account_id' => $acc1041->id,
                    'description' => 'Debe',
                    'debit_local' => 1000.00,
                    'credit_local' => 0.00,
                    'debit_foreign' => 266.67,
                    'credit_foreign' => 0.00,
                ],
                [
                    'accounting_account_id' => $acc1212->id,
                    'description' => 'Haber',
                    'debit_local' => 0.00,
                    'credit_local' => 1000.00,
                    'debit_foreign' => 0.00,
                    'credit_foreign' => 266.67,
                ],
            ],
        ]);

    $failResponse->assertStatus(422)
        ->assertJsonFragment(['missing_currency' => 'EUR']);

    // Intento 2: Con tipos de cambio para TODAS las monedas secundarias (USD y EUR) -> Debe crearse con 201
    $successResponse = $this->actingAs($this->user)
        ->withHeader('X-Company-ID', (string) $this->company->id)
        ->postJson('/api/v1/accounting/journal-entries', [
            'entry_date' => '2026-10-25',
            'currency' => 'PEN',
            'description' => 'Asiento de prueba con todos los TC requeridos',
            'exchange_rates' => [
                [
                    'currency' => 'USD',
                    'rate' => 3.750,
                    'buy_rate' => 3.740,
                    'sell_rate' => 3.750,
                ],
                [
                    'currency' => 'EUR',
                    'rate' => 4.100,
                    'buy_rate' => 4.080,
                    'sell_rate' => 4.100,
                ],
            ],
            'lines' => [
                [
                    'accounting_account_id' => $acc1041->id,
                    'description' => 'Debe',
                    'debit_local' => 1000.00,
                    'credit_local' => 0.00,
                    'debit_foreign' => 266.67,
                    'credit_foreign' => 0.00,
                ],
                [
                    'accounting_account_id' => $acc1212->id,
                    'description' => 'Haber',
                    'debit_local' => 0.00,
                    'credit_local' => 1000.00,
                    'debit_foreign' => 0.00,
                    'credit_foreign' => 266.67,
                ],
            ],
        ]);

    $successResponse->assertStatus(201)
        ->assertJsonPath('data.status', 'POSTED');

    // Verificar que los tipos de cambio se guardaron en la tabla exchange_rates bajo TenantContext
    [$usdRate, $eurRate] = $this->tenantContext->run($this->company->id, function () {
        $usd = ExchangeRate::query()
            ->where('company_id', $this->company->id)
            ->where('rate_date', '2026-10-25')
            ->where('source_currency', 'USD')
            ->first();

        $eur = ExchangeRate::query()
            ->where('company_id', $this->company->id)
            ->where('rate_date', '2026-10-25')
            ->where('source_currency', 'EUR')
            ->first();

        return [$usd, $eur];
    });

    expect($usdRate)->not->toBeNull();
    expect($eurRate)->not->toBeNull();
});
