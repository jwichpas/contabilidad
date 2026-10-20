<?php

declare(strict_types=1);

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
