<?php

declare(strict_types=1);

it('declares tenant RLS and a composite journal foreign key without rewriting journal rows', function (): void {
    $migration = dirname(__DIR__, 2).'/database/migrations/2026_10_24_140000_create_company_subdiaries.php';
    expect(is_file($migration))->toBeTrue();
    $source = file_get_contents($migration);
    expect($source)->toBeString()
        ->toContain("Schema::create('company_subdiaries'")
        ->toContain('FORCE ROW LEVEL SECURITY')
        ->toContain('fk_journal_subdiary_tenant_code')
        ->toContain('ON UPDATE RESTRICT ON DELETE RESTRICT')
        ->toContain('company_subdiaries_tenant_policy')
        ->not->toContain('UPDATE public.journal_entries SET');
});
