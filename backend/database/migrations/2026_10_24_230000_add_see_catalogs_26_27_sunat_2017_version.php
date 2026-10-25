<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Adds annex-only SEE catalogs; no rows are invented in the supplied historical snapshot. */
return new class extends Migration
{
    private const FILE = 'see_catalogs_26_27_sunat_anexo_v_2017.json';
    private const SHA256 = '6a1d1cd6aeba9a27e212bb3e8a216a6fc19bcc98df539cd8703260ea93b34f58';
    private const URL = 'https://www.sunat.gob.pe/legislacion/superin/2017/anexosV-318-2017.pdf';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Fuente SUNAT SEE 26/27 ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (($source['version'] ?? null) !== '2017-12-anexo-v'
            || ($source['classification'] ?? null) !== 'SUNAT_ANNEX_V_2017_REFERENCE'
            || ($source['source_url'] ?? null) !== self::URL
            || ($source['source_pages'] ?? null) !== [9, 10]
            || array_column($source['catalogs'] ?? [], 'number') !== ['26', '27']) {
            throw new RuntimeException('Identidad de fuente SUNAT SEE 26/27 inesperada.');
        }

        $db = DB::connection('pgsql_migrations');
        foreach ($source['catalogs'] as $catalog) {
            $number = $catalog['number'];
            $rows = $catalog['rows'] ?? [];
            $expectedCodes = $number === '26' ? ['0', '1', '2'] : ['0', '1', '2', '3'];
            if (array_column($rows, 0) !== $expectedCodes
                || count(array_filter($rows, static fn ($row): bool => count($row) === 2
                    && is_string($row[1]) && trim($row[1]) !== '')) !== count($expectedCodes)) {
                throw new RuntimeException("Códigos SUNAT SEE {$number} incompletos o duplicados.");
            }

            $db->table('see_catalogs')->insert([
                'number' => $number, 'name' => $catalog['name'],
                'source_sha256' => self::SHA256,
                'classification' => 'NO_SUPPLIED_SEE_SNAPSHOT',
                'item_count' => 0,
            ]);
            $db->table('see_catalog_versions')->insert([
                'catalog_number' => $number, 'version' => $source['version'],
                'name' => $catalog['name'], 'classification' => $source['classification'],
                'source_sha256' => self::SHA256, 'source_url' => self::URL,
                'item_count' => count($rows),
            ]);
            $db->table('see_catalog_version_items')->insert(array_map(
                static fn (array $row, int $offset): array => [
                    'catalog_number' => $number, 'version' => '2017-12-anexo-v',
                    'code' => $row[0], 'description' => $row[1], 'source_row' => $offset + 1,
                ], $rows, array_keys($rows)
            ));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Las ediciones SEE publicadas requieren una migración compensatoria revisada.');
    }
};
