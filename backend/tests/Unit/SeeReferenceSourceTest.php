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
