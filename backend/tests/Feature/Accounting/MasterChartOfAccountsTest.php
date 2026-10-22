<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\AccountingAccount;
use App\Models\AccountingAccountTemplate;
use App\Models\Company;
use App\Models\CompanyUser;
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

test('el catálogo matriz de plantillas reporta las versiones PCGE 2019 y PCGE 2026', function (): void {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/catalogs/chart-of-accounts-templates');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'plan_version',
                    'name',
                    'is_current_default',
                    'total_accounts',
                    'min_level',
                    'max_level',
                ],
            ],
        ]);

    $data = $response->json('data');
    expect($data)->toBeArray()
        ->and(count($data))->toBeGreaterThanOrEqual(2);

    $versions = array_column($data, 'plan_version');
    expect($versions)->toContain('PCGE_2019')
        ->and($versions)->toContain('PCGE_2026');
});

test('se puede consultar la lista de cuentas de la plantilla PCGE 2019 para previsualización', function (): void {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/catalogs/chart-of-accounts-templates?version=PCGE_2019');

    $response->assertOk()
        ->assertJsonPath('data.plan_version', 'PCGE_2019')
        ->assertJsonStructure([
            'data' => [
                'plan_version',
                'total_accounts',
                'accounts' => [
                    '*' => [
                        'id',
                        'plan_version',
                        'code',
                        'name',
                        'level',
                        'account_type',
                        'balance_type',
                        'currency_mode',
                        'is_operational',
                    ],
                ],
            ],
        ]);

    $total = $response->json('data.total_accounts');
    expect($total)->toBe(1764);
});

test('una empresa puede inicializar su plan contable PCGE 2019 de forma aislada e independiente', function (): void {
    // 1. Crear una nueva empresa secundaria para probar el clonado sin interferencia
    $newCompany = Company::query()->create([
        'tax_id' => '20778899001',
        'business_name' => 'Inversiones y Servicios del Sur S.A.C.',
        'legal_address' => 'Calle Mercaderes 120, Arequipa',
        'is_active' => true,
    ]);

    $this->tenantContext->run($newCompany->id, function () use ($newCompany): void {
        CompanyUser::query()->create([
            'company_id' => $newCompany->id,
            'user_id' => $this->user->id,
            'role' => CompanyRole::ChiefAccountant,
            'is_active' => true,
        ]);
    });

    // Conectar al usuario a la nueva empresa
    $this->user->forceFill(['current_company_id' => $newCompany->id])->save();

    // 2. Inicializar el plan contable para la nueva empresa vía endpoint
    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/company/chart-of-accounts/initialize', [
            'plan_version' => 'PCGE_2019',
            'force' => false,
        ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.company_id', $newCompany->id)
        ->assertJsonPath('data.plan_version', 'PCGE_2019')
        ->assertJsonPath('data.total_accounts', 2955);

    // 3. Verificar que las cuentas de la nueva empresa existen en accounting_accounts
    $this->tenantContext->run($newCompany->id, function () use ($newCompany): void {
        $count = AccountingAccount::query()->count();
        expect($count)->toBe(2955);

        $posting = AccountingAccount::query()->where('is_operational', true)->get(['code']);
        expect($posting)->toHaveCount(1212)
            ->and($posting->every(static fn ($acc) => strlen((string) $acc->code) === 6))->toBeTrue();

        // Modificar una cuenta propia de la empresa nueva
        $acc1041 = AccountingAccount::query()->where('code', '1041')->first();
        expect($acc1041)->not->toBeNull();

        $acc1041->update(['name' => 'Cuentas Corrientes BCP Personalizadas']);
        expect($acc1041->fresh()->name)->toBe('Cuentas Corrientes BCP Personalizadas');
    });

    // 4. Verificar que la modificación NO afectó al catálogo matriz
    $template = AccountingAccountTemplate::query()
        ->where('plan_version', 'PCGE_2019')
        ->where('code', '1041')
        ->first();

    expect($template->name)->toBe('Cuentas corrientes operativas');

    // 5. Verificar que la modificación NO afectó a la Empresa 1
    $this->tenantContext->run($this->company->id, function (): void {
        $acc1041Emp1 = AccountingAccount::query()->where('code', '1041')->first();
        expect($acc1041Emp1->name)->toBe('Cuentas corrientes operativas');
    });

    // Restaurar usuario a la empresa 1
    $this->user->forceFill(['current_company_id' => $this->company->id])->save();
});
