<?php

declare(strict_types=1);

use App\Models\User;

it('keeps SEE separate from PLE and preserves the source ambiguity', function (): void {
    $user = User::query()->firstOrCreate(
        ['email' => 'see-reference-test@example.test'],
        ['name' => 'SEE Reference Tester', 'password' => 'Test-only-reference-password-2026!']
    );
    $see = $this->actingAs($user)->getJson('/api/v1/catalogs/sunat-see/03?q=A11');
    $see->assertOk()->assertJsonPath('meta.catalog_number', '03')
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.is_ambiguous', true)
        ->assertJsonPath('data.1.is_ambiguous', true);

    $this->getJson('/api/v1/catalogs/sunat/03')->assertOk()
        ->assertDontSee('Ångstråm');
    $this->getJson('/api/v1/catalogs/sunat-see/04?q=PE')
        ->assertOk()->assertJsonFragment(['code' => 'PE', 'description' => 'PERU']);
    $this->getJson('/api/v1/catalogs/sunat-see/02?q=CLP')
        ->assertOk()->assertJsonFragment(['code' => 'CLP']);
    $this->getJson('/api/v1/catalogs/sunat-see/13')
        ->assertOk()->assertJsonPath('meta.item_count', 0);
});

it('lists global ubigeos and exposes no writes for company users', function (): void {
    $user = User::query()->firstOrCreate(
        ['email' => 'see-reference-test@example.test'],
        ['name' => 'SEE Reference Tester', 'password' => 'Test-only-reference-password-2026!']
    );
    $this->actingAs($user)->getJson('/api/v1/catalogs/sunat-see/ubigeos?q=010101')
        ->assertOk()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.district', 'Chachapoyas');

    $this->postJson('/api/v1/catalogs/sunat-see/ubigeos', ['code' => '999999'])
        ->assertStatus(405);
});
