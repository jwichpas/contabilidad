<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** SEE catalog 59 uses a different code space from PLE/SIRE payment-method table 01. */
return new class extends Migration
{
    private const FILE = 'see_catalog_59_sunat_anexo_v_2017.json';
    private const SHA256 = '5b992cc5622e8218258ad7c8bc718cfbc1c3a5710c7a621d9b3276e3584f0315';
    private const URL = 'https://www.sunat.gob.pe/legislacion/superin/2017/anexosV-318-2017.pdf';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Fuente SUNAT SEE 59 ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $source['rows'] ?? [];
        if (($source['catalog_number'] ?? null) !== '59'
            || ($source['version'] ?? null) !== '2017-12-anexo-v'
            || ($source['classification'] ?? null) !== 'SUNAT_ANNEX_V_2017_REFERENCE'
            || ($source['source_url'] ?? null) !== self::URL
            || count($rows) !== 22
            || array_column($rows, 0) !== [
                '001', '002', '003', '004', '005', '006', '007', '008', '009', '010', '011', '012', '013',
                '101', '102', '103', '104', '105', '106', '107', '108', '999',
            ]
            || $rows[10][1] !== 'DOCUMENTOS EMITIDOS POR LAS EDPYMES Y LAS COOPERATIVAS DE AHORRO Y CRÉDITO NO AUTORIZADAS A CAPTAR DEPÓSITOS DEL PÚBLICO') {
            throw new RuntimeException('Códigos SUNAT SEE 59 incompletos o mezclados con PLE 01.');
        }

        $db = DB::connection('pgsql_migrations');
        $db->table('see_catalogs')->insert([
            'number' => '59', 'name' => $source['name'],
            'source_sha256' => self::SHA256,
            'classification' => 'NO_SUPPLIED_SEE_SNAPSHOT',
            'item_count' => 0,
        ]);
        $db->table('see_catalog_versions')->insert([
            'catalog_number' => '59', 'version' => $source['version'],
            'name' => $source['name'], 'classification' => $source['classification'],
            'source_sha256' => self::SHA256, 'source_url' => self::URL,
            'item_count' => count($rows),
        ]);
        $db->table('see_catalog_version_items')->insert(array_map(
            static fn (array $row, int $offset): array => [
                'catalog_number' => '59', 'version' => '2017-12-anexo-v',
                'code' => $row[0], 'description' => $row[1], 'source_row' => $offset + 1,
            ], $rows, array_keys($rows)
        ));
    }

    public function down(): void
    {
        throw new RuntimeException('La edición SEE 59 requiere una migración compensatoria revisada.');
    }
};
