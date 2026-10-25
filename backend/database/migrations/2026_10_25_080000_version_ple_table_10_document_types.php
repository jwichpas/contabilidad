<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Archive the partial document list and publish the PLE Annex 3 reference independently. */
return new class extends Migration
{
    private const FILE = 'ple_table_10_sunat_anexo_3_2015.json';
    private const SHA256 = '3d06ee30c7ae9827d402ca85f66a6b72cdd618637f9a64425618cf0b6b628973';
    private const URL = 'https://www.sunat.gob.pe/orientacion/amazon/ple/RS-169-2015-SUNAT-PLEver5.pdf';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Fuente PLE 10 ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $source['rows'] ?? [];
        $codes = array_column($rows, 0);
        $expectedCodes = array_merge(
            array_map(static fn (int $n): string => str_pad((string) $n, 2, '0', STR_PAD_LEFT), range(0, 37)),
            array_map(static fn (int $n): string => (string) $n, range(40, 46)),
            array_map(static fn (int $n): string => (string) $n, range(48, 56)),
            ['87', '88', '89', '91', '96', '97', '98']
        );
        if (($source['table_number'] ?? null) !== '10'
            || ($source['version'] ?? null) !== '2015-06-anexo-3'
            || ($source['classification'] ?? null) !== 'SUNAT_PLE_2015_ANNEX_3_REFERENCE'
            || ($source['source_url'] ?? null) !== self::URL
            || $codes !== $expectedCodes
            || $rows[11][0] !== '11'
            || ! in_array(['48', 'COMPROBANTE DE OPERACIONES - LEY N° 29972'], $rows, true)
            || $rows[60] !== ['98', 'NOTA DE DÉBITO - NO DOMICILIADO']) {
            throw new RuntimeException('La Tabla 10 PLE no coincide con el anexo cotejado.');
        }

        $db = DB::connection('pgsql_migrations');
        $old = $db->table('sunat_table_10_electronic_document_types')->orderBy('code')->get();
        $expectedOld = ['00', '01', '02', '03', '04', '05', '06', '07', '08', '09', '10',
            '12', '13', '14', '15', '16', '20', '21', '31', '50', '52', '91', '97', '98'];
        if ($old->pluck('code')->all() !== $expectedOld || $old->firstWhere('code', '00')?->name !== 'OTROS (ESPECIFICAR)') {
            throw new RuntimeException('La carga PLE 10 difiere del legado esperado; requiere conciliación manual.');
        }

        Schema::connection('pgsql_migrations')->create('ple_document_type_versions', function (Blueprint $table): void {
            $table->string('version', 32)->primary();
            $table->string('classification', 80);
            $table->string('source_url', 500)->nullable();
            $table->string('source_sha256', 64);
            $table->unsignedSmallInteger('item_count');
            $table->timestampTz('created_at')->useCurrent();
        });
        Schema::connection('pgsql_migrations')->create('ple_document_type_version_items', function (Blueprint $table): void {
            $table->string('version', 32);
            $table->string('code', 5);
            $table->string('name', 500);
            $table->string('short_name', 150)->nullable();
            $table->boolean('allows_tax_credit')->nullable();
            $table->boolean('is_electronic_default')->nullable();
            $table->boolean('is_active');
            $table->primary(['version', 'code']);
            $table->foreign('version')->references('version')->on('ple_document_type_versions');
        });

        $legacyHash = hash('sha256', json_encode($old->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $db->table('ple_document_type_versions')->insert([
            ['version' => 'legacy-seed', 'classification' => 'FROZEN_GLOBAL_SEED_UNVERIFIED',
                'source_url' => null, 'source_sha256' => $legacyHash, 'item_count' => 24],
            ['version' => '2015-06-anexo-3', 'classification' => $source['classification'],
                'source_url' => self::URL, 'source_sha256' => self::SHA256, 'item_count' => 61],
        ]);
        $db->table('ple_document_type_version_items')->insert($old->map(static fn (object $row): array => [
            'version' => 'legacy-seed', 'code' => $row->code, 'name' => $row->name,
            'short_name' => $row->short_name, 'allows_tax_credit' => $row->allows_tax_credit,
            'is_electronic_default' => $row->is_electronic_default, 'is_active' => $row->is_active,
        ])->all());
        $db->table('ple_document_type_version_items')->insert(array_map(static fn (array $row): array => [
            'version' => '2015-06-anexo-3', 'code' => $row[0], 'name' => $row[1],
            'short_name' => null, 'allows_tax_credit' => null,
            'is_electronic_default' => null, 'is_active' => true,
        ], $rows));

        // Annex membership never implies that a document may be issued or grants tax credit.
        $db->unprepared('REVOKE ALL ON TABLE public.ple_document_type_versions, public.ple_document_type_version_items FROM app_backend');
        $db->unprepared('GRANT SELECT ON TABLE public.ple_document_type_versions, public.ple_document_type_version_items TO app_backend');
    }

    public function down(): void
    {
        throw new RuntimeException('La conciliación PLE 10 requiere una migración compensatoria revisada.');
    }
};
