<?php

declare(strict_types=1);

use App\Models\User;

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
