<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Publish the cited PLE bank codes for historical reference without changing operational banks. */
return new class extends Migration
{
    private const FILE = 'ple_table_03_sunat_anexo_3_2015.json';
    private const SHA256 = 'ad413f15ef20fd7237589e9fc73a556e99093af24c17272c86b9185f206d3141';
    private const URL = 'https://www.sunat.gob.pe/legislacion/superin/2015/anexo3-rs169-2015.pdf';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Fuente PLE 03 ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $source['rows'] ?? [];
        $codes = array_column($rows, 0);
        if (($source['table_number'] ?? null) !== '03'
            || ($source['version'] ?? null) !== '2015-06-anexo-3'
            || ($source['classification'] ?? null) !== 'SUNAT_PLE_2015_ANNEX_3_HISTORICAL_REFERENCE'
            || ($source['source_url'] ?? null) !== self::URL
            || count($rows) !== 38 || count(array_unique($codes)) !== 38
            || $rows[0] !== ['01', 'CENTRAL RESERVA DEL PERU']
            || ! in_array(['51', 'AGRO BANCO'], $rows, true)
            || ! in_array(['58', 'BANCO AZTECA DEL PERU'], $rows, true)
            || $rows[37] !== ['99', 'OTROS']) {
            throw new RuntimeException('La Tabla 03 PLE no coincide con el anexo cotejado.');
        }

        $db = DB::connection('pgsql_migrations');
        $old = $db->table('sunat_table_03_financial_institutions')->orderBy('code')->get();
        $expected = array_values(array_diff($codes, ['51', '54', '55', '56', '58']));
        if ($old->pluck('code')->all() !== $expected
            || $old->firstWhere('code', '02')?->name !== 'BANCO DE CREDITO DEL PERU') {
            throw new RuntimeException('La carga PLE 03 difiere del legado esperado; requiere conciliación manual.');
        }

        Schema::connection('pgsql_migrations')->create('ple_financial_institution_versions', function (Blueprint $table): void {
            $table->string('version', 32)->primary();
            $table->string('classification', 80);
            $table->string('source_url', 500)->nullable();
            $table->string('source_sha256', 64);
            $table->unsignedSmallInteger('item_count');
            $table->timestampTz('created_at')->useCurrent();
        });
        Schema::connection('pgsql_migrations')->create('ple_financial_institution_version_items', function (Blueprint $table): void {
            $table->string('version', 32);
            $table->string('code', 5);
            $table->string('name', 150);
            $table->string('short_name', 100)->nullable();
            $table->string('tax_id', 20)->nullable();
            $table->boolean('is_bank')->nullable();
            $table->boolean('is_active');
            $table->primary(['version', 'code']);
            $table->foreign('version')->references('version')->on('ple_financial_institution_versions');
        });

        $legacyHash = hash('sha256', json_encode($old->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $db->table('ple_financial_institution_versions')->insert([
            ['version' => 'legacy-seed', 'classification' => 'FROZEN_GLOBAL_SEED_UNVERIFIED',
                'source_url' => null, 'source_sha256' => $legacyHash, 'item_count' => 33],
            ['version' => '2015-06-anexo-3', 'classification' => $source['classification'],
                'source_url' => self::URL, 'source_sha256' => self::SHA256, 'item_count' => 38],
        ]);
        $db->table('ple_financial_institution_version_items')->insert($old->map(static fn (object $row): array => [
            'version' => 'legacy-seed', 'code' => $row->code, 'name' => $row->name,
            'short_name' => $row->short_name, 'tax_id' => $row->tax_id,
            'is_bank' => $row->is_bank, 'is_active' => $row->is_active,
        ])->all());
        $db->table('ple_financial_institution_version_items')->insert(array_map(static fn (array $row): array => [
            'version' => '2015-06-anexo-3', 'code' => $row[0], 'name' => $row[1],
            'short_name' => null, 'tax_id' => null, 'is_bank' => null, 'is_active' => true,
        ], $rows));

        // The annex is a historical code list, not a roster of banks available today.
        $db->unprepared('REVOKE ALL ON TABLE public.ple_financial_institution_versions, public.ple_financial_institution_version_items FROM app_backend');
        $db->unprepared('GRANT SELECT ON TABLE public.ple_financial_institution_versions, public.ple_financial_institution_version_items TO app_backend');
    }

    public function down(): void
    {
        throw new RuntimeException('La conciliación PLE 03 requiere una migración compensatoria revisada.');
    }
};
