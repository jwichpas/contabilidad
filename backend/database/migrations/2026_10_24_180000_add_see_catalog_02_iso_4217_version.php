<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** ISO 4217 List One published by its official maintenance agency, kept separate from PLE/SIRE. */
return new class extends Migration
{
    private const FILE = 'see_catalog_02_iso_4217_2026_09_17.json';
    private const SHA256 = '206b0560785fcaf9951e78472dd63e28032e326211b9e73b1242e530944cb681';
    private const XML_SHA256 = '33139b438657d1cee116ba737807ea71d19d6de4b90f799a09c56f0cc6a1b0ff';
    private const URL = 'https://www.six-group.com/dam/download/financial-information/data-center/iso-currrency/lists/list-one.xml';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('La lista ISO 4217 verificada está ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $source['rows'] ?? [];
        $codes = array_column($rows, 0);
        $byCode = array_column($rows, 1, 0);
        if (($source['catalog_number'] ?? null) !== '02'
            || ($source['version'] ?? null) !== '2026-09-17-list-one'
            || ($source['classification'] ?? null) !== 'ISO_4217_LIST_ONE_2026_09_17_REFERENCE'
            || ($source['source_url'] ?? null) !== self::URL
            || ($source['source_published_at'] ?? null) !== '2026-09-17'
            || ($source['source_xml_sha256'] ?? null) !== self::XML_SHA256
            || count($rows) !== 178 || count(array_unique($codes)) !== 178
            || ($byCode['PEN'] ?? null) !== 'Sol'
            || ! in_array('XTS', $codes, true) || in_array('BGN', $codes, true)
            || count(array_filter($rows, static fn ($row): bool => count($row) === 4)) !== 178) {
            throw new RuntimeException('La edición ISO 4217 no coincide con la lista cotejada.');
        }

        Schema::connection('pgsql_migrations')->table('see_catalog_version_items', static function (Blueprint $table): void {
            $table->jsonb('details')->nullable();
        });
        $db = DB::connection('pgsql_migrations');
        $db->table('see_catalog_versions')->insert([
            'catalog_number' => '02', 'version' => $source['version'], 'name' => $source['name'],
            'classification' => $source['classification'], 'source_sha256' => self::SHA256,
            'source_url' => self::URL, 'item_count' => 178,
        ]);
        foreach (array_chunk($rows, 100, true) as $chunk) {
            $db->table('see_catalog_version_items')->insert(array_map(
                static fn (array $row, int $offset): array => [
                    'catalog_number' => '02', 'version' => '2026-09-17-list-one',
                    'code' => $row[0], 'description' => $row[1],
                    'details' => json_encode(['Número ISO' => $row[2], 'Unidad menor' => $row[3]], JSON_THROW_ON_ERROR),
                    'source_row' => $offset + 1,
                ], $chunk, array_keys($chunk)
            ));
        }
    }

    public function down(): void
    {
        $db = DB::connection('pgsql_migrations');
        $db->table('see_catalog_version_items')->where('catalog_number', '02')
            ->where('version', '2026-09-17-list-one')->delete();
        $db->table('see_catalog_versions')->where('catalog_number', '02')
            ->where('version', '2026-09-17-list-one')->delete();
        Schema::connection('pgsql_migrations')->table('see_catalog_version_items', static function (Blueprint $table): void {
            $table->dropColumn('details');
        });
    }
};
