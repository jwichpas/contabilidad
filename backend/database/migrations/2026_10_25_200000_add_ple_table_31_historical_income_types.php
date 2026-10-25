<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Code references only; these 2015 income summaries do not calculate tax. */
return new class extends Migration
{
    private const FILE = 'ple_table_31_income_type_sunat_anexo_3_2015.json';
    private const SHA256 = '9b8c5d00367dc8efbe0bb0a087e8be6a215b3895f0960facb6ee95ad335885f4';
    private const URL = 'https://www.sunat.gob.pe/legislacion/superin/2015/anexo3-rs169-2015.pdf';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Fuente PLE 31 ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $source['rows'] ?? [];
        $codes = array_map(static fn (int $i): string => sprintf('%02d', $i), range(0, 43));
        if (($source['version'] ?? null) !== '2015-06-anexo-3'
            || ($source['classification'] ?? null) !== 'SUNAT_PLE_2015_ANNEX_3_HISTORICAL_REFERENCE'
            || ($source['source_url'] ?? null) !== self::URL
            || ($source['source_pdf_sha256'] ?? null) !== '05e7a3ddcadd09354d367b1f876f070ba70ce1da2ec0abbfbda355e5b54600d6'
            || ($source['table_number'] ?? null) !== '31'
            || ($source['name'] ?? null) !== 'Tipo de renta'
            || array_column($rows, 0) !== $codes
            || ($rows[18] ?? null) !== ['18', 'Servicios digitales utilizados económicamente, usados o consumidos en el país']
            || ($rows[43][0] ?? null) !== '43') {
            throw new RuntimeException('Códigos PLE 31 ajenos al anexo cotejado.');
        }

        $db = DB::connection('pgsql_migrations');
        $db->table('ple_annex3_reference_versions')->insert([
            'table_number' => '31', 'version' => $source['version'],
            'name' => $source['name'], 'classification' => $source['classification'],
            'source_url' => self::URL, 'source_sha256' => self::SHA256,
            'item_count' => count($rows),
        ]);
        $db->table('ple_annex3_reference_items')->insert(array_map(static fn (array $row): array => [
            'table_number' => '31', 'version' => $source['version'],
            'code' => $row[0], 'name' => $row[1], 'is_active' => true,
        ], $rows));
    }

    public function down(): void
    {
        throw new RuntimeException('La referencia PLE 31 requiere una migración compensatoria revisada.');
    }
};
