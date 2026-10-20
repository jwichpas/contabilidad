<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Tenant\ListUserCompaniesService;
use Illuminate\Support\Facades\DB;

/**
 * F0 — Smoke HTTP sin mutaciones sobre la BD unica de desarrollo.
 *
 * Todos los endpoints son GET. No crea empresas, usuarios, tokens ni asientos;
 * no llama a POST, PUT, PATCH, DELETE, a migraciones ni a seeders.
 *
 * Pest.php aplica DatabaseTransactions como proteccion adicional.
 */
it('rejects unauthenticated access to the company and journal APIs', function (): void {
    $this->getJson('/api/v1/tenant/companies')->assertUnauthorized();
    $this->getJson('/api/v1/accounting/journal-entries')->assertUnauthorized();
});

it('lists the authenticated users existing active company', function (): void {
    $user = User::query()->whereNotNull('current_company_id')->firstOrFail();
    $companyId = (int) $user->current_company_id;

    // Debe mostrar al menos la empresa ya seleccionada por el usuario.
    // Si el resultado es [], revisar el SELECT RLS de company_user:
    // este endpoint no entra en SetTenantContext.
    $this->actingAs($user)
        ->getJson('/api/v1/tenant/companies')
        ->assertOk()
        ->assertJsonFragment(['id' => $companyId]);
});

it('denies an authenticated user access to a nonexistent company', function (): void {
    $user = User::query()->whereNotNull('current_company_id')->firstOrFail();

    $this->actingAs($user)
        ->withHeader('X-Company-ID', '9223372036854775807')
        ->getJson('/api/v1/accounting/journal-entries?limit=1')
        ->assertForbidden();
});

it('allows a member to read existing journal entries without writing anything', function (): void {
    $user = User::query()->whereNotNull('current_company_id')->firstOrFail();
    $companyId = (int) $user->current_company_id;

    $this->actingAs($user)
        ->withHeader('X-Company-ID', (string) $companyId)
        ->getJson('/api/v1/accounting/journal-entries?limit=1')
        ->assertOk()
        ->assertJsonStructure(['data']);
});

it('reuses the login company-listing service without issuing or changing tokens', function (): void {
    $user = User::query()->whereNotNull('current_company_id')->firstOrFail();
    $before = DB::table('personal_access_tokens')->count();

    $companies = app(ListUserCompaniesService::class)->forUser($user);

    expect(array_column($companies, 'id'))
        ->toContain((int) $user->current_company_id);
    expect(DB::table('personal_access_tokens')->count())->toBe($before);
});

/**
 * F0: evita regresar al SQL inválido "boolean = 1" de PDO emulado.
 * Solo GET; no crea empresas, usuarios, tokens ni asientos.
 */
it('reads operational accounts and a filtered tree without PostgreSQL boolean/integer comparisons', function (): void {
    $user = User::query()->whereNotNull('current_company_id')->firstOrFail();
    $companyId = (int) $user->current_company_id;

    $this->actingAs($user)
        ->withHeader('X-Company-ID', (string) $companyId)
        ->getJson('/api/v1/accounting/accounts/operational')
        ->assertOk()
        ->assertJsonStructure(['data']);

    $this->withHeader('X-Company-ID', (string) $companyId)
        ->getJson('/api/v1/accounting/accounts/tree?only_active=1')
        ->assertOk()
        ->assertJsonStructure(['data']);
});

it('lists tenant and global catalogs with PostgreSQL boolean filters', function (): void {
    $user = User::query()->whereNotNull('current_company_id')->firstOrFail();
    $companyId = (int) $user->current_company_id;
    $this->actingAs($user)->withHeader('X-Company-ID', (string) $companyId);

    // Solo GET sin mutaciones; no invocar GET company/settings porque el
    // controlador puede inicializar parametros si faltan.
    foreach ([
        '/api/v1/catalogs/currencies',
        '/api/v1/catalogs/accounting-standards',
        '/api/v1/catalogs/financial-statement-rubrics',
        '/api/v1/catalogs/chart-of-accounts-templates?version=PCGE_2019',
        '/api/v1/company/areas',
        '/api/v1/company/cost-centers',
        '/api/v1/company/cash-flow-areas',
        '/api/v1/company/entities?is_client=1',
    ] as $path) {
        $this->getJson($path)->assertOk()->assertJsonStructure(['data']);
    }
});
