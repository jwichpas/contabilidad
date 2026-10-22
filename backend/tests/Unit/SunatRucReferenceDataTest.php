<?php

declare(strict_types=1);

use App\Support\Company\CompanyEconomicProfile;
use Illuminate\Validation\ValidationException;

it('keeps the three SUNAT RUC source files complete and independently versioned', function (): void {
    $expected = [
        'sunat_contributor_types_2026_10_22.json'=>['sunat_tipo_contribuyente',51,2],
        'sunat_ciiu_rev4_2026_10_22.json'=>['sunat_ciiu_rev4',417,4],
        'sunat_tax_codes_2026_10_22.json'=>['sunat_codigos_tributos',38,4],
    ];
    foreach($expected as $name=>[$dataset,$count,$digits]){
        $source=json_decode(
            (string)file_get_contents(database_path('reference_data/'.$name)),
            true,512,JSON_THROW_ON_ERROR
        );
        expect($source['dataset'])->toBe($dataset)
            ->and($source['version'])->toBe('2026-10-22-v1')
            ->and($source['rows'])->toHaveCount($count);
        $codes=array_column($source['rows'],'code');
        expect(count(array_unique($codes)))->toBe($count);
        foreach($codes as $code){
            expect($code)->toMatch('/^[0-9]{'.$digits.'}$/');
        }
    }
});

it('preserves meaningful SUNAT and INEI codes and leading zeros',function():void {
    $types=json_decode((string)file_get_contents(database_path('reference_data/sunat_contributor_types_2026_10_22.json')),true);
    $ciiu=json_decode((string)file_get_contents(database_path('reference_data/sunat_ciiu_rev4_2026_10_22.json')),true);
    $tax=json_decode((string)file_get_contents(database_path('reference_data/sunat_tax_codes_2026_10_22.json')),true);
    expect(collect($types['rows'])->firstWhere('code','07')['name'])->toContain('EMPRESA INDIVIDUAL')
        ->and(collect($types['rows'])->firstWhere('code','39')['name'])->toContain('SOCIEDAD ANONIMA CERRADA')
        ->and(collect($ciiu['rows'])->firstWhere('code','4923')['name'])->toBe('TRANSPORTE DE CARGA POR CARRETERA')
        ->and(collect($ciiu['rows'])->firstWhere('code','0111')['division'])->toBe('01')
        ->and(collect($tax['rows'])->firstWhere('code','1011')['short_name'])->toBe('IGVICP')
        ->and(collect($tax['rows'])->firstWhere('code','3031')['short_name'])->toBe('RTA3CP');
});

it('rejects contributor codes inconsistent with known legal forms',function():void {
    CompanyEconomicProfile::assertContributorTypeMatchesLegalForm('EIRL','07');
    CompanyEconomicProfile::assertContributorTypeMatchesLegalForm('SAC','39');
    expect(fn()=>CompanyEconomicProfile::assertContributorTypeMatchesLegalForm('EIRL','39'))
        ->toThrow(ValidationException::class);
});
