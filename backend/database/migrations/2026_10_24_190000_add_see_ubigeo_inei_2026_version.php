<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Preserve the received ubigeo snapshot; append INEI's 2026 downloadable edition. */
return new class extends Migration
{
    private const FILE = 'see_ubigeos_inei_2026.json';
    private const SHA256 = '0ca6032339c099be56670d8a99d14acaa413a8daa1d2ecf9135b8a456c4a9391';
    private const XLS_SHA256 = 'a62174658a2124fab1366cae40e6ca0feefcc025027b50522909838fbfaae331';
    private const SOURCE_URL = 'https://webapp.inei.gob.pe:8443/sisconcode/web/ubigeo/listaBusquedaUbigeoPorUbicacionGeograficaXls/9/1/1/null/null/null';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Ubigeo INEI 2026 ausente o modificado.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $source['rows'] ?? [];
        $codes = array_column($rows, 0);
        if (($source['version'] ?? null) !== '2026-inei'
            || ($source['classification'] ?? null) !== 'INEI_UBIGEO_2026_REFERENCE'
            || ($source['source_url'] ?? null) !== self::SOURCE_URL
            || ($source['source_year'] ?? null) !== 2026
            || ($source['source_version_id'] ?? null) !== '9-1'
            || ($source['source_xls_sha256'] ?? null) !== self::XLS_SHA256
            || count($rows) !== 1893 || count(array_unique($codes)) !== 1893
            || ! in_array('130112', $codes, true) || ! in_array('250307', $codes, true)
            || count(array_filter($rows, static fn ($row): bool => count($row) === 4
                && preg_match('/^\d{6}$/D', $row[0]) === 1)) !== 1893) {
            throw new RuntimeException('La edición INEI 2026 no coincide con la fuente cotejada.');
        }

        $schema = Schema::connection('pgsql_migrations');
        $schema->create('see_ubigeo_versions', static function (Blueprint $table): void {
            $table->string('version', 40)->primary();
            $table->string('classification', 60);
            $table->string('source_sha256', 64);
            $table->string('source_url', 400);
            $table->unsignedInteger('item_count');
        });
        $schema->create('see_ubigeo_version_items', static function (Blueprint $table): void {
            $table->string('version', 40);
            $table->string('code', 6);
            $table->string('district', 160);
            $table->string('province', 160);
            $table->string('department', 160);
            $table->unsignedInteger('source_row');
            $table->primary(['version', 'code']);
            $table->unique(['version', 'source_row']);
            $table->foreign('version')->references('version')->on('see_ubigeo_versions')->restrictOnDelete();
        });
        $db = DB::connection('pgsql_migrations');
        $db->unprepared(<<<'SQL'
            REVOKE ALL ON public.see_ubigeo_versions, public.see_ubigeo_version_items FROM PUBLIC;
            GRANT SELECT ON public.see_ubigeo_versions, public.see_ubigeo_version_items TO app_backend;
            SQL);
        $db->table('see_ubigeo_versions')->insert([
            'version' => '2026-inei', 'classification' => $source['classification'],
            'source_sha256' => self::SHA256, 'source_url' => self::SOURCE_URL,
            'item_count' => 1893,
        ]);
        foreach (array_chunk($rows, 100, true) as $chunk) {
            $db->table('see_ubigeo_version_items')->insert(array_map(
                static fn (array $row, int $offset): array => [
                    'version' => '2026-inei', 'code' => $row[0],
                    'district' => $row[1], 'province' => $row[2], 'department' => $row[3],
                    'source_row' => $offset + 1,
                ], $chunk, array_keys($chunk)
            ));
        }
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrations')->dropIfExists('see_ubigeo_version_items');
        Schema::connection('pgsql_migrations')->dropIfExists('see_ubigeo_versions');
    }
};
