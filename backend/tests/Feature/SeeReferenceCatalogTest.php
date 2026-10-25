<?php

declare(strict_types=1);

use App\Models\User;

it('keeps SEE separate from PLE and preserves the source ambiguity', function (): void {
    $user = User::query()->firstOrCreate(
        ['email' => 'see-reference-test@example.test'],
        ['name' => 'SEE Reference Tester', 'password' => 'Test-only-reference-password-2026!']
    );
    $see = $this->actingAs($user)->getJson('/api/v1/catalogs/sunat-see/03?version=historical&q=A11');
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
    $this->getJson('/api/v1/catalogs/sunat-see/02?page=1&per_page=25')
        ->assertOk()->assertJsonPath('meta.total', 178)->assertJsonCount(25, 'data');
    $this->getJson('/api/v1/catalogs/sunat-see/02?q=&page=1&per_page=25')
        ->assertOk()->assertJsonPath('meta.total', 178);
    $this->getJson('/api/v1/catalogs/sunat-see/03?per_page=2000')
        ->assertOk()->assertJsonPath('meta.total', 2136)->assertJsonCount(2000, 'data');
    $this->getJson('/api/v1/catalogs/sunat-see/03?per_page=3000')
        ->assertOk()->assertJsonPath('meta.total', 2136)->assertJsonCount(2136, 'data');
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
    $this->getJson('/api/v1/catalogs/sunat-see/ubigeos?page=1&per_page=25')
        ->assertOk()->assertJsonPath('meta.total', 1893)
        ->assertJsonPath('meta.version', '2026-inei')->assertJsonCount(25, 'data');
    $this->getJson('/api/v1/catalogs/sunat-see/ubigeos?q=&page=1&per_page=25')
        ->assertOk()->assertJsonPath('meta.total', 1893);
    $this->getJson('/api/v1/catalogs/sunat-see/ubigeos?per_page=2000')
        ->assertOk()->assertJsonPath('meta.total', 1893)->assertJsonCount(1893, 'data');
    $this->getJson('/api/v1/catalogs/sunat-see/ubigeos?q=130112')
        ->assertOk()->assertJsonPath('data.0.district', 'Alto Trujillo')
        ->assertJsonPath('data.0.population', null);
    $this->getJson('/api/v1/catalogs/sunat-see/ubigeos?version=historical')
        ->assertOk()->assertJsonPath('meta.total', 1874)
        ->assertJsonPath('meta.version', 'historical');
    $this->getJson('/api/v1/catalogs/sunat-see/ubigeos?version=otro')->assertNotFound();

    $this->postJson('/api/v1/catalogs/sunat-see/ubigeos', ['code' => '999999'])
        ->assertStatus(405);
});

it('serves SUNAT 2024 catalog 20 as a separate version without changing historical rows or PLE', function (): void {
    $user = User::query()->firstOrCreate(
        ['email' => 'see-reference-test@example.test'],
        ['name' => 'SEE Reference Tester', 'password' => 'Test-only-reference-password-2026!']
    );
    $this->actingAs($user)->getJson('/api/v1/catalogs/sunat-see/20?version=historical')
        ->assertOk()->assertJsonPath('meta.total', 9)
        ->assertJsonPath('meta.classification', 'SUPPLIED_SEE_SNAPSHOT_UNVERIFIED');
    $this->getJson('/api/v1/catalogs/sunat-see/20?version=2024-anexo-i')
        ->assertOk()->assertJsonPath('meta.total', 14)
        ->assertJsonPath('meta.version', '2024-anexo-i')
        ->assertJsonPath('meta.classification', 'SUNAT_ANNEX_I_2024_REFERENCE')
        ->assertJsonFragment(['code' => '03', 'description' => 'Venta con entrega a terceros'])
        ->assertJsonFragment(['code' => '19', 'description' => 'Traslado de mercancía extranjera']);
    $this->getJson('/api/v1/catalogs/sunat-see/20?version=2024-anexo-i&q=consignaci%C3%B3n')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', '05');
    $this->getJson('/api/v1/catalogs/sunat-see/20?version=otro')->assertNotFound();
    $this->getJson('/api/v1/catalogs/sunat-see/19?version=2024-anexo-i')->assertNotFound();
    $this->getJson('/api/v1/catalogs/sunat/03')->assertOk()->assertDontSee('Venta con entrega a terceros');
});

it('resolves the latest available SEE edition and keeps the received snapshot addressable', function (): void {
    $user = User::query()->firstOrCreate(
        ['email' => 'see-reference-test@example.test'],
        ['name' => 'SEE Reference Tester', 'password' => 'Test-only-reference-password-2026!']
    );
    $this->actingAs($user)->getJson('/api/v1/catalogs/sunat-see')
        ->assertOk()->assertJsonPath('data.14.latest_version', '2017-anexo-e')
        ->assertJsonPath('data.18.latest_version', '2017-anexo-e');
    foreach (['15' => ['2017-anexo-e', 41, '2008', 40],
              '19' => ['2017-anexo-e', 4, '4', 3],
              '20' => ['2024-anexo-i', 14, '03', 9],
              '55' => ['2026-anexo-i', 119, '7029', 30]] as $number => [$edition, $count, $code, $oldCount]) {
        $this->getJson("/api/v1/catalogs/sunat-see/{$number}?per_page=2000")
            ->assertOk()->assertJsonPath('meta.version', $edition)
            ->assertJsonPath('meta.total', $count)->assertJsonFragment(['code' => $code]);
        $this->getJson("/api/v1/catalogs/sunat-see/{$number}?version=historical")
            ->assertOk()->assertJsonPath('meta.total', $oldCount);
    }
    $this->getJson('/api/v1/catalogs/sunat-see/02')->assertOk()
        ->assertJsonPath('meta.version', '2026-09-17-list-one');
});

it('returns ISO 4217 currencies by default and preserves historical PEN and obsolete codes', function (): void {
    $user = User::query()->firstOrCreate(
        ['email' => 'see-reference-test@example.test'],
        ['name' => 'SEE Reference Tester', 'password' => 'Test-only-reference-password-2026!']
    );
    $this->actingAs($user)->getJson('/api/v1/catalogs/sunat-see/02?q=PEN')
        ->assertOk()->assertJsonPath('meta.version', '2026-09-17-list-one')
        ->assertJsonPath('data.0.description', 'Sol')
        ->assertJsonPath('data.0.details.Número ISO', '604');
    $this->getJson('/api/v1/catalogs/sunat-see/02?version=historical&q=PEN')
        ->assertOk()->assertJsonPath('data.0.description', 'Nuevo Sol');
    $this->getJson('/api/v1/catalogs/sunat-see/02?q=BGN')
        ->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/catalogs/sunat-see/02?version=historical&q=BGN')
        ->assertOk()->assertJsonPath('meta.total', 1);
});

it('returns the UN/CEFACT country edition separately from the historical country list', function (): void {
    $user = User::query()->firstOrCreate(
        ['email' => 'see-reference-test@example.test'],
        ['name' => 'SEE Reference Tester', 'password' => 'Test-only-reference-password-2026!']
    );
    $this->actingAs($user)->getJson('/api/v1/catalogs/sunat-see/04?per_page=2000')
        ->assertOk()->assertJsonPath('meta.version', '2026-10-24-uncefact')
        ->assertJsonPath('meta.total', 249)
        ->assertJsonFragment(['code' => 'AX', 'description' => 'ÅLAND ISLANDS']);
    $this->getJson('/api/v1/catalogs/sunat-see/04?version=historical')
        ->assertOk()->assertJsonPath('meta.total', 239);
    $this->getJson('/api/v1/catalogs/sunat-see/04?q=ZR')
        ->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/catalogs/sunat-see/04?version=historical&q=ZR')
        ->assertOk()->assertJsonPath('meta.total', 1);
});

it('shows the complete UNECE units edition with status marks while keeping ambiguous historical A11', function (): void {
    $user = User::query()->firstOrCreate(
        ['email' => 'see-reference-test@example.test'],
        ['name' => 'SEE Reference Tester', 'password' => 'Test-only-reference-password-2026!']
    );
    $this->actingAs($user)->getJson('/api/v1/catalogs/sunat-see/03?q=A11')
        ->assertOk()->assertJsonPath('meta.version', '2021-rev17')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.description', 'angstrom')
        ->assertJsonPath('data.0.is_ambiguous', false);
    $result = $this->getJson('/api/v1/catalogs/sunat-see/03?per_page=3000')->assertOk();
    expect(collect($result->json('data'))->firstWhere('code', '05')['details']['Estado fuente'])->toBe('X');
    $this->getJson('/api/v1/catalogs/sunat-see/03?version=historical&q=A11')
        ->assertOk()->assertJsonPath('meta.total', 2);
});

it('serves SUNAT mortgage references 26 and 27 only from the published edition', function (): void {
    $user = User::query()->firstOrCreate(
        ['email' => 'see-reference-test@example.test'],
        ['name' => 'SEE Reference Tester', 'password' => 'Test-only-reference-password-2026!']
    );
    $index = $this->actingAs($user)->getJson('/api/v1/catalogs/sunat-see')->assertOk();
    expect(collect($index->json('data'))->firstWhere('number', '26')['historical_item_count'])->toBe(0);

    foreach (['26' => [3, '2'], '27' => [4, '3']] as $number => [$count, $lastCode]) {
        $this->getJson("/api/v1/catalogs/sunat-see/{$number}")
            ->assertOk()->assertJsonPath('meta.version', '2017-12-anexo-v')
            ->assertJsonPath('meta.total', $count)->assertJsonFragment(['code' => $lastCode]);
        $this->getJson("/api/v1/catalogs/sunat-see/{$number}?version=historical")->assertNotFound();
    }
    $this->getJson('/api/v1/catalogs/sunat-see/27?q=primera')
        ->assertOk()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.code', '3');
    $this->getJson('/api/v1/catalogs/sunat/01')->assertOk()->assertDontSee('Mi Vivienda');
});

it('matches the cited PLE and SEE payment codes without mixing their editions', function (): void {
    $user = User::query()->firstOrCreate(
        ['email' => 'see-reference-test@example.test'],
        ['name' => 'SEE Reference Tester', 'password' => 'Test-only-reference-password-2026!']
    );
    $this->actingAs($user)->getJson('/api/v1/catalogs/sunat-see/59?q=011')
        ->assertOk()->assertJsonPath('meta.version', '2017-12-anexo-v')
        ->assertJsonPath('data.0.code', '011')
        ->assertJsonPath('data.0.description', 'DOCUMENTOS EMITIDOS POR LAS EDPYMES Y LAS COOPERATIVAS DE AHORRO Y CRÉDITO NO AUTORIZADAS A CAPTAR DEPÓSITOS DEL PÚBLICO');
    $this->getJson('/api/v1/catalogs/sunat-see/59')->assertOk()->assertJsonPath('meta.total', 22);
    $this->getJson('/api/v1/catalogs/sunat-see/59?version=historical')->assertNotFound();
    $ple = $this->getJson('/api/v1/catalogs/sunat/01')->assertOk()
        ->assertJsonPath('meta.version', '2015-06-anexo-3')
        ->assertJsonPath('meta.total', 22)
        ->assertJsonFragment(['code' => '011', 'name' => 'DOCUMENTOS EMITIDOS POR LAS EDPYMES Y LAS COOPERATIVAS DE AHORRO Y CRÉDITO NO AUTORIZADAS A CAPTAR DEPÓSITOS DEL PÚBLICO'])
        ->assertJsonFragment(['code' => '012'])
        ->assertJsonFragment(['code' => '013']);
    $see = $this->getJson('/api/v1/catalogs/sunat-see/59?per_page=100')->assertOk();
    expect(array_column($ple->json('data'), 'code'))->toBe(array_column($see->json('data'), 'code'));

    $this->getJson('/api/v1/catalogs/sunat/01?version=legacy-seed')->assertOk()
        ->assertJsonPath('meta.total', 20)
        ->assertJsonFragment(['code' => '011', 'name' => 'LETRAS DE CAMBIO']);
    $this->getJson('/api/v1/catalogs/sunat/01?version=other')->assertNotFound();
});

it('preserves the five seeded PLE identity types and exposes the sixth Annex 3 code separately from SEE 06', function (): void {
    $user = User::query()->firstOrCreate(
        ['email' => 'see-reference-test@example.test'],
        ['name' => 'SEE Reference Tester', 'password' => 'Test-only-reference-password-2026!']
    );
    $this->actingAs($user)->getJson('/api/v1/catalogs/sunat/02')->assertOk()
        ->assertJsonPath('meta.version', '2015-06-anexo-3')
        ->assertJsonPath('meta.total', 6)
        ->assertJsonFragment(['code' => 'A', 'name' => 'CÉDULA DIPLOMÁTICA DE IDENTIDAD']);
    $this->getJson('/api/v1/catalogs/sunat/02?version=legacy-seed')->assertOk()
        ->assertJsonPath('meta.total', 5)
        ->assertJsonFragment(['code' => '4', 'name' => 'CARNET DE EXTRANJERÍA']);
    $this->getJson('/api/v1/catalogs/sunat/02?version=unknown')->assertNotFound();
    $this->getJson('/api/v1/catalogs/sunat-see/06')->assertOk()
        ->assertJsonPath('meta.version', '2026-000049-anexo-i')
        ->assertJsonPath('meta.total', 13);
});

it('archives the six seeded inventory types and returns nineteen PLE Annex 3 entries', function (): void {
    $user = User::query()->firstOrCreate(
        ['email' => 'see-reference-test@example.test'],
        ['name' => 'SEE Reference Tester', 'password' => 'Test-only-reference-password-2026!']
    );
    $this->actingAs($user)->getJson('/api/v1/catalogs/sunat/05')->assertOk()
        ->assertJsonPath('meta.version', '2015-06-anexo-3')
        ->assertJsonPath('meta.total', 19)
        ->assertJsonFragment(['code' => '05', 'name' => 'MATERIALES AUXILIARES'])
        ->assertJsonFragment(['code' => '06', 'name' => 'SUMINISTROS'])
        ->assertJsonFragment(['code' => '98', 'name' => 'OTROS 8']);
    $this->getJson('/api/v1/catalogs/sunat/05?version=legacy-seed')->assertOk()
        ->assertJsonPath('meta.total', 6)
        ->assertJsonFragment(['code' => '05', 'name' => 'SUMINISTROS DIVERSOS']);
    $this->getJson('/api/v1/catalogs/sunat/05?version=other')->assertNotFound();
});

it('shows the historical PLE financial institution list apart from the operational seed', function (): void {
    $user = User::query()->firstOrCreate(
        ['email' => 'see-reference-test@example.test'],
        ['name' => 'SEE Reference Tester', 'password' => 'Test-only-reference-password-2026!']
    );
    $this->actingAs($user)->getJson('/api/v1/catalogs/sunat/03')->assertOk()
        ->assertJsonPath('meta.version', '2015-06-anexo-3')
        ->assertJsonPath('meta.classification', 'SUNAT_PLE_2015_ANNEX_3_HISTORICAL_REFERENCE')
        ->assertJsonPath('meta.operational_status_verified', false)
        ->assertJsonPath('meta.total', 38)
        ->assertJsonFragment(['code' => '51', 'name' => 'AGRO BANCO'])
        ->assertJsonFragment(['code' => '54', 'name' => 'BANCO FALABELLA S.A.'])
        ->assertJsonFragment(['code' => '58', 'name' => 'BANCO AZTECA DEL PERU']);
    $this->getJson('/api/v1/catalogs/sunat/03?version=legacy-seed')->assertOk()
        ->assertJsonPath('meta.total', 33)
        ->assertJsonFragment(['code' => '02', 'name' => 'BANCO DE CREDITO DEL PERU'])
        ->assertDontSee('AGRO BANCO');
    $this->getJson('/api/v1/catalogs/sunat/03?version=other')->assertNotFound();
});

it('displays PLE units as annex codes and keeps numeric legacy and SEE codes separate', function (): void {
    $user = User::query()->firstOrCreate(
        ['email' => 'see-reference-test@example.test'],
        ['name' => 'SEE Reference Tester', 'password' => 'Test-only-reference-password-2026!']
    );
    $this->actingAs($user)->getJson('/api/v1/catalogs/sunat/06')->assertOk()
        ->assertJsonPath('meta.version', '2015-06-anexo-3')
        ->assertJsonPath('meta.total', 62)
        ->assertJsonFragment(['code' => 'KGM', 'name' => 'KILOGRAMO'])
        ->assertJsonFragment(['code' => 'NIU', 'name' => 'UNIDAD (BIENES)']);
    $this->getJson('/api/v1/catalogs/sunat/06?version=legacy-seed')->assertOk()
        ->assertJsonPath('meta.total', 16)
        ->assertJsonFragment(['code' => '07', 'symbol' => 'NIU']);
    $this->getJson('/api/v1/catalogs/sunat-see/03?q=KGM')->assertOk()
        ->assertJsonPath('meta.version', '2021-rev17');
    $this->getJson('/api/v1/catalogs/sunat/06?version=other')->assertNotFound();
});

it('lists the 2026 identity references and keeps the nine supplied codes historical', function (): void {
    $user = User::query()->firstOrCreate(
        ['email' => 'see-reference-test@example.test'],
        ['name' => 'SEE Reference Tester', 'password' => 'Test-only-reference-password-2026!']
    );
    $this->actingAs($user)->getJson('/api/v1/catalogs/sunat-see/06')
        ->assertOk()->assertJsonPath('meta.version', '2026-000049-anexo-i')
        ->assertJsonPath('meta.total', 13);
    $this->getJson('/api/v1/catalogs/sunat-see/06?q=H')
        ->assertOk()->assertJsonFragment(['code' => 'H', 'description' => 'CARNE PERMISO TEMP.PERMAN -CPP']);
    $this->getJson('/api/v1/catalogs/sunat-see/06?q=E')
        ->assertOk()->assertJsonFragment(['code' => 'E', 'description' => 'TAM- Tarjeta Andina de Migración']);
    $this->getJson('/api/v1/catalogs/sunat-see/06?version=historical')
        ->assertOk()->assertJsonPath('meta.total', 9);
    $this->getJson('/api/v1/catalogs/sunat/02')->assertOk()->assertDontSee('CARNE PERMISO TEMP.PERMAN -CPP');
});
