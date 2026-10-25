<?php

declare(strict_types=1);

it('ships the full supplied SEE and INEI snapshots without dropping duplicate codes', function (): void {
    $catalogs = json_decode((string) file_get_contents(__DIR__.'/../../database/reference_data/see_catalogs_2017_snapshot.json'), true, 512, JSON_THROW_ON_ERROR);
    $ubigeos = json_decode((string) file_get_contents(__DIR__.'/../../database/reference_data/see_ubigeos_inei_snapshot.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($catalogs['classification'])->toBe('SUPPLIED_SEE_SNAPSHOT_UNVERIFIED')
        ->and($catalogs['catalogs'])->toHaveCount(33)
        ->and(array_sum(array_map(static fn ($c) => count($c['rows']), $catalogs['catalogs'])))->toBe(1792)
        ->and($ubigeos['rows'])->toHaveCount(1874);

    $units = collect($catalogs['catalogs'])->firstWhere('number', '03')['rows'];
    expect(collect($units)->where(0, 'A11')->pluck(1)->all())
        ->toBe(['5 C calorie', 'Ångstråm']);
});

it('pins the 14 independently sourced transport motives from SUNAT annex I of 2024', function (): void {
    $path = __DIR__.'/../../database/reference_data/see_catalog_20_anexo_i_2024.json';
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $path))->toBe('86b68440dcfbb0f0be6972eef393b0fc0807f781f884f5c08575620df86b8aa8')
        ->and($source['catalog_number'])->toBe('20')
        ->and($source['rows'])->toHaveCount(14)
        ->and(array_column($source['rows'], 0))->toBe(['01', '02', '03', '04', '05', '06', '07', '08', '09', '13', '14', '17', '18', '19']);
});

it('pins independent PLE 01 and SEE 59 sources with matching official payment method codes', function (): void {
    $plePath = __DIR__.'/../../database/reference_data/ple_table_01_sunat_anexo_3_2015.json';
    $seePath = __DIR__.'/../../database/reference_data/see_catalog_59_sunat_anexo_v_2017.json';
    $ple = json_decode((string) file_get_contents($plePath), true, 512, JSON_THROW_ON_ERROR);
    $see = json_decode((string) file_get_contents($seePath), true, 512, JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $plePath))->toBe('b65eb22b6a5bcdb637e2a839a645a1a8f70274306da8b77fd91a64c3257df29c')
        ->and($ple['source_url'])->toContain('RS-169-2015-SUNAT-PLEver5.pdf')
        ->and($ple['rows'])->toHaveCount(22)
        ->and($ple['rows'])->toBe($see['rows'])
        ->and(array_column($ple['rows'], 0))->toContain('011', '012', '013');
});

it('pins PLE Annex 3 Table 2 without borrowing the SEE identity edition', function (): void {
    $path = __DIR__.'/../../database/reference_data/ple_table_02_sunat_anexo_3_2015.json';
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $path))->toBe('a513318955112c87220fd78905b21c4a5a8b6c4a33fbf79751ff1405f818e9ed')
        ->and($source['table_number'])->toBe('02')
        ->and($source['rows'])->toHaveCount(6)
        ->and(array_column($source['rows'], 0))->toBe(['0', '1', '4', '6', '7', 'A']);
});

it('pins thirty-eight historical PLE financial institutions without treating them as operating banks', function (): void {
    $path = __DIR__.'/../../database/reference_data/ple_table_03_sunat_anexo_3_2015.json';
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $codes = array_column($source['rows'], 0);
    expect(hash_file('sha256', $path))->toBe('ad413f15ef20fd7237589e9fc73a556e99093af24c17272c86b9185f206d3141')
        ->and($source['table_number'])->toBe('03')
        ->and($source['classification'])->toBe('SUNAT_PLE_2015_ANNEX_3_HISTORICAL_REFERENCE')
        ->and($source['rows'])->toHaveCount(38)
        ->and(array_unique($codes))->toHaveCount(38)
        ->and(array_diff($codes, ['51', '54', '55', '56', '58']))->toHaveCount(33)
        ->and($codes)->toContain('51', '54', '55', '56', '58');
});

it('pins the separate PLE Table 4 currency codes without importing SEE labels or ISO lifecycle', function (): void {
    $path = __DIR__.'/../../database/reference_data/ple_table_04_sunat_anexo_3_2015.json';
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $path))->toBe('ed87f1c2c391b7a07557152e4149c3bcd3cf28c20d0b14782ec0c3350a98959e')
        ->and($source['source_pdf_sha256'])->toBe('05e7a3ddcadd09354d367b1f876f070ba70ce1da2ec0abbfbda355e5b54600d6')
        ->and($source['table_number'])->toBe('04')
        ->and($source['classification'])->toBe('SUNAT_PLE_2015_ANNEX_3_HISTORICAL_CODES_ONLY')
        ->and($source['codes'])->toHaveCount(178)
        ->and(array_unique($source['codes']))->toHaveCount(178)
        ->and($source['codes'])->toContain('PEN', 'USD', 'CLP', 'BGN', 'ZWL')
        ->and($source['codes'])->not->toContain('BYN', 'ZWG');
});

it('pins the nineteen PLE inventory type codes and the separate legacy classification', function (): void {
    $path = __DIR__.'/../../database/reference_data/ple_table_05_sunat_anexo_3_2015.json';
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $path))->toBe('379587a63a33f99f2a9d373f7113567952e613426f0d14233027e8a91e7de3c1')
        ->and($source['table_number'])->toBe('05')
        ->and($source['rows'])->toHaveCount(19)
        ->and(array_column($source['rows'], 0))->toBe([
            '01', '02', '03', '04', '05', '06', '07', '08', '09', '10',
            '91', '92', '93', '94', '95', '96', '97', '98', '99',
        ])
        ->and($source['rows'][4][1])->toBe('MATERIALES AUXILIARES');
});

it('pins the sixty-two PLE Annex 3 unit codes independently of the old numeric mapping', function (): void {
    $path = __DIR__.'/../../database/reference_data/ple_table_06_sunat_anexo_3_2015.json';
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $codes = array_column($source['rows'], 0);
    expect(hash_file('sha256', $path))->toBe('ead39cf383282241e6590345c1811f9a2d435690974beca479d6452ddea6c8e3')
        ->and($source['table_number'])->toBe('06')
        ->and($source['rows'])->toHaveCount(62)
        ->and(array_unique($codes))->toHaveCount(62)
        ->and($codes)->toContain('KGM', 'LTR', 'NIU', 'ZZ', '4A')
        ->and($codes)->not->toContain('01', '07', '99');
});

it('pins the forty-seven PLE inventory operations and the changed meaning of code 07', function (): void {
    $path = __DIR__.'/../../database/reference_data/ple_table_12_sunat_anexo_3_2015.json';
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $codes = array_column($source['rows'], 0);
    expect(hash_file('sha256', $path))->toBe('0dc1c6b2bb5f578909e9112c25b1563bc26c9ca4f4fb60763226f271d07432b7')
        ->and($source['table_number'])->toBe('12')
        ->and($source['rows'])->toHaveCount(47)
        ->and(array_unique($codes))->toHaveCount(47)
        ->and(array_column($source['rows'], 1, 0)['07'])->toBe('BONIFICACIÓN')
        ->and($codes)->toContain('17', '28', '38', '91', '98');
});

it('pins distinct PLE 13 catalog origins and PLE 14 valuation methods', function (): void {
    $path = __DIR__.'/../../database/reference_data/ple_tables_13_14_sunat_anexo_3_2015.json';
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $path))->toBe('c0ee978c7067d73296db31169eeb6c68b642488d2093d8168a3bd9a00e026160')
        ->and($source['source_pdf_sha256'])->toBe('05e7a3ddcadd09354d367b1f876f070ba70ce1da2ec0abbfbda355e5b54600d6')
        ->and(array_column($source['tables'], 'number'))->toBe(['13', '14'])
        ->and($source['tables'][0]['rows'])->toBe([
            ['1', 'NACIONES UNIDAS'], ['3', 'GS1 (EAN-UCC)'], ['9', 'OTROS'],
        ])
        ->and($source['tables'][1]['rows'])->toBe([
            ['1', 'PROMEDIO PONDERADO'], ['2', 'PRIMERAS ENTRADAS, PRIMERAS SALIDAS'],
            ['3', 'EXISTENCIAS BÁSICAS'], ['4', 'DETALLISTA'],
            ['5', 'IDENTIFICACIÓN ESPECÍFICA'], ['9', 'OTROS'],
        ])
        ->and($source['latest_table_13']['version'])->toBe('2020-108-anexo-i')
        ->and($source['latest_table_13']['source_pdf_sha256'])->toBe('e7f79660ea4f977926885d949321ee47cf36a59dd55771670959001cf6071d99')
        ->and($source['latest_table_13']['rows'])->toBe([
            ['1', 'NACIONES UNIDAS'], ['3', 'GTIN'], ['9', 'OTROS'],
        ]);
});

it('pins the six distinct PLE Annex 3 tables for titles, shares, accounts and fixed assets', function (): void {
    $path = __DIR__.'/../../database/reference_data/ple_tables_15_20_sunat_anexo_3_2015.json';
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $path))->toBe('711c83ad4659999e4230c18d604bc1df11711089a780d662e78eb965fc66d737')
        ->and($source['source_pdf_sha256'])->toBe('05e7a3ddcadd09354d367b1f876f070ba70ce1da2ec0abbfbda355e5b54600d6')
        ->and(array_column($source['tables'], 'number'))->toBe(['15', '16', '17', '18', '19', '20'])
        ->and(array_map(static fn (array $table): int => count($table['rows']), $source['tables']))->toBe([11, 4, 8, 2, 3, 3])
        ->and($source['tables'][0]['rows'][10])->toBe(['99', 'OTROS TÍTULOS'])
        ->and($source['tables'][2]['rows'][0])->toBe(['01', 'PLAN CONTABLE GENERAL EMPRESARIAL'])
        ->and($source['tables'][5]['rows'])->toBe([
            ['1', 'LINEA RECTA'], ['2', 'UNIDADES PRODUCIDAS'], ['9', 'OTROS'],
        ]);
    foreach ($source['tables'] as $table) {
        $codes = array_column($table['rows'], 0);
        expect(array_unique($codes))->toHaveCount(count($codes));
    }
});

it('pins separate PLE production cost grouping and financial statement catalog codes', function (): void {
    $path = __DIR__.'/../../database/reference_data/ple_tables_21_22_sunat_anexo_3_2015.json';
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $path))->toBe('b47a542ef05cc9a7c202e5731a12c987790407a9b8f182436a254499183fe14e')
        ->and($source['source_pdf_sha256'])->toBe('05e7a3ddcadd09354d367b1f876f070ba70ce1da2ec0abbfbda355e5b54600d6')
        ->and(array_column($source['tables'], 'number'))->toBe(['21', '22'])
        ->and($source['tables'][0]['rows'])->toBe([
            ['1', 'PROCESO PRODUCTIVO'], ['2', 'LÍNEA DE PRODUCCIÓN'],
            ['3', 'PRODUCTO'], ['4', 'PROYECTO'], ['9', 'OTROS'],
        ])
        ->and($source['tables'][1]['rows'])->toHaveCount(9)
        ->and($source['tables'][1]['rows'][1])->toBe(['02', 'SUPERINTENDENCIA DEL MERCADO DE VALORES - SECTOR SEGUROS - INDIVIDUAL'])
        ->and($source['tables'][1]['rows'][8])->toBe(['09', 'OTROS NO CONSIDERADOS EN LOS ANTERIORES']);
});

it('pins the historical PLE treaty code list without treating it as current eligibility', function (): void {
    $path = __DIR__.'/../../database/reference_data/ple_table_25_treaties_sunat_anexo_3_2015.json';
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $path))->toBe('e84e81ab28b4bfeaea4e8887af7c918c9101b5173083ed334d2d5d834ddd776b')
        ->and($source['source_pdf_sha256'])->toBe('05e7a3ddcadd09354d367b1f876f070ba70ce1da2ec0abbfbda355e5b54600d6')
        ->and($source['table_number'])->toBe('25')
        ->and($source['classification'])->toBe('SUNAT_PLE_2015_ANNEX_3_HISTORICAL_REFERENCE')
        ->and(array_column($source['rows'], 0))->toBe(['00', '01', '02', '03', '04', '05', '06', '07', '08', '09'])
        ->and($source['rows'][3])->toBe(['03', 'COMUNIDAD ANDINA DE NACIONES (CAN)'])
        ->and($source['rows'][9])->toBe(['09', 'OTROS']);
});

it('pins all thirteen historical PLE economic relationship codes and the long code 12 description', function (): void {
    $path = __DIR__.'/../../database/reference_data/ple_table_27_economic_relationship_sunat_anexo_3_2015.json';
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $path))->toBe('82a580f14b449ca4220f298e6002fad3a57a9587813c37d9c78d485e9e47c09a')
        ->and($source['source_pdf_sha256'])->toBe('05e7a3ddcadd09354d367b1f876f070ba70ce1da2ec0abbfbda355e5b54600d6')
        ->and($source['table_number'])->toBe('27')
        ->and(array_column($source['rows'], 0))->toBe(['00', '01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'])
        ->and(strlen($source['rows'][12][1]))->toBeGreaterThan(2000)
        ->and($source['rows'][0])->toBe(['00', 'Sin vinculación']);
});

it('pins the forty-six historical PLE net equity codes without replacing the company PCGE', function (): void {
    $path = __DIR__.'/../../database/reference_data/ple_table_28_net_equity_sunat_anexo_3_2015.json';
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $codes = array_column($source['rows'], 0);
    expect(hash_file('sha256', $path))->toBe('436450eac57a7b2c20b90658306e94660bbc040315dd44d02536232285e93dcc')
        ->and($source['source_pdf_sha256'])->toBe('05e7a3ddcadd09354d367b1f876f070ba70ce1da2ec0abbfbda355e5b54600d6')
        ->and($source['table_number'])->toBe('28')
        ->and($source['classification'])->toBe('SUNAT_PLE_2015_ANNEX_3_HISTORICAL_REFERENCE')
        ->and($codes)->toHaveCount(46)
        ->and(array_unique($codes))->toHaveCount(46)
        ->and($source['rows'][16])->toBe(['56', 'RESULTADOS NO REALIZADOS'])
        ->and($source['rows'][45])->toBe(['5922', 'Gastos de años anteriores']);
});

it('pins the five distinct PLE acquisition classification codes', function (): void {
    $path = __DIR__.'/../../database/reference_data/ple_table_30_acquisition_class_sunat_anexo_3_2015.json';
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $path))->toBe('240052dfc21aeb9f5889128e23310ab3a0428f71da7bf1eab63054a0cc842691')
        ->and($source['source_pdf_sha256'])->toBe('05e7a3ddcadd09354d367b1f876f070ba70ce1da2ec0abbfbda355e5b54600d6')
        ->and($source['table_number'])->toBe('30')
        ->and(array_column($source['rows'], 0))->toBe(['1', '2', '3', '4', '5'])
        ->and($source['rows'][1])->toBe(['2', 'ACTIVO FIJO'])
        ->and($source['rows'][4])->toBe(['5', 'OTROS GASTOS NO INCLUIDOS EN EL NUMERAL 4']);
});

it('pins the three PLE service modalities separately from SIRE table 20', function (): void {
    $path = __DIR__.'/../../database/reference_data/ple_table_32_nonresident_service_sunat_anexo_3_2015.json';
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $path))->toBe('24b9f10ad3d71aac6a6b02aea39038a3cb5a68d22081a9a8c12935b0c521972d')
        ->and($source['source_pdf_sha256'])->toBe('05e7a3ddcadd09354d367b1f876f070ba70ce1da2ec0abbfbda355e5b54600d6')
        ->and($source['table_number'])->toBe('32')
        ->and(array_column($source['rows'], 0))->toBe(['1', '2', '3'])
        ->and($source['rows'][1])->toBe(['2', 'SERVICIO PRESTADO PARTE EN EL PERÚ Y PARTE EN EL EXTRANJERO']);
});

it('pins the six historical PLE exemption codes without asserting present-day eligibility', function (): void {
    $path = __DIR__.'/../../database/reference_data/ple_table_33_nonresident_exemptions_sunat_anexo_3_2015.json';
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    expect(hash_file('sha256', $path))->toBe('5566f634e6f45c8657b7471f4fae00fc732b7af4103c58e5846bc9ca1286b783')
        ->and($source['source_pdf_sha256'])->toBe('05e7a3ddcadd09354d367b1f876f070ba70ce1da2ec0abbfbda355e5b54600d6')
        ->and($source['table_number'])->toBe('33')
        ->and($source['classification'])->toBe('SUNAT_PLE_2015_ANNEX_3_HISTORICAL_REFERENCE')
        ->and(array_column($source['rows'], 0))->toBe(['1', '2', '3', '4', '5', '6'])
        ->and($source['rows'][5][1])->toContain('brutos que perciben las representaciones de países.');
});

it('pins all thirty PLE customs locations without borrowing the SIRE customs table', function (): void {
    $path = __DIR__.'/../../database/reference_data/ple_table_11_sunat_anexo_3_2015.json';
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $codes = array_column($source['rows'], 0);
    expect(hash_file('sha256', $path))->toBe('cda6985f84f93f9d5874c77307793e360da7354f0ca06c69a40d4c81b0776cbb')
        ->and($source['table_number'])->toBe('11')
        ->and($source['rows'])->toHaveCount(30)
        ->and(array_unique($codes))->toHaveCount(30)
        ->and($codes)->toContain('884', '893', '910', '965')
        ->and($codes)->not->toContain('000', '037', '974');
});

it('pins sixty-one PLE Annex 3 document types independently of SEE and SIRE', function (): void {
    $path = __DIR__.'/../../database/reference_data/ple_table_10_sunat_anexo_3_2015.json';
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $codes = array_column($source['rows'], 0);
    expect(hash_file('sha256', $path))->toBe('3d06ee30c7ae9827d402ca85f66a6b72cdd618637f9a64425618cf0b6b628973')
        ->and($source['table_number'])->toBe('10')
        ->and($source['rows'])->toHaveCount(61)
        ->and(array_unique($codes))->toHaveCount(61)
        ->and($codes)->toContain('11', '40', '48', '56', '87', '89', '96')
        ->and($codes)->not->toContain('47', '57');
});

it('pins the distinct SUNAT Table 8 and the 2008 addition without inventing PLE obligations', function (): void {
    $path = __DIR__.'/../../database/reference_data/sunat_table_08_rs234_2006_rs239_2008.json';
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $codes = array_column($source['rows'], 0);
    expect(hash_file('sha256', $path))->toBe('4b715c3134df8fb22c3a5e487d9824a67e6ae16327403a39991326583654d573')
        ->and($source['source_xls_sha256'])->toBe('e036ba3a62a2043bd6acd87776167ff72fbee77b4ce3811f29996ef41a882f68')
        ->and($source['amendment_pdf_sha256'])->toBe('8133e965817f15130bf8dd48c9e65056058ee51bec09c8ecb2a8888c39c0cb44')
        ->and($source['table_number'])->toBe('08')
        ->and($source['classification'])->toBe('SUNAT_RS_234_2006_TABLE_8_WITH_RS_239_2008_AMENDMENT_REFERENCE')
        ->and($source['rows'])->toHaveCount(32)
        ->and(array_unique($codes))->toHaveCount(32)
        ->and($codes)->toBe(array_merge(array_map('strval', range(1, 5)), ['5-A'], array_map('strval', range(6, 31))))
        ->and(array_column($source['rows'], 1, 0)['5-A'])->toBe('LIBRO DIARIO DE FORMATO SIMPLIFICADO');
});

it('pins each reconciled annex fixture and its added code', function (): void {
    foreach ([
        ['15', '2017_anexo_e', '9f96ed5017b3d7eca4fdb81b8c89ad275d68e743abe5b4c9a4357a95635d26e6', 41, '2008'],
        ['19', '2017_anexo_e', 'f68f519e0fd7a1f881d203f74cba86a9f87f2c77c5cb80331d843eb93d53e84d', 4, '4'],
        ['55', '2026_anexo_i', '217cc40eee6a0eeb58b6fa8cb4caa315011ca8a71c9e3c874c4202adb246fc35', 119, '7029'],
    ] as [$number, $suffix, $sha, $count, $code]) {
        $path = __DIR__."/../../database/reference_data/see_catalog_{$number}_{$suffix}.json";
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        expect(hash_file('sha256', $path))->toBe($sha)
            ->and($source['catalog_number'])->toBe($number)
            ->and($source['rows'])->toHaveCount($count)
            ->and(array_column($source['rows'], 0))->toContain($code);
    }
});

it('pins the official ISO 4217 List One edition while preserving the supplied currency snapshot', function (): void {
    $path = __DIR__.'/../../database/reference_data/see_catalog_02_iso_4217_2026_09_17.json';
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
    $path = __DIR__.'/../../database/reference_data/see_ubigeos_inei_2026.json';
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $old = json_decode((string) file_get_contents(__DIR__.'/../../database/reference_data/see_ubigeos_inei_snapshot.json'), true, 512, JSON_THROW_ON_ERROR);
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
    $path = __DIR__.'/../../database/reference_data/see_catalog_04_uncefact_2026_10_24.json';
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $codes = array_column($source['rows'], 0);
    expect(hash_file('sha256', $path))->toBe('53a93e2b6e79978dab273b628cb397f6abda16f579afcd6a911399eab2d0afea')
        ->and($source['source_csv_sha256'])->toBe('0a6ea77aaeec295438b9cb69c6c68186b332c64e6d4a34a64d66b29f37b9f87b')
        ->and($source['rows'])->toHaveCount(249)
        ->and(array_unique($codes))->toHaveCount(249)
        ->and($codes)->toContain('PE', 'AX')
        ->and($codes)->not->toContain('AN');
});

it('pins the full UNECE Rec. 20 revision 17 and retains its lifecycle flags', function (): void {
    $path = __DIR__.'/../../database/reference_data/see_catalog_03_unece_rec20_rev17_2021.json';
    $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $codes = array_column($source['rows'], 0);
    $statuses = array_count_values(array_column($source['rows'], 2));
    expect(hash_file('sha256', $path))->toBe('ad2796d90c3d7eaa645f2f835f61c9c858d96edad3c40e8dca34c6bbace94115')
        ->and($source['source_xlsx_sha256'])->toBe('994a85c3682e67604d42dbda3b35d672338a45b400f5397a96a4c5467ee8056d')
        ->and($source['rows'])->toHaveCount(2136)
        ->and(array_unique($codes))->toHaveCount(2136)
        ->and(array_column($source['rows'], 1, 0)['A11'])->toBe('angstrom')
        ->and($statuses['X'])->toBe(309)
        ->and($statuses['D'])->toBe(71)
        ->and($statuses['¦'])->toBe(1);
});
