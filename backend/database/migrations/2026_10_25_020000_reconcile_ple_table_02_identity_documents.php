<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Preserve the original identity seed and publish PLE Annex 3 Table 2 independently of SEE 06. */
return new class extends Migration
{
    private const FILE = 'ple_table_02_sunat_anexo_3_2015.json';
    private const SHA256 = 'a513318955112c87220fd78905b21c4a5a8b6c4a33fbf79751ff1405f818e9ed';
    private const URL = 'https://www.sunat.gob.pe/orientacion/amazon/ple/RS-169-2015-SUNAT-PLEver5.pdf';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Fuente PLE 02 ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $source['rows'] ?? [];
        if (($source['table_number'] ?? null) !== '02'
            || ($source['version'] ?? null) !== '2015-06-anexo-3'
            || ($source['classification'] ?? null) !== 'SUNAT_PLE_2015_ANNEX_3_REFERENCE'
            || ($source['source_url'] ?? null) !== self::URL
            || array_column($rows, 0) !== ['0', '1', '4', '6', '7', 'A']
            || $rows[5][1] !== 'CÉDULA DIPLOMÁTICA DE IDENTIDAD') {
            throw new RuntimeException('La Tabla 02 PLE no coincide con el anexo cotejado.');
        }

        $db = DB::connection('pgsql_migrations');
        $old = $db->table('sunat_table_02_identity_document_types')->orderBy('code')->get();
        if ($old->pluck('code')->all() !== ['0', '1', '4', '6', '7']
            || $old->firstWhere('code', '4')?->name !== 'CARNET DE EXTRANJERÍA') {
            throw new RuntimeException('La carga PLE 02 difiere del legado esperado; requiere conciliación manual.');
        }

        Schema::connection('pgsql_migrations')->create('ple_identity_document_versions', function (Blueprint $table): void {
            $table->string('version', 32)->primary();
            $table->string('classification', 80);
            $table->string('source_url', 500)->nullable();
            $table->string('source_sha256', 64);
            $table->unsignedSmallInteger('item_count');
            $table->timestampTz('created_at')->useCurrent();
        });
        Schema::connection('pgsql_migrations')->create('ple_identity_document_version_items', function (Blueprint $table): void {
            $table->string('version', 32);
            $table->string('code', 2);
            $table->string('name', 100);
            $table->string('abbreviation', 15);
            $table->unsignedSmallInteger('exact_length')->nullable();
            $table->unsignedSmallInteger('max_length');
            $table->boolean('is_numeric_only');
            $table->boolean('is_active');
            $table->primary(['version', 'code']);
            $table->foreign('version')->references('version')->on('ple_identity_document_versions');
        });

        $legacyHash = hash('sha256', json_encode($old->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $db->table('ple_identity_document_versions')->insert([
            ['version' => 'legacy-seed', 'classification' => 'FROZEN_GLOBAL_SEED_UNVERIFIED',
                'source_url' => null, 'source_sha256' => $legacyHash, 'item_count' => 5],
            ['version' => '2015-06-anexo-3', 'classification' => $source['classification'],
                'source_url' => self::URL, 'source_sha256' => self::SHA256, 'item_count' => 6],
        ]);
        $db->table('ple_identity_document_version_items')->insert($old->map(static fn (object $item): array => [
            'version' => 'legacy-seed', 'code' => $item->code, 'name' => $item->name,
            'abbreviation' => $item->abbreviation, 'exact_length' => $item->exact_length,
            'max_length' => $item->max_length, 'is_numeric_only' => $item->is_numeric_only,
            'is_active' => $item->is_active,
        ])->all());

        $byCode = $old->keyBy('code');
        $db->table('ple_identity_document_version_items')->insert(array_map(static function (array $row) use ($byCode): array {
            $prior = $byCode->get($row[0]);
            return [
                'version' => '2015-06-anexo-3', 'code' => $row[0], 'name' => $row[1],
                // The supplementary fields are application metadata, not constraints from Annex 3.
                'abbreviation' => $prior?->abbreviation ?? 'CDI',
                'exact_length' => $prior?->exact_length,
                'max_length' => $prior?->max_length ?? 15,
                'is_numeric_only' => $prior?->is_numeric_only ?? false,
                'is_active' => $prior?->is_active ?? true,
            ];
        }, $rows));

        foreach ($rows as [$code, $name]) {
            if ($byCode->has($code)) {
                $db->table('sunat_table_02_identity_document_types')->where('code', $code)->update([
                    'name' => $name, 'updated_at' => now(),
                ]);
            } else {
                $db->table('sunat_table_02_identity_document_types')->insert([
                    'code' => $code, 'name' => $name, 'abbreviation' => 'CDI',
                    'exact_length' => null, 'max_length' => 15, 'is_numeric_only' => false,
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $db->unprepared('REVOKE ALL ON TABLE public.ple_identity_document_versions, public.ple_identity_document_version_items FROM app_backend');
        $db->unprepared('GRANT SELECT ON TABLE public.ple_identity_document_versions, public.ple_identity_document_version_items TO app_backend');
    }

    public function down(): void
    {
        throw new RuntimeException('La conciliación PLE 02 requiere una migración compensatoria revisada.');
    }
};
