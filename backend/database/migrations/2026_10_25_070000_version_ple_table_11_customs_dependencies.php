<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Archive the partial seed and publish PLE Annex 3 customs codes in separate reference tables. */
return new class extends Migration
{
    private const FILE = 'ple_table_11_sunat_anexo_3_2015.json';
    private const SHA256 = 'cda6985f84f93f9d5874c77307793e360da7354f0ca06c69a40d4c81b0776cbb';
    private const URL = 'https://www.sunat.gob.pe/legislacion/superin/2015/anexo3-rs169-2015.pdf';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Fuente PLE 11 ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $source['rows'] ?? [];
        $codes = array_column($rows, 0);
        if (($source['table_number'] ?? null) !== '11'
            || ($source['version'] ?? null) !== '2015-06-anexo-3'
            || ($source['classification'] ?? null) !== 'SUNAT_PLE_2015_ANNEX_3_REFERENCE'
            || ($source['source_url'] ?? null) !== self::URL
            || count($rows) !== 30 || count(array_unique($codes)) !== 30
            || $rows[0] !== ['019', 'TUMBES']
            || ! in_array(['884', 'DEPENDENCIA FERROVIARIA TACNA'], $rows, true)
            || $rows[29] !== ['965', 'DEPENDENCIA POSTAL DE SALAVERRY']) {
            throw new RuntimeException('La Tabla 11 PLE no coincide con el anexo cotejado.');
        }

        $db = DB::connection('pgsql_migrations');
        $old = $db->table('sunat_table_11_customs_dependencies')->orderBy('code')->get();
        $expected = array_slice($codes, 0, 22);
        if ($old->pluck('code')->all() !== $expected || $old->firstWhere('code', '172')?->name !== 'TACNA') {
            throw new RuntimeException('La carga PLE 11 difiere del legado esperado; requiere conciliación manual.');
        }

        Schema::connection('pgsql_migrations')->create('ple_customs_dependency_versions', function (Blueprint $table): void {
            $table->string('version', 32)->primary();
            $table->string('classification', 80);
            $table->string('source_url', 500)->nullable();
            $table->string('source_sha256', 64);
            $table->unsignedSmallInteger('item_count');
            $table->timestampTz('created_at')->useCurrent();
        });
        Schema::connection('pgsql_migrations')->create('ple_customs_dependency_version_items', function (Blueprint $table): void {
            $table->string('version', 32);
            $table->string('code', 5);
            $table->string('name', 150);
            $table->boolean('is_active');
            $table->primary(['version', 'code']);
            $table->foreign('version')->references('version')->on('ple_customs_dependency_versions');
        });

        $legacyHash = hash('sha256', json_encode($old->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $db->table('ple_customs_dependency_versions')->insert([
            ['version' => 'legacy-seed', 'classification' => 'FROZEN_GLOBAL_SEED_UNVERIFIED',
                'source_url' => null, 'source_sha256' => $legacyHash, 'item_count' => 22],
            ['version' => '2015-06-anexo-3', 'classification' => $source['classification'],
                'source_url' => self::URL, 'source_sha256' => self::SHA256, 'item_count' => 30],
        ]);
        $db->table('ple_customs_dependency_version_items')->insert($old->map(static fn (object $row): array => [
            'version' => 'legacy-seed', 'code' => $row->code, 'name' => $row->name,
            'is_active' => $row->is_active,
        ])->all());
        $db->table('ple_customs_dependency_version_items')->insert(array_map(static fn (array $row): array => [
            'version' => '2015-06-anexo-3', 'code' => $row[0], 'name' => $row[1],
            'is_active' => true,
        ], $rows));

        // Do not infer an operational customs location from a 2015 PLE reference.
        $db->unprepared('REVOKE ALL ON TABLE public.ple_customs_dependency_versions, public.ple_customs_dependency_version_items FROM app_backend');
        $db->unprepared('GRANT SELECT ON TABLE public.ple_customs_dependency_versions, public.ple_customs_dependency_version_items TO app_backend');
    }

    public function down(): void
    {
        throw new RuntimeException('La conciliación PLE 11 requiere una migración compensatoria revisada.');
    }
};
