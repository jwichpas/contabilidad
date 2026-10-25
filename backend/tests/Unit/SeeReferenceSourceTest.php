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

it('pins the official ISO 4217 List One edition while preserving the supplied currency snapshot', function (): void {
    $path = database_path('reference_data/see_catalog_02_iso_4217_2026_09_17.json');
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $codes = array_column($source['rows'], 0);
    expect(hash_file('sha256', $path))->toBe('206b0560785fcaf9951e78472dd63e28032e326211b9e73b1242e530944cb681')
        ->and($source['source_xml_sha256'])->toBe('33139b438657d1cee116ba737807ea71d19d6de4b90f799a09c56f0cc6a1b0ff')
        ->and($source['source_published_at'])->toBe('2026-09-17')
        ->and($source['rows'])->toHaveCount(178)
        ->and(array_unique($codes))->toHaveCount(178)
        ->and(array_column($source['rows'], 1, 0)['PEN'])->toBe('Sol')
        ->and($codes)->toContain('ZWG')
        ->and($codes)->not->toContain('BGN');
});

it('pins INEI 2026 district codes separately from the supplied ubigeo snapshot', function (): void {
    $path = database_path('reference_data/see_ubigeos_inei_2026.json');
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $old = json_decode((string) file_get_contents(database_path('reference_data/see_ubigeos_inei_snapshot.json')), true, 512, JSON_THROW_ON_ERROR);
    $codes = array_column($source['rows'], 0);
    expect(hash_file('sha256', $path))->toBe('0ca6032339c099be56670d8a99d14acaa413a8daa1d2ecf9135b8a456c4a9391')
        ->and($source['source_xls_sha256'])->toBe('a62174658a2124fab1366cae40e6ca0feefcc025027b50522909838fbfaae331')
        ->and($source['source_version_id'])->toBe('9-1')
        ->and($source['rows'])->toHaveCount(1893)
        ->and(array_unique($codes))->toHaveCount(1893)
        ->and(array_diff(array_column($old['rows'], 0), $codes))->toBe([])
        ->and(array_diff($codes, array_column($old['rows'], 0)))->toHaveCount(19);
});

it('pins the UN/CEFACT country snapshot and its source CSV', function (): void {
    $path = database_path('reference_data/see_catalog_04_uncefact_2026_10_24.json');
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $codes = array_column($source['rows'], 0);
    expect(hash_file('sha256', $path))->toBe('53a93e2b6e79978dab273b628cb397f6abda16f579afcd6a911399eab2d0afea')
        ->and($source['source_csv_sha256'])->toBe('0a6ea77aaeec295438b9cb69c6c68186b332c64e6d4a34a64d66b29f37b9f87b')
        ->and($source['rows'])->toHaveCount(249)
        ->and(array_unique($codes))->toHaveCount(249)
        ->and($codes)->toContain('PE', 'AX')
        ->and($codes)->not->toContain('AN');
});
