<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Publish the historical PLE currency codes without importing SEE currency labels. */
return new class extends Migration
{
    private const FILE = 'ple_table_04_sunat_anexo_3_2015.json';
    private const SHA256 = 'ed87f1c2c391b7a07557152e4149c3bcd3cf28c20d0b14782ec0c3350a98959e';
    private const URL = 'https://www.sunat.gob.pe/legislacion/superin/2015/anexo3-rs169-2015.pdf';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Fuente PLE 04 ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $codes = $source['codes'] ?? [];
        if (($source['table_number'] ?? null) !== '04'
            || ($source['version'] ?? null) !== '2015-06-anexo-3'
            || ($source['classification'] ?? null) !== 'SUNAT_PLE_2015_ANNEX_3_HISTORICAL_CODES_ONLY'
            || ($source['source_url'] ?? null) !== self::URL
            || ($source['source_pdf_sha256'] ?? null) !== '05e7a3ddcadd09354d367b1f876f070ba70ce1da2ec0abbfbda355e5b54600d6'
            || count($codes) !== 178
            || count(array_unique($codes)) !== 178
            || ! in_array('PEN', $codes, true)
            || ! in_array('USD', $codes, true)
            || ! in_array('CLP', $codes, true)
            || ! in_array('ZWL', $codes, true)
            || ! in_array('BGN', $codes, true)
            || in_array('BYN', $codes, true)) {
            throw new RuntimeException('La Tabla 04 PLE no coincide con el anexo cotejado.');
        }
        foreach ($codes as $code) {
            if (! is_string($code) || ! preg_match('/^[A-Z]{3}$/D', $code)) {
                throw new RuntimeException('Código PLE 04 inválido.');
            }
        }

        Schema::connection('pgsql_migrations')->create('ple_currency_versions', function (Blueprint $table): void {
            $table->string('version', 32)->primary();
            $table->string('classification', 100);
            $table->string('source_url', 500);
            $table->string('source_sha256', 64);
            $table->unsignedSmallInteger('item_count');
            $table->timestampTz('created_at')->useCurrent();
        });
        Schema::connection('pgsql_migrations')->create('ple_currency_version_items', function (Blueprint $table): void {
            $table->string('version', 32);
            $table->string('code', 3);
            $table->string('name', 200)->nullable();
            $table->boolean('is_active');
            $table->primary(['version', 'code']);
            $table->foreign('version')->references('version')->on('ple_currency_versions');
        });

        $db = DB::connection('pgsql_migrations');
        $db->table('ple_currency_versions')->insert([
            'version' => '2015-06-anexo-3', 'classification' => $source['classification'],
            'source_url' => self::URL, 'source_sha256' => self::SHA256, 'item_count' => 178,
        ]);
        foreach (array_chunk($codes, 100) as $chunk) {
            $db->table('ple_currency_version_items')->insert(array_map(static fn (string $code): array => [
                'version' => '2015-06-anexo-3', 'code' => $code, 'name' => null, 'is_active' => true,
            ], $chunk));
        }

        // Currency selection for a company still uses its separate SEE/ISO catalog and visibility rules.
        $db->unprepared('REVOKE ALL ON TABLE public.ple_currency_versions, public.ple_currency_version_items FROM app_backend');
        $db->unprepared('GRANT SELECT ON TABLE public.ple_currency_versions, public.ple_currency_version_items TO app_backend');
    }

    public function down(): void
    {
        throw new RuntimeException('La referencia PLE 04 requiere una migración compensatoria revisada.');
    }
};
