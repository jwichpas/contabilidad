<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** UN/CEFACT country reference captured in 2026; distinct from an ISO publication. */
return new class extends Migration
{
    private const FILE = 'see_catalog_04_uncefact_2026_10_24.json';
    private const SHA256 = '53a93e2b6e79978dab273b628cb397f6abda16f579afcd6a911399eab2d0afea';
    private const CSV_SHA256 = '0a6ea77aaeec295438b9cb69c6c68186b332c64e6d4a34a64d66b29f37b9f87b';
    private const URL = 'https://test.uncefact.org/vocabulary/country-id/index.csv';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Referencia UN/CEFACT de países ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $source['rows'] ?? [];
        $codes = array_column($rows, 0);
        if (($source['catalog_number'] ?? null) !== '04'
            || ($source['version'] ?? null) !== '2026-10-24-uncefact'
            || ($source['classification'] ?? null) !== 'UNCEFACT_COUNTRY_2026_10_24_REFERENCE'
            || ($source['source_url'] ?? null) !== self::URL
            || ($source['source_csv_sha256'] ?? null) !== self::CSV_SHA256
            || ($source['captured_at'] ?? null) !== '2026-10-24'
            || count($rows) !== 249 || count(array_unique($codes)) !== 249
            || ! in_array('PE', $codes, true) || in_array('AN', $codes, true)
            || count(array_filter($rows, static fn ($row): bool => count($row) === 2
                && preg_match('/^[A-Z]{2}$/D', $row[0]) === 1)) !== 249) {
            throw new RuntimeException('La edición UN/CEFACT de países no coincide con la fuente cotejada.');
        }

        $db = DB::connection('pgsql_migrations');
        $db->table('see_catalog_versions')->insert([
            'catalog_number' => '04', 'version' => $source['version'], 'name' => $source['name'],
            'classification' => $source['classification'], 'source_sha256' => self::SHA256,
            'source_url' => self::URL, 'item_count' => 249,
        ]);
        foreach (array_chunk($rows, 100, true) as $chunk) {
            $db->table('see_catalog_version_items')->insert(array_map(
                static fn (array $row, int $offset): array => [
                    'catalog_number' => '04', 'version' => '2026-10-24-uncefact',
                    'code' => $row[0], 'description' => $row[1], 'source_row' => $offset + 1,
                ], $chunk, array_keys($chunk)
            ));
        }
    }

    public function down(): void
    {
        $db = DB::connection('pgsql_migrations');
        $db->table('see_catalog_version_items')->where('catalog_number', '04')
            ->where('version', '2026-10-24-uncefact')->delete();
        $db->table('see_catalog_versions')->where('catalog_number', '04')
            ->where('version', '2026-10-24-uncefact')->delete();
    }
};
