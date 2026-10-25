<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Archive the PLE equity taxonomy without changing any company chart of accounts. */
return new class extends Migration
{
    private const FILE = 'ple_table_28_net_equity_sunat_anexo_3_2015.json';
    private const SHA256 = '436450eac57a7b2c20b90658306e94660bbc040315dd44d02536232285e93dcc';
    private const URL = 'https://www.sunat.gob.pe/legislacion/superin/2015/anexo3-rs169-2015.pdf';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Fuente PLE 28 ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $source['rows'] ?? [];
        $codes = array_column($rows, 0);
        if (($source['version'] ?? null) !== '2015-06-anexo-3'
            || ($source['classification'] ?? null) !== 'SUNAT_PLE_2015_ANNEX_3_HISTORICAL_REFERENCE'
            || ($source['source_url'] ?? null) !== self::URL
            || ($source['source_pdf_sha256'] ?? null) !== '05e7a3ddcadd09354d367b1f876f070ba70ce1da2ec0abbfbda355e5b54600d6'
            || ($source['table_number'] ?? null) !== '28'
            || ($source['name'] ?? null) !== 'Patrimonio neto'
            || count($rows) !== 46
            || count(array_unique($codes)) !== 46
            || ($rows[0] ?? null) !== ['50', 'CAPITAL']
            || ($rows[16] ?? null) !== ['56', 'RESULTADOS NO REALIZADOS']
            || ($rows[45] ?? null) !== ['5922', 'Gastos de años anteriores']) {
            throw new RuntimeException('La Tabla 28 PLE no coincide con el anexo cotejado.');
        }

        $db = DB::connection('pgsql_migrations');
        $db->table('ple_annex3_reference_versions')->insert([
            'table_number' => '28', 'version' => $source['version'],
            'name' => $source['name'], 'classification' => $source['classification'],
            'source_url' => self::URL, 'source_sha256' => self::SHA256,
            'item_count' => count($rows),
        ]);
        $db->table('ple_annex3_reference_items')->insert(array_map(static fn (array $row): array => [
            'table_number' => '28', 'version' => $source['version'],
            'code' => $row[0], 'name' => $row[1], 'is_active' => true,
        ], $rows));
    }

    public function down(): void
    {
        throw new RuntimeException('La referencia PLE 28 requiere una migración compensatoria revisada.');
    }
};
