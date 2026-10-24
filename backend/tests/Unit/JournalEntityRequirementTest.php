<?php

declare(strict_types=1);

use App\Services\Accounting\JournalEntityRequirement;

it('requires an entity for explicit roles or the auxiliary flag', function (): void {
    JournalEntityRequirement::validate('NONE', false, null);
    expect(fn () => JournalEntityRequirement::validate('NONE', true, null))->toThrow(DomainException::class);
    expect(fn () => JournalEntityRequirement::validate('ANY_THIRD_PARTY', false, null))->toThrow(DomainException::class);
    expect(fn () => JournalEntityRequirement::validate('UNKNOWN', false, null))->toThrow(DomainException::class);
});

it('validates all account roles and rejects unexpected entities', function (): void {
    foreach (['CUSTOMER' => 'is_client', 'SUPPLIER' => 'is_supplier', 'EMPLOYEE' => 'is_employee',
        'SHAREHOLDER' => 'is_shareholder', 'FINANCIAL_INSTITUTION' => 'is_financial_institution',
        'TAX_AUTHORITY' => 'is_tax_authority'] as $type => $flag) {
        JournalEntityRequirement::validate($type, false, ['is_active' => true, $flag => true]);
        expect(fn () => JournalEntityRequirement::validate($type, false, ['is_active' => true, $flag => false]))
            ->toThrow(DomainException::class);
        expect(fn () => JournalEntityRequirement::validate($type, false, ['is_active' => false, $flag => true]))
            ->toThrow(DomainException::class);
    }
    expect(fn () => JournalEntityRequirement::validate('NONE', false, ['is_active' => true]))
        ->toThrow(DomainException::class);
    JournalEntityRequirement::validate('ANY_THIRD_PARTY', true, ['is_active' => true]);
});
