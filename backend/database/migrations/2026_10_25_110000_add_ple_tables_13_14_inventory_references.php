<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Publish two small Annex 3 references without activating inventory behavior. */
return new class extends Migration
{
    private const FILE = 'ple_tables_13_14_sunat_anexo_3_2015.json';
    private const SHA256 = 'c0ee978c7067d73296db31169eeb6c68b642488d2093d8168a3bd9a00e026160';
    private const URL = 'https://www.sunat.gob.pe/legislacion/superin/2015/anexo3-rs169-2015.pdf';
    private const LATEST_URL = 'https://www.sunat.gob.pe/legislacion/superin/2020/anexos-108-2020.pdf';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Fuente PLE 13/14 ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $tables = $source['tables'] ?? [];
        $latest = $source['latest_table_13'] ?? [];
        if (($source['version'] ?? null) !== '2015-06-anexo-3'
            || ($source['classification'] ?? null) !== 'SUNAT_PLE_2015_ANNEX_3_HISTORICAL_REFERENCE'
            || ($source['source_url'] ?? null) !== self::URL
            || ($source['source_pdf_sha256'] ?? null) !== '05e7a3ddcadd09354d367b1f876f070ba70ce1da2ec0abbfbda355e5b54600d6'
            || count($tables) !== 2
            || ($tables[0]['number'] ?? null) !== '13'
            || ($tables[0]['rows'] ?? null) !== [['1', 'NACIONES UNIDAS'], ['3', 'GS1 (EAN-UCC)'], ['9', 'OTROS']]
            || ($tables[1]['number'] ?? null) !== '14'
            || ($tables[1]['rows'] ?? null) !== [
                ['1', 'PROMEDIO PONDERADO'], ['2', 'PRIMERAS ENTRADAS, PRIMERAS SALIDAS'],
                ['3', 'EXISTENCIAS BÁSICAS'], ['4', 'DETALLISTA'],
                ['5', 'IDENTIFICACIÓN ESPECÍFICA'], ['9', 'OTROS'],
            ]
            || ($latest['version'] ?? null) !== '2020-108-anexo-i'
            || ($latest['classification'] ?? null) !== 'SUNAT_PLE_2020_ANNEX_I_TABLE_13_REFERENCE'
            || ($latest['source_url'] ?? null) !== self::LATEST_URL
            || ($latest['source_pdf_sha256'] ?? null) !== 'e7f79660ea4f977926885d949321ee47cf36a59dd55771670959001cf6071d99'
            || ($latest['effective_from'] ?? null) !== '2021-01-01'
            || ($latest['rows'] ?? null) !== [['1', 'NACIONES UNIDAS'], ['3', 'GTIN'], ['9', 'OTROS']]) {
            throw new RuntimeException('Las Tablas 13/14 PLE no coinciden con el anexo cotejado.');
        }

        Schema::connection('pgsql_migrations')->create('ple_annex3_reference_versions', function (Blueprint $table): void {
            $table->string('table_number', 2);
            $table->string('version', 32);
            $table->string('name', 100);
            $table->string('classification', 100);
            $table->string('source_url', 500);
            $table->string('source_sha256', 64);
            $table->unsignedSmallInteger('item_count');
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['table_number', 'version']);
        });
        Schema::connection('pgsql_migrations')->create('ple_annex3_reference_items', function (Blueprint $table): void {
            $table->string('table_number', 2);
            $table->string('version', 32);
            $table->string('code', 10);
            $table->string('name', 500);
            $table->boolean('is_active');
            $table->primary(['table_number', 'version', 'code']);
            $table->foreign(['table_number', 'version'], 'fk_ple_annex3_reference_version')
                ->references(['table_number', 'version'])->on('ple_annex3_reference_versions');
        });

        $db = DB::connection('pgsql_migrations');
        foreach ($tables as $reference) {
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
        $db->table('ple_annex3_reference_versions')->insert([
            'table_number' => '13', 'version' => $latest['version'],
            'name' => 'Catálogo de existencias', 'classification' => $latest['classification'],
            'source_url' => self::LATEST_URL, 'source_sha256' => self::SHA256,
            'item_count' => count($latest['rows']),
        ]);
        $db->table('ple_annex3_reference_items')->insert(array_map(static fn (array $row): array => [
            'table_number' => '13', 'version' => $latest['version'],
            'code' => $row[0], 'name' => $row[1], 'is_active' => true,
        ], $latest['rows']));

        $db->unprepared('REVOKE ALL ON TABLE public.ple_annex3_reference_versions, public.ple_annex3_reference_items FROM app_backend');
        $db->unprepared('GRANT SELECT ON TABLE public.ple_annex3_reference_versions, public.ple_annex3_reference_items TO app_backend');
    }

    public function down(): void
    {
        throw new RuntimeException('Las referencias PLE 13/14 requieren una migración compensatoria revisada.');
    }
};
