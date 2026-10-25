<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Archive the 2015 PLE exemption codes; never use these rows to grant tax treatment. */
return new class extends Migration
{
    private const FILE = 'ple_table_33_nonresident_exemptions_sunat_anexo_3_2015.json';
    private const SHA256 = '5566f634e6f45c8657b7471f4fae00fc732b7af4103c58e5846bc9ca1286b783';
    private const URL = 'https://www.sunat.gob.pe/legislacion/superin/2015/anexo3-rs169-2015.pdf';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Fuente PLE 33 ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $source['rows'] ?? [];
        if (($source['version'] ?? null) !== '2015-06-anexo-3'
            || ($source['classification'] ?? null) !== 'SUNAT_PLE_2015_ANNEX_3_HISTORICAL_REFERENCE'
            || ($source['source_url'] ?? null) !== self::URL
            || ($source['source_pdf_sha256'] ?? null) !== '05e7a3ddcadd09354d367b1f876f070ba70ce1da2ec0abbfbda355e5b54600d6'
            || ($source['table_number'] ?? null) !== '33'
            || ($source['name'] ?? null) !== 'Exoneraciones de operaciones de no domiciliados (art. 19 de la Ley del Impuesto a la Renta)'
            || array_column($rows, 0) !== ['1', '2', '3', '4', '5', '6']
            || ! str_contains($rows[0][1] ?? '', 'créditos de fomento')
            || ! str_contains($rows[5][1] ?? '', 'brutos que perciben las representaciones de países.')) {
            throw new RuntimeException('La Tabla 33 PLE no coincide con el anexo cotejado.');
        }

        $db = DB::connection('pgsql_migrations');
        $db->table('ple_annex3_reference_versions')->insert([
            'table_number' => '33', 'version' => $source['version'],
            'name' => $source['name'], 'classification' => $source['classification'],
            'source_url' => self::URL, 'source_sha256' => self::SHA256,
            'item_count' => count($rows),
        ]);
        $db->table('ple_annex3_reference_items')->insert(array_map(static fn (array $row): array => [
            'table_number' => '33', 'version' => $source['version'],
            'code' => $row[0], 'name' => $row[1], 'is_active' => true,
        ], $rows));
    }

    public function down(): void
    {
        throw new RuntimeException('La referencia PLE 33 requiere una migración compensatoria revisada.');
    }
};
