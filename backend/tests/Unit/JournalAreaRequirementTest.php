<?php

declare(strict_types=1);

use App\Services\Accounting\JournalAreaRequirement;

it('enforces required active and recognized cash flow areas', function (): void {
    JournalAreaRequirement::validateCashFlow(false, 'NONE', null);
    expect(fn () => JournalAreaRequirement::validateCashFlow(true, 'OPERATING', null))->toThrow(DomainException::class);
    expect(fn () => JournalAreaRequirement::validateCashFlow(false, 'NONE', ['is_active' => false,
        'activity_category' => 'OPERATING']))->toThrow(DomainException::class);
    expect(fn () => JournalAreaRequirement::validateCashFlow(false, 'UNKNOWN', null))->toThrow(DomainException::class);
});

it('matches specific activity while allowing cash equivalent accounts to classify different activities', function (): void {
    foreach (['OPERATING', 'INVESTING', 'FINANCING', 'CASH_EQUIVALENT'] as $category) {
        $area = ['is_active' => true, 'activity_category' => $category];
        JournalAreaRequirement::validateCashFlow(true, $category, $area);
        JournalAreaRequirement::validateCashFlow(true, 'CASH_EQUIVALENT', $area);
        JournalAreaRequirement::validateCashFlow(false, 'NONE', $area);
    }
    expect(fn () => JournalAreaRequirement::validateCashFlow(true, 'INVESTING', [
        'is_active' => true, 'activity_category' => 'OPERATING']))->toThrow(DomainException::class);
    expect(fn () => JournalAreaRequirement::validateCashFlow(false, 'NONE', [
        'is_active' => true, 'activity_category' => 'UNKNOWN']))->toThrow(DomainException::class);
});
