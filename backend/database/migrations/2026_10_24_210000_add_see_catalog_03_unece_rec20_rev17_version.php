<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Full Recommendation 20 Rev. 17 code table, retaining its source status flags. */
return new class extends Migration
{
    private const FILE = 'see_catalog_03_unece_rec20_rev17_2021.json';
    private const SHA256 = 'ad2796d90c3d7eaa645f2f835f61c9c858d96edad3c40e8dca34c6bbace94115';
    private const XLSX_SHA256 = '994a85c3682e67604d42dbda3b35d672338a45b400f5397a96a4c5467ee8056d';
    private const URL = 'https://unece.org/trade/documents/revision-17-annexes-i-iii';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('La edición UNECE Rev. 17 está ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $source['rows'] ?? [];
        $codes = array_column($rows, 0);
        $byCode = array_column($rows, 1, 0);
        $statuses = array_count_values(array_column($rows, 2));
        if (($source['catalog_number'] ?? null) !== '03'
            || ($source['version'] ?? null) !== '2021-rev17'
            || ($source['classification'] ?? null) !== 'UNECE_REC20_REV17_2021_REFERENCE'
            || ($source['source_url'] ?? null) !== self::URL
            || ($source['source_xlsx_sha256'] ?? null) !== self::XLSX_SHA256
            || ($source['source_sheet'] ?? null) !== 'Annex II & Annex III'
            || count($rows) !== 2136 || count(array_unique($codes)) !== 2136
            || ($byCode['A11'] ?? null) !== 'angstrom'
            || ($statuses['X'] ?? 0) !== 309 || ($statuses['D'] ?? 0) !== 71
            || ($statuses['¦'] ?? 0) !== 1
            || count(array_filter($rows, static fn ($row): bool => count($row) === 6
                && preg_match('/^[A-Z0-9]{1,3}$/D', $row[0]) === 1)) !== 2136) {
            throw new RuntimeException('La edición UNECE Rev. 17 no coincide con la fuente cotejada.');
        }

        $db = DB::connection('pgsql_migrations');
        $db->table('see_catalog_versions')->insert([
            'catalog_number' => '03', 'version' => '2021-rev17', 'name' => $source['name'],
            'classification' => $source['classification'], 'source_sha256' => self::SHA256,
            'source_url' => self::URL, 'item_count' => 2136,
        ]);
        foreach (array_chunk($rows, 100, true) as $chunk) {
            $db->table('see_catalog_version_items')->insert(array_map(
                static function (array $row, int $offset): array {
                    $details = array_filter([
                        'Estado fuente' => $row[2], 'Detalle' => $row[3],
                        'Nivel' => $row[4], 'Símbolo' => $row[5],
                    ], static fn (string $value): bool => $value !== '');

                    return [
                        'catalog_number' => '03', 'version' => '2021-rev17',
                        'code' => $row[0], 'description' => $row[1],
                        'details' => json_encode($details ?: new stdClass(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        'source_row' => $offset + 1,
                    ];
                }, $chunk, array_keys($chunk)
            ));
        }
    }

    public function down(): void
    {
        $db = DB::connection('pgsql_migrations');
        $db->table('see_catalog_version_items')->where('catalog_number', '03')
            ->where('version', '2021-rev17')->delete();
        $db->table('see_catalog_versions')->where('catalog_number', '03')
            ->where('version', '2021-rev17')->delete();
    }
};
