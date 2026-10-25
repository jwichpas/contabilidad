<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Keep operational mappings unchanged while publishing the PLE Annex 3 inventory operations. */
return new class extends Migration
{
    private const FILE = 'ple_table_12_sunat_anexo_3_2015.json';
    private const SHA256 = '0dc1c6b2bb5f578909e9112c25b1563bc26c9ca4f4fb60763226f271d07432b7';
    private const URL = 'https://www.sunat.gob.pe/legislacion/superin/2015/anexo3-rs169-2015.pdf';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Fuente PLE 12 ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $source['rows'] ?? [];
        $codes = array_column($rows, 0);
        $expectedCodes = array_merge(array_map(static fn (int $n): string => str_pad((string) $n, 2, '0', STR_PAD_LEFT), range(1, 38)),
            array_map(static fn (int $n): string => (string) $n, range(91, 99)));
        if (($source['table_number'] ?? null) !== '12'
            || ($source['version'] ?? null) !== '2015-06-anexo-3'
            || ($source['classification'] ?? null) !== 'SUNAT_PLE_2015_ANNEX_3_REFERENCE'
            || ($source['source_url'] ?? null) !== self::URL
            || $codes !== $expectedCodes
            || $rows[6] !== ['07', 'BONIFICACIÓN']
            || $rows[27] !== ['28', 'AJUSTE POR DIFERENCIA DE INVENTARIO']
            || $rows[46] !== ['99', 'OTROS']) {
            throw new RuntimeException('La Tabla 12 PLE no coincide con el anexo cotejado.');
        }

        $db = DB::connection('pgsql_migrations');
        $old = $db->table('sunat_table_12_operation_types')->orderBy('code')->get();
        $legacyCodes = array_merge(array_map(static fn (int $n): string => str_pad((string) $n, 2, '0', STR_PAD_LEFT), range(1, 16)), ['99']);
        if ($old->pluck('code')->all() !== $legacyCodes || $old->firstWhere('code', '07')?->name !== 'PROMOCIÓN') {
            throw new RuntimeException('La carga PLE 12 difiere del legado esperado; requiere conciliación manual.');
        }

        Schema::connection('pgsql_migrations')->create('ple_inventory_operation_versions', function (Blueprint $table): void {
            $table->string('version', 32)->primary();
            $table->string('classification', 80);
            $table->string('source_url', 500)->nullable();
            $table->string('source_sha256', 64);
            $table->unsignedSmallInteger('item_count');
            $table->timestampTz('created_at')->useCurrent();
        });
        Schema::connection('pgsql_migrations')->create('ple_inventory_operation_version_items', function (Blueprint $table): void {
            $table->string('version', 32);
            $table->string('code', 5);
            $table->string('name', 150);
            $table->string('operation_category', 50)->nullable();
            $table->boolean('is_active');
            $table->primary(['version', 'code']);
            $table->foreign('version')->references('version')->on('ple_inventory_operation_versions');
        });

        $legacyHash = hash('sha256', json_encode($old->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $db->table('ple_inventory_operation_versions')->insert([
            ['version' => 'legacy-seed', 'classification' => 'FROZEN_GLOBAL_SEED_UNVERIFIED',
                'source_url' => null, 'source_sha256' => $legacyHash, 'item_count' => 17],
            ['version' => '2015-06-anexo-3', 'classification' => $source['classification'],
                'source_url' => self::URL, 'source_sha256' => self::SHA256, 'item_count' => 47],
        ]);
        $db->table('ple_inventory_operation_version_items')->insert($old->map(static fn (object $row): array => [
            'version' => 'legacy-seed', 'code' => $row->code, 'name' => $row->name,
            'operation_category' => $row->operation_category, 'is_active' => $row->is_active,
        ])->all());
        $db->table('ple_inventory_operation_version_items')->insert(array_map(static fn (array $row): array => [
            'version' => '2015-06-anexo-3', 'code' => $row[0], 'name' => $row[1],
            'operation_category' => null, 'is_active' => true,
        ], $rows));

        // Do not silently repurpose legacy 07 (PROMOCIÓN) as the annex's BONIFICACIÓN.
        $db->unprepared('REVOKE ALL ON TABLE public.ple_inventory_operation_versions, public.ple_inventory_operation_version_items FROM app_backend');
        $db->unprepared('GRANT SELECT ON TABLE public.ple_inventory_operation_versions, public.ple_inventory_operation_version_items TO app_backend');
    }

    public function down(): void
    {
        throw new RuntimeException('La conciliación PLE 12 requiere una migración compensatoria revisada.');
    }
};
