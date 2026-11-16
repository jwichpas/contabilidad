<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->tenant = app(TenantContext::class);
    $this->company = Company::query()->create([
        'tax_id' => '20'.random_int(100000000,999999999),
        'business_name' => 'Empresa A - diseño ESF ERI', 'legal_address' => 'Laboratorio',
        'is_active' => true,
    ]);
    $this->user = User::query()->create([
        'name' => 'Administrador diseñador EEFF',
        'email' => 'layout_'.bin2hex(random_bytes(7)).'@example.test',
        'password' => 'testing-only-2026!',
    ]);
    $this->tenant->run((int) $this->company->id, function (): void {
        DB::table('company_user')->insert([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id, 'role' => 'ADMIN', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });
    $this->user->update(['current_company_id' => $this->company->id]);
    Sanctum::actingAs($this->user, ['*']);
    $this->withHeader('X-Company-Id',(string) $this->company->id);
});

function incomeLayoutSettings(): array
{
    return [
        'labels' => ['sales' => 'Ventas empresa A'],
        'hidden_codes' => ['discounts'],
        'line_order' => ['discounts', 'sales'],
        'hide_zero' => true,
    ];
}

it('keeps company-specific and statement-specific layouts with immutable revisions', function (): void {
    $eri = '/api/v1/company/financial-layout/ERI';
    $esf = '/api/v1/company/financial-layout/ESF';
    $this->getJson($eri)->assertOk()
        ->assertJsonPath('data.revision',0)->assertJsonPath('catalog.sales.kind','detail')
        ->assertJsonPath('catalog.net_profit.kind','protected');
    $this->putJson($eri, ['expected_revision'=>0, 'settings'=>incomeLayoutSettings()])
        ->assertOk()->assertJsonPath('data.revision',1)
        ->assertJsonPath('data.settings.labels.sales','Ventas empresa A');
    $this->getJson($eri)->assertOk()
        ->assertJsonPath('data.revision',1)->assertJsonCount(1,'history');
    $this->getJson($esf)->assertOk()
        ->assertJsonPath('data.revision',0)
        ->assertJsonCount(0,'history');
    $this->putJson($eri, ['expected_revision'=>0,'settings'=>incomeLayoutSettings()])
        ->assertStatus(409);
    $this->tenant->run((int) $this->company->id, function (): void {
        expect(DB::table('company_financial_report_layout_revisions')->count())->toBe(1);
    });
});

it('protects formulas, subtotal codes and disclosure warnings', function (): void {
    $route = '/api/v1/company/financial-layout/ERI';
    $settings = incomeLayoutSettings();
    $settings['labels']['net_profit'] = 'Cambio al resultado';
    $this->putJson($route, ['expected_revision'=>0,'settings'=>$settings])->assertUnprocessable();
    $settings = incomeLayoutSettings();
    $settings['hidden_codes'][] = 'total_comprehensive';
    $this->putJson($route, ['expected_revision'=>0,'settings'=>$settings])->assertUnprocessable();
    $settings = incomeLayoutSettings();
    $settings['line_order'][] = 'gross_profit';
    $this->putJson($route, ['expected_revision'=>0,'settings'=>$settings])->assertUnprocessable();
    $this->getJson('/api/v1/company/financial-layout/ECP')->assertStatus(409);
});

it('restricts write access and rejects impersonation of another company', function (): void {
    $path = '/api/v1/company/financial-layout/ERI';
    $companyB = Company::query()->create([
        'tax_id' => '20'.random_int(100000000,999999999),
        'business_name' => 'Empresa B - independiente', 'legal_address' => 'Otro domicilio',
        'is_active' => true,
    ]);
    $this->getJson($path, ['X-Company-Id' => (string) $companyB->id])->assertForbidden();
    $this->putJson($path, ['expected_revision'=>0,'settings'=>incomeLayoutSettings()],
        ['X-Company-Id' => (string) $companyB->id])->assertForbidden();
    $this->tenant->run((int) $this->company->id, function (): void {
        DB::table('company_user')->where('company_id',$this->company->id)
            ->where('user_id',$this->user->id)->update(['role'=>'ACCOUNTANT']);
    });
    $this->putJson($path, ['expected_revision'=>0,'settings'=>incomeLayoutSettings()])
        ->assertForbidden();
    $this->getJson($path)->assertOk();
});
