<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The numeric seed is archived; the PLE Annex 3 unit code space is published separately. */
return new class extends Migration
{
    private const FILE = 'ple_table_06_sunat_anexo_3_2015.json';
    private const SHA256 = 'ead39cf383282241e6590345c1811f9a2d435690974beca479d6452ddea6c8e3';
    private const URL = 'https://www.sunat.gob.pe/orientacion/amazon/ple/RS-169-2015-SUNAT-PLEver5.pdf';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Fuente PLE 06 ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $source['rows'] ?? [];
        $codes = array_column($rows, 0);
        if (($source['table_number'] ?? null) !== '06'
            || ($source['version'] ?? null) !== '2015-06-anexo-3'
            || ($source['classification'] ?? null) !== 'SUNAT_PLE_2015_ANNEX_3_REFERENCE'
            || ($source['source_url'] ?? null) !== self::URL
            || count($rows) !== 62 || count(array_unique($codes)) !== 62
            || $rows[0] !== ['4A', 'BOBINAS']
            || ! in_array(['KGM', 'KILOGRAMO'], $rows, true)
            || ! in_array(['NIU', 'UNIDAD (BIENES)'], $rows, true)
            || $rows[61] !== ['YDK', 'YARDA CUADRADA']) {
            throw new RuntimeException('La Tabla 06 PLE no coincide con el anexo cotejado.');
        }

        $db = DB::connection('pgsql_migrations');
        $old = $db->table('sunat_table_06_measurement_units')->orderBy('code')->get();
        $expected = array_merge(array_map(static fn (int $n): string => str_pad((string) $n, 2, '0', STR_PAD_LEFT), range(1, 15)), ['99']);
        if ($old->pluck('code')->all() !== $expected || $old->firstWhere('code', '07')?->symbol !== 'NIU') {
            throw new RuntimeException('La carga PLE 06 difiere del legado esperado; requiere conciliación manual.');
        }

        Schema::connection('pgsql_migrations')->create('ple_measurement_unit_versions', function (Blueprint $table): void {
            $table->string('version', 32)->primary();
            $table->string('classification', 80);
            $table->string('source_url', 500)->nullable();
            $table->string('source_sha256', 64);
            $table->unsignedSmallInteger('item_count');
            $table->timestampTz('created_at')->useCurrent();
        });
        Schema::connection('pgsql_migrations')->create('ple_measurement_unit_version_items', function (Blueprint $table): void {
            $table->string('version', 32);
            $table->string('code', 5);
            $table->string('name', 100);
            $table->string('symbol', 10)->nullable();
            $table->boolean('is_active');
            $table->primary(['version', 'code']);
            $table->foreign('version')->references('version')->on('ple_measurement_unit_versions');
        });

        $legacyHash = hash('sha256', json_encode($old->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $db->table('ple_measurement_unit_versions')->insert([
            ['version' => 'legacy-seed', 'classification' => 'FROZEN_GLOBAL_SEED_UNVERIFIED',
                'source_url' => null, 'source_sha256' => $legacyHash, 'item_count' => 16],
            ['version' => '2015-06-anexo-3', 'classification' => $source['classification'],
                'source_url' => self::URL, 'source_sha256' => self::SHA256, 'item_count' => 62],
        ]);
        $db->table('ple_measurement_unit_version_items')->insert($old->map(static fn (object $row): array => [
            'version' => 'legacy-seed', 'code' => $row->code, 'name' => $row->name,
            'symbol' => $row->symbol, 'is_active' => $row->is_active,
        ])->all());
        $db->table('ple_measurement_unit_version_items')->insert(array_map(static fn (array $row): array => [
            'version' => '2015-06-anexo-3', 'code' => $row[0], 'name' => $row[1],
            'symbol' => null, 'is_active' => true,
        ], $rows));

        // Preserve the old numeric identifiers for existing references; do not blend code spaces.
        $db->unprepared('REVOKE ALL ON TABLE public.ple_measurement_unit_versions, public.ple_measurement_unit_version_items FROM app_backend');
        $db->unprepared('GRANT SELECT ON TABLE public.ple_measurement_unit_versions, public.ple_measurement_unit_version_items TO app_backend');
    }

    public function down(): void
    {
        throw new RuntimeException('La conciliación PLE 06 requiere una migración compensatoria revisada.');
    }
};
