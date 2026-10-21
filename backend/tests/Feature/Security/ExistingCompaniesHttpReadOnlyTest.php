<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Tenant\ListUserCompaniesService;
use Illuminate\Support\Facades\DB;

/**
 * F1.3: smoke HTTP GET sobre dos empresas EXISTENTES, sin crear registros.
 * La base debe ser Supabase Dev app_backend. No usar POST/PUT/PATCH/DELETE.
 * Se ejecuta con DatabaseTransactions desde tests/Pest.php.
 */
it('separates real company account IDs and journal entries through Laravel HTTP GET', function (): void {
    $role = DB::selectOne('SELECT current_user AS role_name')?->role_name;
    if ($role !== 'app_backend') {
        $this->markTestSkipped('Requiere conexión app_backend restringida.');
    }

    $selectedUser = null;
    $companies = [];
    foreach (User::query()->whereNotNull('current_company_id')->orderBy('id')->get() as $user) {
        $accessible = app(ListUserCompaniesService::class)->forUser($user);
        if (count($accessible) >= 2) {
            $selectedUser = $user;
            $companies = array_slice($accessible, 0, 2);
            break;
        }
    }
    if ($selectedUser === null) {
        $this->markTestSkipped('Requiere usuario con membresía activa en dos empresas reales.');
    }

    $this->actingAs($selectedUser);
    $listing = $this->getJson('/api/v1/tenant/companies')->assertOk()->json('data');
    $listedIds = array_map(static fn (array $c): int => (int) $c['id'], $listing);
    foreach ($companies as $company) {
        expect($listedIds)->toContain((int) $company['id']);
    }

    $accountIdsPerCompany = [];
    foreach ($companies as $company) {
        $id = (int) $company['id'];
        $accounts = $this->withHeader('X-Company-Id', (string) $id)
            ->getJson('/api/v1/accounting/accounts/operational')
            ->assertOk()
            ->json('data');
        expect($accounts)->toBeArray()->not->toBeEmpty();
        $accountIdsPerCompany[] = array_map(static fn (array $a): int => (int) $a['id'], $accounts);

        $entries = $this->withHeader('X-Company-Id', (string) $id)
            ->getJson('/api/v1/accounting/journal-entries?limit=100')
            ->assertOk()
            ->json('data');
        expect($entries)->toBeArray();
        foreach ($entries as $entry) {
            expect((int) $entry['company_id'])->toBe($id);
        }
    }
    expect(array_intersect($accountIdsPerCompany[0], $accountIdsPerCompany[1]))->toBe([]);
});

it('rejects anonymous access to all reviewed tenant GET routes', function (): void {
    $this->getJson('/api/v1/tenant/companies')->assertUnauthorized();
    $this->getJson('/api/v1/accounting/accounts/operational')->assertUnauthorized();
    $this->getJson('/api/v1/accounting/journal-entries?limit=1')->assertUnauthorized();
});

it('denies malformed and unassigned company IDs then permits its own company', function (): void {
    $role = DB::selectOne('SELECT current_user AS role_name')?->role_name;
    expect($role)->toBe('app_backend');

    $user = User::query()->whereNotNull('current_company_id')->orderBy('id')->firstOrFail();
    $id = (int) $user->current_company_id;
    $this->actingAs($user);

    foreach (['0', '-1', 'abc', '1 OR 1=1'] as $forgedId) {
        $this->withHeader('X-Company-Id', $forgedId)
            ->getJson('/api/v1/accounting/journal-entries?limit=1')
            ->assertBadRequest();
    }

    $this->withHeader('X-Company-Id', '9223372036854775807')
        ->getJson('/api/v1/accounting/journal-entries?limit=1')
        ->assertForbidden();

    $this->withHeader('X-Company-Id', (string) $id)
        ->getJson('/api/v1/accounting/journal-entries?limit=1')
        ->assertOk();
});

it('does not retain company context or cached accounts across A to B to A reads', function (): void {
    $role = DB::selectOne('SELECT current_user AS role_name')?->role_name;
    expect($role)->toBe('app_backend');

    $user = null;
    $companyIds = [];
    foreach (User::query()->whereNotNull('current_company_id')->orderBy('id')->get() as $candidate) {
        $available = app(ListUserCompaniesService::class)->forUser($candidate);
        if (count($available) >= 2) {
            $user = $candidate;
            $companyIds = array_map(static fn (array $c): int => (int) $c['id'], array_slice($available, 0, 2));
            break;
        }
    }
    if ($user === null) {
        $this->markTestSkipped('Requiere dos membresías de empresa activas.');
    }

    $this->actingAs($user);
    $read = function (int $companyId): array {
        $rows = $this->withHeader('X-Company-Id', (string) $companyId)
            ->getJson('/api/v1/accounting/accounts/operational')
            ->assertOk()->json('data');
        expect($rows)->toBeArray()->not->toBeEmpty();
        return array_map(static fn (array $a): int => (int) $a['id'], $rows);
    };

    $idsA1 = $read($companyIds[0]);
    $idsB = $read($companyIds[1]);
    $idsA2 = $read($companyIds[0]);

    expect($idsA2)->toEqual($idsA1);
    expect(array_intersect($idsA1, $idsB))->toBe([]);
    $current = DB::selectOne("SELECT nullif(current_setting('app.current_company_id', true), '') AS tenant_id");
    expect($current->tenant_id)->toBeNull();
});
