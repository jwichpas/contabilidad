<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Preserve six seeded inventory types before publishing the 19 PLE Annex 3 codes. */
return new class extends Migration
{
    private const FILE = 'ple_table_05_sunat_anexo_3_2015.json';
    private const SHA256 = '379587a63a33f99f2a9d373f7113567952e613426f0d14233027e8a91e7de3c1';
    private const URL = 'https://www.sunat.gob.pe/orientacion/amazon/ple/RS-169-2015-SUNAT-PLEver5.pdf';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Fuente PLE 05 ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $source['rows'] ?? [];
        if (($source['table_number'] ?? null) !== '05'
            || ($source['version'] ?? null) !== '2015-06-anexo-3'
            || ($source['classification'] ?? null) !== 'SUNAT_PLE_2015_ANNEX_3_REFERENCE'
            || ($source['source_url'] ?? null) !== self::URL
            || array_column($rows, 0) !== [
                '01', '02', '03', '04', '05', '06', '07', '08', '09', '10',
                '91', '92', '93', '94', '95', '96', '97', '98', '99',
            ]
            || $rows[4][1] !== 'MATERIALES AUXILIARES'
            || $rows[17][1] !== 'OTROS 8') {
            throw new RuntimeException('La Tabla 05 PLE no coincide con el anexo cotejado.');
        }

        $db = DB::connection('pgsql_migrations');
        $old = $db->table('sunat_table_05_inventory_types')->orderBy('code')->get();
        if ($old->pluck('code')->all() !== ['01', '02', '03', '04', '05', '99']
            || $old->firstWhere('code', '05')?->name !== 'SUMINISTROS DIVERSOS') {
            throw new RuntimeException('La carga PLE 05 difiere del legado esperado; requiere conciliación manual.');
        }

        Schema::connection('pgsql_migrations')->create('ple_inventory_type_versions', function (Blueprint $table): void {
            $table->string('version', 32)->primary();
            $table->string('classification', 80);
            $table->string('source_url', 500)->nullable();
            $table->string('source_sha256', 64);
            $table->unsignedSmallInteger('item_count');
            $table->timestampTz('created_at')->useCurrent();
        });
        Schema::connection('pgsql_migrations')->create('ple_inventory_type_version_items', function (Blueprint $table): void {
            $table->string('version', 32);
            $table->string('code', 5);
            $table->string('name', 150);
            $table->string('related_account_element', 5)->nullable();
            $table->boolean('is_active');
            $table->primary(['version', 'code']);
            $table->foreign('version')->references('version')->on('ple_inventory_type_versions');
        });

        $legacyHash = hash('sha256', json_encode($old->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $db->table('ple_inventory_type_versions')->insert([
            ['version' => 'legacy-seed', 'classification' => 'FROZEN_GLOBAL_SEED_UNVERIFIED',
                'source_url' => null, 'source_sha256' => $legacyHash, 'item_count' => 6],
            ['version' => '2015-06-anexo-3', 'classification' => $source['classification'],
                'source_url' => self::URL, 'source_sha256' => self::SHA256, 'item_count' => 19],
        ]);
        $db->table('ple_inventory_type_version_items')->insert($old->map(static fn (object $row): array => [
            'version' => 'legacy-seed', 'code' => $row->code, 'name' => $row->name,
            'related_account_element' => $row->related_account_element, 'is_active' => $row->is_active,
        ])->all());

        $byCode = $old->keyBy('code');
        $db->table('ple_inventory_type_version_items')->insert(array_map(static function (array $row) use ($byCode): array {
            $prior = $byCode->get($row[0]);
            return [
                'version' => '2015-06-anexo-3', 'code' => $row[0], 'name' => $row[1],
                // PCGE hints in the old seed do not come from the SUNAT table.
                'related_account_element' => $prior?->related_account_element,
                'is_active' => $prior?->is_active ?? true,
            ];
        }, $rows));

        foreach ($rows as [$code, $name]) {
            if ($byCode->has($code)) {
                $db->table('sunat_table_05_inventory_types')->where('code', $code)->update([
                    'name' => $name, 'updated_at' => now(),
                ]);
            } else {
                $db->table('sunat_table_05_inventory_types')->insert([
                    'code' => $code, 'name' => $name, 'related_account_element' => null,
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $db->unprepared('REVOKE ALL ON TABLE public.ple_inventory_type_versions, public.ple_inventory_type_version_items FROM app_backend');
        $db->unprepared('GRANT SELECT ON TABLE public.ple_inventory_type_versions, public.ple_inventory_type_version_items TO app_backend');
    }

    public function down(): void
    {
        throw new RuntimeException('La conciliación PLE 05 requiere una migración compensatoria revisada.');
    }
};
