<?php

declare(strict_types=1);

// These rules build Laravel ValidationException objects; boot Laravel only,
// WITHOUT DatabaseTransactions or SQL/Neon connections.
uses(\Tests\TestCase::class);

use App\Http\Controllers\Api\V1\Tenant\CreateCompanyFromWizardController;
use App\Support\Company\CompanyEconomicProfile;
use Illuminate\Validation\ValidationException;

it('checks the RUC check digit server side before company creation', function (): void {
    $method = new ReflectionMethod(CreateCompanyFromWizardController::class, 'isValidRuc');

    expect($method->invoke(null, '20100070970'))->toBeTrue()
        ->and($method->invoke(null, '20100070971'))->toBeFalse()
        ->and($method->invoke(null, '2010007097'))->toBeFalse()
        ->and($method->invoke(null, '00100070970'))->toBeFalse();
});

it('rejects a legal entity that attempts to use Nuevo RUS', function (): void {
    expect(fn () => CompanyEconomicProfile::assertTaxRegimeEligibility('NRUS', 'SAC'))
        ->toThrow(ValidationException::class);
    CompanyEconomicProfile::assertTaxRegimeEligibility('GENERAL', 'SAC');
    expect(true)->toBeTrue();
});


it('ties CIIU 4923 to transport instead of an arbitrary commercial sector', function (): void {
    expect(CompanyEconomicProfile::suggestedSectorForCiiu('4923'))->toBe('TRANSPORTE_LOGISTICA')
        ->and(CompanyEconomicProfile::suggestedSectorForCiiu('4752'))->toBe('COMERCIAL')
        ->and(CompanyEconomicProfile::suggestedSectorForCiiu('4100'))->toBe('CONSTRUCCION')
        ->and(CompanyEconomicProfile::suggestedSectorForCiiu('0111'))->toBeNull();

    $bad = [[
        'sector_code' => 'COMERCIAL',
        'ciiu_code' => '4923',
        'description' => 'TRANSPORTE DE CARGA POR CARRETERA',
        'is_primary' => true,
    ]];
    expect(fn () => CompanyEconomicProfile::assertValidActivities($bad))
        ->toThrow(ValidationException::class);

    $bad[0]['sector_code'] = 'TRANSPORTE_LOGISTICA';
    CompanyEconomicProfile::assertValidActivities($bad);
    expect(true)->toBeTrue();
});
