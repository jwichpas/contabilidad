<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** SUNAT's four-digit country list is separate from SEE and SIRE country codes. */
return new class extends Migration
{
    private const FILE = 'ple_table_35_country_codes_sunat_portal_2026_10_25.json';
    private const SHA256 = 'ad22bc5e829501e37b93459695b7acd1902bdb40fe92317dab01306b03c12a24';
    private const URL = 'https://www2.sunat.gob.pe/pdt/pdtModulos/independientes/p695/CodPais.htm';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Fuente PLE 35 ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $source['rows'] ?? [];
        $codes = array_column($rows, 0);
        if (($source['version'] ?? null) !== '2026-10-25-sunat-portal-capture'
            || ($source['classification'] ?? null) !== 'SUNAT_PLE_35_COUNTRY_PORTAL_SNAPSHOT_REFERENCE'
            || ($source['source_url'] ?? null) !== self::URL
            || ($source['source_html_sha256'] ?? null) !== '0e39943233b72e3ca3dff4e542259223e3d9cea339bc4f119afa921eea37c25b'
            || ($source['annex_2015_pdf_sha256'] ?? null) !== '05e7a3ddcadd09354d367b1f876f070ba70ce1da2ec0abbfbda355e5b54600d6'
            || ($source['table_number'] ?? null) !== '35'
            || count($rows) !== 273 || count(array_unique($codes)) !== 273
            || ($rows[0] ?? null) !== ['9001', 'BOUVET ISLAND']
            || ($rows[272] ?? null) !== ['9897', 'ZONA NEUTRAL (PALESTINA)']) {
            throw new RuntimeException('La Tabla 35 PLE no coincide con la captura cotejada.');
        }
        foreach ($rows as $row) {
            if (count($row) !== 2 || ! preg_match('/^9[0-9]{3}$/', $row[0])
                || ! is_string($row[1]) || $row[1] === '') {
                throw new RuntimeException('Código o nombre PLE 35 inválido.');
            }
        }

        $db = DB::connection('pgsql_migrations');
        $db->table('ple_annex3_reference_versions')->insert([
            'table_number' => '35', 'version' => $source['version'],
            'name' => $source['name'], 'classification' => $source['classification'],
            'source_url' => self::URL, 'source_sha256' => self::SHA256,
            'item_count' => count($rows),
        ]);
        foreach (array_chunk($rows, 100) as $chunk) {
            $db->table('ple_annex3_reference_items')->insert(array_map(static fn (array $row): array => [
                'table_number' => '35', 'version' => $source['version'],
                'code' => $row[0], 'name' => $row[1], 'is_active' => true,
            ], $chunk));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('La referencia PLE 35 requiere una migración compensatoria revisada.');
    }
};
