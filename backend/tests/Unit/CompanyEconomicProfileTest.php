<?php

declare(strict_types=1);

use App\Support\Company\CompanyEconomicProfile;
use Illuminate\Validation\ValidationException;

function activity(string $ciiu, bool $primary = false, string $sector = 'COMERCIAL'): array
{
    return [
        'sector_code' => $sector,
        'ciiu_code' => $ciiu,
        'description' => 'Actividad económica registrada',
        'is_primary' => $primary,
    ];
}

it('accepts one main CIIU and multiple independent secondary activities', function (): void {
    CompanyEconomicProfile::assertValidActivities([
        activity('4752', true),
        activity('4923', false, 'TRANSPORTE_LOGISTICA'),
        activity('0141', false, 'GANADERIA'),
    ]);
    expect(true)->toBeTrue();
});

it('rejects zero or two primary economic activities', function (): void {
    expect(fn () => CompanyEconomicProfile::assertValidActivities([
        activity('4752'), activity('4923'),
    ]))->toThrow(ValidationException::class);
    expect(fn () => CompanyEconomicProfile::assertValidActivities([
        activity('4752', true), activity('4923', true),
    ]))->toThrow(ValidationException::class);
});

it('rejects duplicated CIIU codes regardless of sector', function (): void {
    expect(fn () => CompanyEconomicProfile::assertValidActivities([
        activity('4752', true), activity('4752', false, 'INDUSTRIAL'),
    ]))->toThrow(ValidationException::class);
});

it('rejects malformed CIIU, unknown sector and blank description', function (): void {
    expect(fn () => CompanyEconomicProfile::assertValidActivities([
        activity('A752', true),
    ]))->toThrow(ValidationException::class);
    expect(fn () => CompanyEconomicProfile::assertValidActivities([
        activity('4752', true, 'INVALIDO'),
    ]))->toThrow(ValidationException::class);
    expect(fn () => CompanyEconomicProfile::assertValidActivities([
        ['sector_code' => 'COMERCIAL', 'ciiu_code' => '4752', 'description' => '   ', 'is_primary' => true],
    ]))->toThrow(ValidationException::class);
});

it('allows NRUS only for a natural person and leaves other regimes independent of sector', function (): void {
    CompanyEconomicProfile::assertTaxRegimeEligibility('NRUS', 'PERSONA_NATURAL');
    CompanyEconomicProfile::assertTaxRegimeEligibility('GENERAL', 'SAC');
    expect(fn () => CompanyEconomicProfile::assertTaxRegimeEligibility('NRUS', 'SAC'))
        ->toThrow(ValidationException::class);
    expect(fn () => CompanyEconomicProfile::assertTaxRegimeEligibility('NRUS', null))
        ->toThrow(ValidationException::class);
});
