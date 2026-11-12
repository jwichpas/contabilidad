<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyUser;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\ExchangeRates\SunatCurrentExchangeRateClient;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->tenant = app(TenantContext::class);
    $this->company = Company::query()->create([
        'tax_id' => '20'.random_int(100000000, 999999999),
        'business_name' => 'SUNAT FX Test Company',
        'legal_address' => 'Fixture', 'is_active' => true,
    ]);
    $this->user = User::query()->create([
        'name' => 'SUNAT FX Tester',
        'email' => 'fx_'.bin2hex(random_bytes(6)).'@example.test',
        'password' => 'Test-Only-FX-123',
    ]);
    $pen = Currency::query()->firstOrCreate(['code' => 'PEN'], [
        'sunat_code' => '01', 'name' => 'Sol', 'symbol' => 'S/',
        'is_base_currency' => true, 'is_active' => true,
    ]);
    $usd = Currency::query()->firstOrCreate(['code' => 'USD'], [
        'sunat_code' => '02', 'name' => 'Dólar', 'symbol' => '$',
        'is_base_currency' => false, 'is_active' => true,
    ]);

    $this->tenant->run((int) $this->company->id, function () use ($pen, $usd): void {
        CompanyUser::query()->create([
            'user_id' => $this->user->id, 'company_id' => $this->company->id,
            'role' => 'ADMIN', 'is_active' => true,
        ]);
        foreach ([$pen, $usd] as $currency) {
            CompanyCurrency::query()->create([
                'currency_id' => $currency->id,
                'is_base' => $currency->code === 'PEN',
                'is_active' => true,
            ]);
        }
    });
    $this->user->update(['current_company_id' => $this->company->id]);
    $this->actingAs($this->user)->withHeader('X-Company-Id', (string) $this->company->id);
});

it('imports exact SUNAT USD/PEN quote without overwriting it on repeated requests', function (): void {
    Http::fake([
        SunatCurrentExchangeRateClient::SOURCE_URL => Http::response(
            '12/11/2026|3.407|3.414|', 200, ['Content-Type' => 'text/plain']
        ),
    ]);

    $this->postJson('/api/v1/company/exchange-rates/import-sunat', [
        'rate_date' => '2026-11-12',
    ])->assertCreated()
        ->assertJsonPath('data.status', 'imported')
        ->assertJsonPath('data.buy_rate', '3.407000')
        ->assertJsonPath('data.sell_rate', '3.414000');

    $this->postJson('/api/v1/company/exchange-rates/import-sunat', [
        'rate_date' => '2026-11-12',
    ])->assertOk()->assertJsonPath('data.status', 'existing');

    $this->getJson('/api/v1/company/exchange-rates?date=2026-11-12')
        ->assertOk()
        ->assertJsonPath('data.required_currencies.0.is_recorded_for_date', true)
        ->assertJsonPath('data.required_currencies.0.sell_rate', '3.414000');
    Http::assertSentCount(1);
});

it('rejects a historical date without using the only current SUNAT quote', function (): void {
    Http::fake([
        SunatCurrentExchangeRateClient::SOURCE_URL => Http::response('12/11/2026|3.407|3.414|'),
    ]);
    $this->postJson('/api/v1/company/exchange-rates/import-sunat', [
        'rate_date' => '2026-11-11',
    ])->assertStatus(409)
        ->assertJsonPath('data.status', 'date_mismatch')
        ->assertJsonPath('data.published_date', '2026-11-12');

    $count = $this->tenant->run((int) $this->company->id,
        fn (): int => ExchangeRate::query()->count());
    expect($count)->toBe(0);
});

it('preserves the recorded manual rate and never calls SUNAT again', function (): void {
    $this->tenant->run((int) $this->company->id, function (): void {
        ExchangeRate::query()->create([
            'rate_date' => '2026-11-12', 'source_currency' => 'USD',
            'target_currency' => 'PEN', 'buy_rate' => '3.500000',
            'sell_rate' => '3.510000',
        ]);
    });
    Http::fake([
        SunatCurrentExchangeRateClient::SOURCE_URL => Http::response('12/11/2026|3.407|3.414|'),
    ]);

    $this->postJson('/api/v1/company/exchange-rates/import-sunat', [
        'rate_date' => '2026-11-12',
    ])->assertOk()
        ->assertJsonPath('data.status', 'existing')
        ->assertJsonPath('data.sell_rate', '3.510000');
    Http::assertNothingSent();
});

it('fails closed when SUNAT is unavailable or the payload is not a valid quote', function (): void {
    Http::fake([
        SunatCurrentExchangeRateClient::SOURCE_URL => Http::response('<html>error</html>', 200),
    ]);
    $this->postJson('/api/v1/company/exchange-rates/import-sunat', [
        'rate_date' => '2026-11-12',
    ])->assertStatus(503)->assertJsonPath('data.status', 'unavailable');
    expect($this->tenant->run((int) $this->company->id,
        fn (): int => ExchangeRate::query()->count()))->toBe(0);
});

it('rejects forged company IDs and user-supplied remote URLs', function (): void {
    $this->postJson('/api/v1/company/exchange-rates/import-sunat', [
        'rate_date' => '2026-11-12', 'company_id' => 9999,
        'source_url' => 'http://127.0.0.1/private',
    ])->assertUnprocessable()->assertJsonValidationErrors(['company_id', 'source_url']);
});
