<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** PLE rubric positions may repeat a code within a sector; source_row is their identity. */
return new class extends Migration
{
    private const URL = 'https://www.sunat.gob.pe/orientacion/amazon/ple/RS-169-2015-SUNAT-PLEver5.pdf';
    private const ANNEX_SHA256 = '05e7a3ddcadd09354d367b1f876f070ba70ce1da2ec0abbfbda355e5b54600d6';
    private const HASHES = [
        '01' => '554b8e111a79e64f2263f4aeb0441eec894e8997cacca861e93d71743de48b2c',
        '02' => 'e3094ef87c24fb3197aeeafc46c4ace705598fa2c6fdb168d5412193d2824b3b',
        '03' => '183b6224b7eee53745560ce5b2a41f5f88db58aab4b60e84dc06c1f7e6d60982',
        '04' => '31a0b17f6e77b3d1a393a033dd695565358029b7d65e1e5c4629a0a1f93596c5',
        '05' => '844398e0cb5bf0840463a514c883000d45d0cd66ecb717306a655f9eadb0a1c5',
        '06' => 'e1b62fadd21227df30949300766679573b17e117a54294b7dcf7dbd5597a5df1',
        '07' => '61106256730a68283579e75d4ace556831ee2b74341d5e377f350da628bf4a60',
        '08' => '361bbc99e6d8d3ad31465c2c34be3ffea238fd90df6b5c39b29522f2374a3417',
    ];
    private const COUNTS = ['01' => 284, '02' => 194, '03' => 235, '04' => 160, '05' => 217, '06' => 296, '07' => 60, '08' => 181];

    public function up(): void
    {
        $sources = [];
        foreach (self::HASHES as $sector => $hash) {
            $path = database_path("reference_data/ple_table_34_sector_{$sector}_sunat_anexo_3_2015.json");
            if (! is_file($path) || ! hash_equals($hash, hash_file('sha256', $path))) {
                throw new RuntimeException("Fuente PLE 34 sector {$sector} ausente o modificada.");
            }
            $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $rows = $source['rows'] ?? [];
            if (($source['version'] ?? null) !== '2015-06-anexo-3'
                || ($source['classification'] ?? null) !== 'SUNAT_PLE_2015_ANNEX_3_HISTORICAL_REFERENCE'
                || ($source['source_url'] ?? null) !== self::URL
                || ($source['companion_annex_sha256'] ?? null) !== self::ANNEX_SHA256
                || ($source['table_number'] ?? null) !== '34'
                || ($source['sector_code'] ?? null) !== $sector
                || ! is_string($source['sector_name'] ?? null)
                || count($rows) !== self::COUNTS[$sector]) {
                throw new RuntimeException("Metadatos PLE 34 sector {$sector} no coinciden.");
            }
            foreach ($rows as $index => $row) {
                if (count($row) !== 5 || $row[0] !== $index + 1 || ! is_string($row[1])
                    || ! preg_match('/^[A-Z0-9]{4,10}$/', $row[1]) || ! is_string($row[2])
                    || $row[2] === '' || ! is_int($row[3]) || ! is_int($row[4])
                    || $row[3] < 62 || $row[3] > 78 || ! in_array($row[4], [1, 2], true)) {
                    throw new RuntimeException("Posición PLE 34 sector {$sector} inválida.");
                }
            }
            $sources[$sector] = $source;
        }

        Schema::connection('pgsql_migrations')->create('ple_financial_rubric_versions', function (Blueprint $table): void {
            $table->string('version', 32)->primary();
            $table->string('name', 100);
            $table->string('classification', 100);
            $table->string('source_url', 500);
            $table->string('source_sha256', 64);
            $table->unsignedSmallInteger('item_count');
            $table->timestampTz('created_at')->useCurrent();
        });
        Schema::connection('pgsql_migrations')->create('ple_financial_rubric_items', function (Blueprint $table): void {
            $table->string('version', 32);
            $table->unsignedSmallInteger('source_row');
            $table->string('sector_code', 2);
            $table->string('sector_name', 100);
            $table->string('code', 10);
            $table->text('name');
            $table->unsignedSmallInteger('source_page');
            $table->unsignedSmallInteger('source_column');
            $table->boolean('is_active');
            $table->primary(['version', 'source_row']);
            $table->index(['version', 'sector_code', 'code']);
            $table->foreign('version')->references('version')->on('ple_financial_rubric_versions');
        });

        $db = DB::connection('pgsql_migrations');
        $db->table('ple_financial_rubric_versions')->insert([
            'version' => '2015-06-anexo-3',
            'name' => 'Código de los rubros de los estados financieros',
            'classification' => 'SUNAT_PLE_2015_ANNEX_3_HISTORICAL_REFERENCE',
            'source_url' => self::URL,
            'source_sha256' => hash('sha256', implode('', self::HASHES)),
            'item_count' => array_sum(self::COUNTS),
        ]);
        $ordinal = 0;
        $batch = [];
        foreach ($sources as $sector => $source) {
            foreach ($source['rows'] as [$position, $code, $label, $page, $column]) {
                $batch[] = [
                    'version' => '2015-06-anexo-3', 'source_row' => ++$ordinal,
                    'sector_code' => $sector, 'sector_name' => $source['sector_name'],
                    'code' => $code, 'name' => $label,
                    'source_page' => $page, 'source_column' => $column, 'is_active' => true,
                ];
                if (count($batch) === 200) {
                    $db->table('ple_financial_rubric_items')->insert($batch);
                    $batch = [];
                }
            }
        }
        if ($batch !== []) {
            $db->table('ple_financial_rubric_items')->insert($batch);
        }
        $db->unprepared('REVOKE ALL ON TABLE public.ple_financial_rubric_versions, public.ple_financial_rubric_items FROM app_backend');
        $db->unprepared('GRANT SELECT ON TABLE public.ple_financial_rubric_versions, public.ple_financial_rubric_items TO app_backend');
    }

    public function down(): void
    {
        throw new RuntimeException('La referencia PLE 34 requiere una migración compensatoria revisada.');
    }
};
