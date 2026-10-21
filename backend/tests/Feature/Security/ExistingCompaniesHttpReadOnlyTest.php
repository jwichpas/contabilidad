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
