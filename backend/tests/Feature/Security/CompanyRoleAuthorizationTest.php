<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * F1.3 — HTTP real con empresas/usuarios ficticios y rollback por Pest.php.
 * EJECUTAR SOLO EN CI PostgreSQL efimero, NUNCA contra Supabase de desarrollo.
 * Verifica permisos del middleware antes de llegar al controlador de escritura.
 */
beforeEach(function (): void {
    $unique = bin2hex(random_bytes(4));

    $this->companyA = Company::query()->create([
        'tax_id' => '20'.(string) random_int(100000000, 999999999),
        'business_name' => 'F1 Test Empresa Alfa',
        'legal_address' => 'Calle de Prueba 101',
        'is_active' => DB::raw('TRUE'),
    ]);

    $this->companyB = Company::query()->create([
        'tax_id' => '20'.(string) random_int(100000000, 999999999),
        'business_name' => 'F1 Test Empresa Beta',
        'legal_address' => 'Calle de Prueba 202',
        'is_active' => DB::raw('TRUE'),
    ]);

    $this->administrator = User::query()->create([
        'name' => 'Administrador F1',
        'email' => "f1_admin_{$unique}@example.test",
        'password' => 'Temporary-CI-Only-1!',
    ]);
    $this->accountant = User::query()->create([
        'name' => 'Contador F1',
        'email' => "f1_accountant_{$unique}@example.test",
        'password' => 'Temporary-CI-Only-2!',
    ]);
    $this->operator = User::query()->create([
        'name' => 'Digitador F1',
        'email' => "f1_operator_{$unique}@example.test",
        'password' => 'Temporary-CI-Only-3!',
    ]);

    $now = now();
    $tenantContext = app(\App\Support\Tenant\TenantContext::class);
    $tenantContext->run($this->companyA->id, function () use ($now): void {
        DB::table('company_user')->insert([
            'company_id' => $this->companyA->id, 'user_id' => $this->administrator->id,
            'role' => 'ADMIN', 'is_active' => DB::raw('TRUE'), 'created_at' => $now, 'updated_at' => $now,
        ]);
    });
    $tenantContext->run($this->companyB->id, function () use ($now): void {
        DB::table('company_user')->insert([
            ['company_id' => $this->companyB->id, 'user_id' => $this->administrator->id, 'role' => 'AUDITOR', 'is_active' => DB::raw('TRUE'), 'created_at' => $now, 'updated_at' => $now],
            ['company_id' => $this->companyB->id, 'user_id' => $this->accountant->id, 'role' => 'ACCOUNTANT', 'is_active' => DB::raw('TRUE'), 'created_at' => $now, 'updated_at' => $now],
            ['company_id' => $this->companyB->id, 'user_id' => $this->operator->id, 'role' => 'DATA_ENTRY', 'is_active' => DB::raw('TRUE'), 'created_at' => $now, 'updated_at' => $now],
        ]);
    });

    // Clave foranea compuesta: primero crear membresias, DESPUES asignar
    // current_company_id. De lo contrario INSERT users viola la FK.
    $this->administrator->update(['current_company_id' => $this->companyA->id]);
    $this->accountant->update(['current_company_id' => $this->companyB->id]);
    $this->operator->update(['current_company_id' => $this->companyB->id]);
});

it('denies writes to auditors even if they are administrators of another company', function (): void {
    $this->actingAs($this->administrator)->withHeader('X-Company-Id', (string) $this->companyB->id);

    foreach ([
        ['POST', '/api/v1/accounting/journal-entries'],
        ['POST', '/api/v1/accounting/accounts'],
        ['PUT', '/api/v1/accounting/accounts/12345'],
        ['PATCH', '/api/v1/accounting/accounts/12345/deactivate'],
        ['PUT', '/api/v1/company/settings'],
        ['POST', '/api/v1/company/exchange-rates'],
        ['POST', '/api/v1/company/chart-of-accounts/initialize'],
    ] as [$method, $uri]) {
        $this->json($method, $uri, [])->assertForbidden();
    }
});

it('allows administrators to reach account validation in their own company', function (): void {
    $this->actingAs($this->administrator)
        ->withHeader('X-Company-Id', (string) $this->companyA->id)
        ->postJson('/api/v1/accounting/accounts', [])
        ->assertUnprocessable();
});

it('allows accountant to reach journal validation, but denies account administration', function (): void {
    $this->actingAs($this->accountant)->withHeader('X-Company-Id', (string) $this->companyB->id);
    $this->postJson('/api/v1/accounting/journal-entries', [])->assertUnprocessable();
    $this->postJson('/api/v1/accounting/accounts', [])->assertForbidden();
    $this->putJson('/api/v1/company/settings', [])->assertForbidden();
});

it('rejects posting by data entry until a separate draft workflow exists', function (): void {
    $this->actingAs($this->operator)
        ->withHeader('X-Company-Id', (string) $this->companyB->id)
        ->postJson('/api/v1/accounting/journal-entries', [])
        ->assertForbidden();
});

it('checks membership and role for the requested tenant, not the default tenant', function (): void {
    $this->actingAs($this->accountant)
        ->withHeader('X-Company-Id', (string) $this->companyA->id)
        ->postJson('/api/v1/accounting/journal-entries', [])
        ->assertForbidden();
});

it('denies access immediately after membership is revoked', function (): void {
    app(\App\Support\Tenant\TenantContext::class)->run($this->companyB->id, function (): void {
        DB::table('company_user')
            ->where('company_id', $this->companyB->id)
            ->where('user_id', $this->accountant->id)
            ->update(['is_active' => DB::raw('FALSE')]);
    });

    $this->actingAs($this->accountant)
        ->withHeader('X-Company-Id', (string) $this->companyB->id)
        ->postJson('/api/v1/accounting/journal-entries', [])
        ->assertForbidden();
});

it('does not allow unauthenticated requests to enter company mutation routes', function (): void {
    $this->postJson('/api/v1/accounting/journal-entries', [])->assertUnauthorized();
});

it('rejects malformed tenant headers before authorization', function (): void {
    $this->actingAs($this->administrator);
    foreach (['0', '-1', 'abc', '1 OR 1=1'] as $forgedHeader) {
        $this->withHeader('X-Company-Id', $forgedHeader)
            ->getJson('/api/v1/accounting/journal-entries?limit=1')
            ->assertBadRequest();
    }
});

it('permits auditor read access only within an active membership', function (): void {
    $this->actingAs($this->administrator)
        ->withHeader('X-Company-Id', (string) $this->companyB->id)
        ->getJson('/api/v1/accounting/journal-entries?limit=1')
        ->assertOk()
        ->assertJsonStructure(['data']);

    // Reading the A company is also allowed because this user is ADMIN there;
    // the write authorization is verified separately above.
});

it('rejects even valid roles if the destination company is inactive', function (): void {
    $this->companyB->forceFill(['is_active' => DB::raw('FALSE')])->save();

    $this->actingAs($this->accountant)
        ->withHeader('X-Company-Id', (string) $this->companyB->id)
        ->postJson('/api/v1/accounting/journal-entries', [])
        ->assertForbidden();
});
