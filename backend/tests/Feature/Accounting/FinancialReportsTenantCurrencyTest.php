<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Currency;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

// DatabaseTransactions comes from tests/Pest.php (no permanent test tenants).
beforeEach(function (): void {
    $nonce = bin2hex(random_bytes(5));
    $this->company = Company::query()->create([
        'tax_id' => '20'.(string) random_int(100000000, 999999999),
        'business_name' => 'DEMO reportes moneda funcional '.$nonce,
        'legal_address' => 'Calle de prueba 100',
        'is_active' => true,
    ]);
    $this->user = User::query()->create([
        'name' => 'Test Contable Reportes',
        'email' => 'financial_report_'.$nonce.'@example.test',
        'password' => 'Solo-uso-pruebas-2026!',
    ]);
    app(TenantContext::class)->run($this->company->id, function (): void {
        CompanyUser::query()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'role' => CompanyRole::ChiefAccountant,
            'is_active' => true,
        ]);
    });
    $this->user->update(['current_company_id' => $this->company->id]);
    Sanctum::actingAs($this->user, ['*']);
});

it('reports an empty company in its configured CLP functional currency, not assumed PEN', function (): void {
    $clp = Currency::query()->where('code', 'CLP')->firstOrFail();
    app(TenantContext::class)->run($this->company->id, function () use ($clp): void {
        DB::table('company_settings')->insert([
            'company_id' => $this->company->id,
            'base_currency_id' => $clp->id,
            'tax_regime' => 'GENERAL',
            'default_vat_rate' => '18.00',
        ]);
    });

    $this->getJson(
        '/api/v1/accounting/reports/financial-snapshot?as_of=2026-10-26',
        ['X-Company-Id' => (string) $this->company->id]
    )->assertOk()
        ->assertJsonPath('data.currency', 'CLP')
        ->assertJsonPath('data.totals.assets', '0.0000')
        ->assertJsonPath('data.totals.trial_difference', '0.0000')
        ->assertJsonPath('data.income_statement.net', '0.0000')
        ->assertJsonCount(0, 'data.accounts');
});

it('fails closed for companies without a functional currency and denies foreign-tenant reports', function (): void {
    $this->getJson(
        '/api/v1/accounting/reports/financial-snapshot?as_of=2026-10-26',
        ['X-Company-Id' => (string) $this->company->id]
    )->assertStatus(409);

    $other = Company::query()->create([
        'tax_id' => '20'.(string) random_int(100000000, 999999999),
        'business_name' => 'DEMO ajena de reportes '.bin2hex(random_bytes(5)),
        'legal_address' => 'Calle de prueba 200',
        'is_active' => true,
    ]);

    // The signed-in user has no membership in this company.
    $headers = ['X-Company-Id' => (string) $other->id];
    $this->getJson('/api/v1/accounting/reports/financial-snapshot?as_of=2026-10-26', $headers)
        ->assertForbidden();
    $this->getJson('/api/v1/accounting/reports/general-ledger?account_id=1&from=2026-01-01&to=2026-10-26', $headers)
        ->assertForbidden();
});
