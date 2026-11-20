<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

// Uses the current DEV database transaction fixture from Pest.php.
beforeEach(function (): void {
    $this->tenant = app(TenantContext::class);
    $this->company = Company::query()->create([
        'tax_id' => '20'.random_int(100000000, 999999999),
        'business_name' => 'Configuración EFE tenant A', 'legal_address' => 'DEV', 'is_active' => true,
    ]);
    $this->user = User::query()->create([
        'name' => 'EFE configuration test',
        'email' => 'efe_'.bin2hex(random_bytes(7)).'@example.test',
        'password' => 'Test-Only-2026!',
    ]);
    $this->tenant->run($this->company->id, function (): void {
        DB::table('company_user')->insert([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'role' => 'ADMIN', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });
    $this->user->update(['current_company_id' => $this->company->id]);
    Sanctum::actingAs($this->user, ['*']);
    $this->withHeader('X-Company-Id', (string) $this->company->id);
});

function sampleFlowSettings(): array
{
    return [
        'hide_zero' => true,
        'labels' => ['1102' => 'Cobros por ventas del negocio'],
        'hidden_codes' => ['1105'],
        'custom_lines' => [[
            'code' => 'C0001', 'label' => 'Cobros especiales internos',
            'section' => 'OPERATING', 'direction' => 'INFLOW',
        ]],
        'account_rules' => [],
        'area_rules' => [],
    ];
}

it('returns global codes but stores and audits settings only for the selected company', function (): void {
    $path = '/api/v1/company/cash-flow-presentation';
    $this->getJson($path)->assertOk()
        ->assertJsonPath('data.revision', 0)
        ->assertJsonPath('standard.1102.label', 'Venta de bienes y prestación de servicios')
        ->assertJsonCount(0, 'history');

    $this->putJson($path, ['expected_revision' => 0, 'settings' => sampleFlowSettings()])
        ->assertOk()->assertJsonPath('data.revision', 1)
        ->assertJsonPath('data.settings.labels.1102', 'Cobros por ventas del negocio');

    $this->getJson($path)->assertOk()
        ->assertJsonPath('data.revision', 1)
        ->assertJsonPath('data.settings.custom_lines.0.code', 'C0001')
        ->assertJsonCount(1, 'history');

    $this->putJson($path, ['expected_revision' => 0, 'settings' => sampleFlowSettings()])
        ->assertStatus(409);

    $this->tenant->run($this->company->id, function (): void {
        expect(DB::table('company_cash_flow_presentation_revisions')->count())->toBe(1);
    });

    $unrelated = Company::query()->create([
        'tax_id' => '20'.random_int(100000000, 999999999),
        'business_name' => 'Tenant B EFE', 'legal_address' => 'DEV', 'is_active' => true,
    ]);
    $this->getJson($path, ['X-Company-Id' => (string) $unrelated->id])->assertForbidden();
    $this->putJson($path, ['expected_revision' => 0, 'settings' => sampleFlowSettings()],
        ['X-Company-Id' => (string) $unrelated->id])->assertForbidden();
});

it('enforces accounting roles and rejects attempts to edit fixed totals', function (): void {
    $path = '/api/v1/company/cash-flow-presentation';
    $settings = sampleFlowSettings();
    $settings['labels']['1301'] = 'Modificar subtotal';
    $this->putJson($path, ['expected_revision' => 0, 'settings' => $settings])
        ->assertUnprocessable();

    $settings = sampleFlowSettings();
    $settings['custom_lines'][0]['code'] = '1102';
    $this->putJson($path, ['expected_revision' => 0, 'settings' => $settings])
        ->assertUnprocessable();

    $this->tenant->run($this->company->id, function (): void {
        DB::table('company_user')->where('company_id', $this->company->id)
            ->where('user_id', $this->user->id)->update(['role' => 'ACCOUNTANT']);
    });
    $this->putJson($path, ['expected_revision' => 0, 'settings' => sampleFlowSettings()])
        ->assertForbidden();
    $this->getJson($path)->assertOk()->assertJsonPath('data.revision', 0);
});
