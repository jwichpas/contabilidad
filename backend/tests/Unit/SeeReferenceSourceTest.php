<?php

declare(strict_types=1);

it('ships the full supplied SEE and INEI snapshots without dropping duplicate codes', function (): void {
    $catalogs = json_decode((string) file_get_contents(database_path('reference_data/see_catalogs_2017_snapshot.json')), true, 512, JSON_THROW_ON_ERROR);
    $ubigeos = json_decode((string) file_get_contents(database_path('reference_data/see_ubigeos_inei_snapshot.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($catalogs['classification'])->toBe('SUPPLIED_SEE_SNAPSHOT_UNVERIFIED')
        ->and($catalogs['catalogs'])->toHaveCount(33)
        ->and(array_sum(array_map(static fn ($c) => count($c['rows']), $catalogs['catalogs'])))->toBe(1792)
        ->and($ubigeos['rows'])->toHaveCount(1874);

    $units = collect($catalogs['catalogs'])->firstWhere('number', '03')['rows'];
    expect(collect($units)->where(0, 'A11')->pluck(1)->all())
        ->toBe(['5 C calorie', 'Ångstråm']);
});

it('pins the 14 independently sourced transport motives from SUNAT annex I of 2024', function (): void {
    $path = database_path('reference_data/see_catalog_20_anexo_i_2024.json');
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $path))->toBe('86b68440dcfbb0f0be6972eef393b0fc0807f781f884f5c08575620df86b8aa8')
        ->and($source['catalog_number'])->toBe('20')
        ->and($source['rows'])->toHaveCount(14)
        ->and(array_column($source['rows'], 0))->toBe(['01', '02', '03', '04', '05', '06', '07', '08', '09', '13', '14', '17', '18', '19']);
});

it('pins each reconciled annex fixture and its added code', function (): void {
    foreach ([
        ['15', '2017_anexo_e', '9f96ed5017b3d7eca4fdb81b8c89ad275d68e743abe5b4c9a4357a95635d26e6', 41, '2008'],
        ['19', '2017_anexo_e', 'f68f519e0fd7a1f881d203f74cba86a9f87f2c77c5cb80331d843eb93d53e84d', 4, '4'],
        ['55', '2026_anexo_i', '217cc40eee6a0eeb58b6fa8cb4caa315011ca8a71c9e3c874c4202adb246fc35', 119, '7029'],
    ] as [$number, $suffix, $sha, $count, $code]) {
        $path = database_path("reference_data/see_catalog_{$number}_{$suffix}.json");
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        expect(hash_file('sha256', $path))->toBe($sha)
            ->and($source['catalog_number'])->toBe($number)
            ->and($source['rows'])->toHaveCount($count)
            ->and(array_column($source['rows'], 0))->toContain($code);
    }
});
