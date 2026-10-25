<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Keep the original app mapping separate from SUNAT's own Table 8. */
return new class extends Migration
{
    private const FILE = 'sunat_table_08_rs234_2006_rs239_2008.json';
    private const SHA256 = '4b715c3134df8fb22c3a5e487d9824a67e6ae16327403a39991326583654d573';
    private const URL = 'https://www.sunat.gob.pe/legislacion/superin/2006/234_tablas.xls';
    private const AMENDMENT_URL = 'https://www.sunat.gob.pe/legislacion/superin/2008/239.pdf';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Fuente SUNAT Tabla 8 ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $source['rows'] ?? [];
        $expectedCodes = array_merge(
            array_map('strval', range(1, 5)), ['5-A'], array_map('strval', range(6, 31))
        );
        if (($source['table_number'] ?? null) !== '08'
            || ($source['version'] ?? null) !== '2008-rs239'
            || ($source['classification'] ?? null) !== 'SUNAT_RS_234_2006_TABLE_8_WITH_RS_239_2008_AMENDMENT_REFERENCE'
            || ($source['source_url'] ?? null) !== self::URL
            || ($source['amendment_url'] ?? null) !== self::AMENDMENT_URL
            || ($source['source_xls_sha256'] ?? null) !== 'e036ba3a62a2043bd6acd87776167ff72fbee77b4ce3811f29996ef41a882f68'
            || ($source['amendment_pdf_sha256'] ?? null) !== '8133e965817f15130bf8dd48c9e65056058ee51bec09c8ecb2a8888c39c0cb44'
            || array_column($rows, 0) !== $expectedCodes
            || $rows[5] !== ['5-A', 'LIBRO DIARIO DE FORMATO SIMPLIFICADO']
            || $rows[31] !== ['31', 'LIBRO DE PLANILLAS']) {
            throw new RuntimeException('La Tabla 8 no coincide con las fuentes SUNAT cotejadas.');
        }

        $db = DB::connection('pgsql_migrations');
        $old = $db->table('sunat_table_08_electronic_books')->orderBy('code')->get();
        $expectedOld = ['1', '10', '11', '12', '13', '14', '16', '17', '18', '2', '28',
            '3', '30', '31', '4', '5', '6', '7', '8', '9'];
        if ($old->pluck('code')->all() !== $expectedOld
            || $old->firstWhere('code', '4')?->name !== 'LIBRO DE RETENCIONES INCISOS E) Y F) ART. 34 LIR') {
            throw new RuntimeException('La carga SUNAT 08 difiere del legado esperado; requiere conciliación manual.');
        }

        Schema::connection('pgsql_migrations')->create('sunat_book_code_versions', function (Blueprint $table): void {
            $table->string('version', 32)->primary();
            $table->string('classification', 100);
            $table->string('source_url', 500)->nullable();
            $table->string('amendment_url', 500)->nullable();
            $table->string('source_sha256', 64);
            $table->unsignedSmallInteger('item_count');
            $table->timestampTz('created_at')->useCurrent();
        });
        Schema::connection('pgsql_migrations')->create('sunat_book_code_version_items', function (Blueprint $table): void {
            $table->string('version', 32);
            $table->string('code', 5);
            $table->string('name', 500);
            $table->string('ple_book_code', 20)->nullable();
            $table->boolean('is_compulsory_general_regime')->nullable();
            $table->boolean('is_active');
            $table->primary(['version', 'code']);
            $table->foreign('version')->references('version')->on('sunat_book_code_versions');
        });

        $legacyHash = hash('sha256', json_encode($old->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $db->table('sunat_book_code_versions')->insert([
            ['version' => 'legacy-seed', 'classification' => 'FROZEN_GLOBAL_SEED_UNVERIFIED',
                'source_url' => null, 'amendment_url' => null, 'source_sha256' => $legacyHash, 'item_count' => 20],
            ['version' => '2008-rs239', 'classification' => $source['classification'],
                'source_url' => self::URL, 'amendment_url' => self::AMENDMENT_URL,
                'source_sha256' => self::SHA256, 'item_count' => 32],
        ]);
        $db->table('sunat_book_code_version_items')->insert($old->map(static fn (object $row): array => [
            'version' => 'legacy-seed', 'code' => $row->code, 'name' => $row->name,
            'ple_book_code' => $row->ple_book_code,
            'is_compulsory_general_regime' => $row->is_compulsory_general_regime,
            'is_active' => $row->is_active,
        ])->all());
        $db->table('sunat_book_code_version_items')->insert(array_map(static fn (array $row): array => [
            'version' => '2008-rs239', 'code' => $row[0], 'name' => $row[1],
            'ple_book_code' => null, 'is_compulsory_general_regime' => null,
            'is_active' => true,
        ], $rows));

        $db->unprepared('REVOKE ALL ON TABLE public.sunat_book_code_versions, public.sunat_book_code_version_items FROM app_backend');
        $db->unprepared('GRANT SELECT ON TABLE public.sunat_book_code_versions, public.sunat_book_code_version_items TO app_backend');
    }

    public function down(): void
    {
        throw new RuntimeException('La conciliación SUNAT Tabla 8 requiere una migración compensatoria revisada.');
    }
};
