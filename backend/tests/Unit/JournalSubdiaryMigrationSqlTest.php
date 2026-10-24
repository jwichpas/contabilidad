<?php

declare(strict_types=1);

it('uses a balanced PostgreSQL dollar quote for the immutable journal trigger', function (): void {
    // A pure Unit test does not bootstrap the Laravel Application instance.
    // Locate the migration relative to backend/tests/Unit, not base_path().
    $migrationPath = dirname(__DIR__, 2).'/database/migrations/2026_10_24_130000_add_journal_entry_subdiary.php';
    expect(is_file($migrationPath))->toBeTrue();

    $source = file_get_contents($migrationPath);
    expect($source)->toBeString();

    $delimiter = '$journal_immutable_guard$';
    expect(substr_count($source, $delimiter))->toBe(2);
    expect($source)->toContain(
        'RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog AS '.$delimiter
    );
    expect($source)->toContain($delimiter.';');
});
