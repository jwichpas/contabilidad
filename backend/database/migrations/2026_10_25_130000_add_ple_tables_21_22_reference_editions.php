<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Publish two PLE Annex 3 references without touching company financial statements. */
return new class extends Migration
{
    private const FILE = 'ple_tables_21_22_sunat_anexo_3_2015.json';
    private const SHA256 = 'b47a542ef05cc9a7c202e5731a12c987790407a9b8f182436a254499183fe14e';
    private const URL = 'https://www.sunat.gob.pe/legislacion/superin/2015/anexo3-rs169-2015.pdf';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Fuente PLE 21/22 ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $tables = $source['tables'] ?? [];
        if (($source['version'] ?? null) !== '2015-06-anexo-3'
            || ($source['classification'] ?? null) !== 'SUNAT_PLE_2015_ANNEX_3_HISTORICAL_REFERENCE'
            || ($source['source_url'] ?? null) !== self::URL
            || ($source['source_pdf_sha256'] ?? null) !== '05e7a3ddcadd09354d367b1f876f070ba70ce1da2ec0abbfbda355e5b54600d6'
            || array_column($tables, 'number') !== ['21', '22']
            || array_map(static fn (array $table): int => count($table['rows']), $tables) !== [5, 9]
            || ($tables[0]['rows'] ?? null) !== [
                ['1', 'PROCESO PRODUCTIVO'], ['2', 'LÍNEA DE PRODUCCIÓN'],
                ['3', 'PRODUCTO'], ['4', 'PROYECTO'], ['9', 'OTROS'],
            ]
            || ($tables[1]['rows'][1] ?? null) !== ['02', 'SUPERINTENDENCIA DEL MERCADO DE VALORES - SECTOR SEGUROS - INDIVIDUAL']
            || ($tables[1]['rows'][8] ?? null) !== ['09', 'OTROS NO CONSIDERADOS EN LOS ANTERIORES']) {
            throw new RuntimeException('Las Tablas 21/22 PLE no coinciden con el anexo cotejado.');
        }

        $db = DB::connection('pgsql_migrations');
        foreach ($tables as $reference) {
            $codes = array_column($reference['rows'], 0);
            if (count($codes) !== count(array_unique($codes))) {
                throw new RuntimeException('Códigos PLE duplicados en tabla '.$reference['number']);
            }
            $db->table('ple_annex3_reference_versions')->insert([
                'table_number' => $reference['number'], 'version' => $source['version'],
                'name' => $reference['name'], 'classification' => $source['classification'],
                'source_url' => self::URL, 'source_sha256' => self::SHA256,
                'item_count' => count($reference['rows']),
            ]);
            $db->table('ple_annex3_reference_items')->insert(array_map(static fn (array $row): array => [
                'table_number' => $reference['number'], 'version' => $source['version'],
                'code' => $row[0], 'name' => $row[1], 'is_active' => true,
            ], $reference['rows']));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Las referencias PLE 21/22 requieren una migración compensatoria revisada.');
    }
};
