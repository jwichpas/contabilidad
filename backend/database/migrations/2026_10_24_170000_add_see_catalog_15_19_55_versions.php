<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Append verified annex editions; the supplied historical snapshot remains untouched. */
return new class extends Migration
{
    private const SOURCES = [
        '15' => ['see_catalog_15_2017_anexo_e.json', '9f96ed5017b3d7eca4fdb81b8c89ad275d68e743abe5b4c9a4357a95635d26e6', '2017-anexo-e', 41, '2008', 'https://www.sunat.gob.pe/legislacion/superin/2017/anexoE-245-2017.pdf'],
        '19' => ['see_catalog_19_2017_anexo_e.json', 'f68f519e0fd7a1f881d203f74cba86a9f87f2c77c5cb80331d843eb93d53e84d', '2017-anexo-e', 4, '4', 'https://www.sunat.gob.pe/legislacion/superin/2017/anexoE-245-2017.pdf'],
        '55' => ['see_catalog_55_2026_anexo_i.json', '217cc40eee6a0eeb58b6fa8cb4caa315011ca8a71c9e3c874c4202adb246fc35', '2026-anexo-i', 119, '7029', 'https://www.sunat.gob.pe/legislacion/superin/2026/anexo-000108-2026.pdf'],
    ];

    public function up(): void
    {
        $db = DB::connection('pgsql_migrations');
        foreach (self::SOURCES as $number => [$filename, $sha, $version, $expectedCount, $requiredCode, $sourceUrl]) {
            $number = (string) $number;
            $path = database_path('reference_data/'.$filename);
            if (! is_file($path) || ! hash_equals($sha, hash_file('sha256', $path))) {
                throw new RuntimeException("Fuente SUNAT SEE {$number} ausente o modificada.");
            }
            $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $rows = $source['rows'] ?? [];
            $codes = array_column($rows, 0);
            if (($source['catalog_number'] ?? null) !== $number
                || ($source['version'] ?? null) !== $version
                || ($source['source_url'] ?? null) !== $sourceUrl
                || ! in_array($source['classification'] ?? null, ['SUNAT_ANNEX_E_2017_REFERENCE', 'SUNAT_ANNEX_I_2026_REFERENCE'], true)
                || count($rows) !== $expectedCount || count(array_unique($codes)) !== $expectedCount
                || ! in_array($requiredCode, $codes, true)) {
                throw new RuntimeException("Anexo SUNAT SEE {$number} no coincide con la versión cotejada.");
            }

            $db->table('see_catalog_versions')->insert([
                'catalog_number' => $number, 'version' => $version, 'name' => $source['name'],
                'classification' => $source['classification'], 'source_sha256' => $sha,
                'source_url' => $sourceUrl, 'item_count' => $expectedCount,
            ]);
            foreach (array_chunk($rows, 100, true) as $chunk) {
                $db->table('see_catalog_version_items')->insert(array_map(
                    static fn (array $row, int $offset): array => [
                        'catalog_number' => $number, 'version' => $version, 'code' => $row[0],
                        'description' => $row[1], 'source_row' => $offset + 1,
                    ], $chunk, array_keys($chunk)
                ));
            }
        }
    }

    public function down(): void
    {
        $db = DB::connection('pgsql_migrations');
        foreach (self::SOURCES as $number => [, , $version]) {
            $db->table('see_catalog_version_items')->where('catalog_number', $number)
                ->where('version', $version)->delete();
            $db->table('see_catalog_versions')->where('catalog_number', $number)
                ->where('version', $version)->delete();
        }
    }
};
