<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Archive PLE treaty codes as a read-only historical reference, not treaty eligibility. */
return new class extends Migration
{
    private const FILE = 'ple_table_25_treaties_sunat_anexo_3_2015.json';
    private const SHA256 = 'e84e81ab28b4bfeaea4e8887af7c918c9101b5173083ed334d2d5d834ddd776b';
    private const URL = 'https://www.sunat.gob.pe/legislacion/superin/2015/anexo3-rs169-2015.pdf';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Fuente PLE 25 ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $source['rows'] ?? [];
        if (($source['version'] ?? null) !== '2015-06-anexo-3'
            || ($source['classification'] ?? null) !== 'SUNAT_PLE_2015_ANNEX_3_HISTORICAL_REFERENCE'
            || ($source['source_url'] ?? null) !== self::URL
            || ($source['source_pdf_sha256'] ?? null) !== '05e7a3ddcadd09354d367b1f876f070ba70ce1da2ec0abbfbda355e5b54600d6'
            || ($source['table_number'] ?? null) !== '25'
            || ($source['name'] ?? null) !== 'Convenios para evitar la doble tributación'
            || array_column($rows, 0) !== ['00', '01', '02', '03', '04', '05', '06', '07', '08', '09']
            || ($rows[3] ?? null) !== ['03', 'COMUNIDAD ANDINA DE NACIONES (CAN)']
            || ($rows[9] ?? null) !== ['09', 'OTROS']) {
            throw new RuntimeException('La Tabla 25 PLE no coincide con el anexo cotejado.');
        }

        $db = DB::connection('pgsql_migrations');
        $db->table('ple_annex3_reference_versions')->insert([
            'table_number' => '25', 'version' => $source['version'],
            'name' => $source['name'], 'classification' => $source['classification'],
            'source_url' => self::URL, 'source_sha256' => self::SHA256,
            'item_count' => count($rows),
        ]);
        $db->table('ple_annex3_reference_items')->insert(array_map(static fn (array $row): array => [
            'table_number' => '25', 'version' => $source['version'],
            'code' => $row[0], 'name' => $row[1], 'is_active' => true,
        ], $rows));
    }

    public function down(): void
    {
        throw new RuntimeException('La referencia PLE 25 requiere una migración compensatoria revisada.');
    }
};
