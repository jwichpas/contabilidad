<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Keep the supplied SEE snapshot intact; add a separately attributed SUNAT annex. */
return new class extends Migration
{
    private const SOURCE = 'see_catalog_20_anexo_i_2024.json';
    private const SHA256 = '86b68440dcfbb0f0be6972eef393b0fc0807f781f884f5c08575620df86b8aa8';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::SOURCE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Fuente SUNAT del catálogo SEE 20 ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $source['rows'] ?? [];
        if (($source['catalog_number'] ?? null) !== '20'
            || ($source['version'] ?? null) !== '2024-anexo-i'
            || ($source['classification'] ?? null) !== 'SUNAT_ANNEX_I_2024_REFERENCE'
            || ($source['source_url'] ?? null) !== 'https://www.sunat.gob.pe/legislacion/superin/2024/anexo1-000240-2024.pdf'
            || count($rows) !== 14
            || array_column($rows, 0) !== ['01', '02', '03', '04', '05', '06', '07', '08', '09', '13', '14', '17', '18', '19']) {
            throw new RuntimeException('El anexo SUNAT del catálogo 20 no coincide con la versión cotejada.');
        }

        $schema = Schema::connection('pgsql_migrations');
        $schema->create('see_catalog_versions', function (Blueprint $table): void {
            $table->string('catalog_number', 2);
            $table->string('version', 40);
            $table->string('name', 220);
            $table->string('classification', 60);
            $table->string('source_sha256', 64);
            $table->string('source_url', 400);
            $table->unsignedInteger('item_count');
            $table->primary(['catalog_number', 'version']);
            $table->foreign('catalog_number')->references('number')->on('see_catalogs')->restrictOnDelete();
        });
        $schema->create('see_catalog_version_items', function (Blueprint $table): void {
            $table->string('catalog_number', 2);
            $table->string('version', 40);
            $table->string('code', 32);
            $table->text('description');
            $table->unsignedInteger('source_row');
            $table->primary(['catalog_number', 'version', 'code']);
            $table->unique(['catalog_number', 'version', 'source_row']);
            $table->foreign(['catalog_number', 'version'])->references(['catalog_number', 'version'])
                ->on('see_catalog_versions')->restrictOnDelete();
        });

        $db = DB::connection('pgsql_migrations');
        $db->unprepared(<<<'SQL'
            REVOKE ALL ON public.see_catalog_versions, public.see_catalog_version_items FROM PUBLIC;
            GRANT SELECT ON public.see_catalog_versions, public.see_catalog_version_items TO app_backend;
            SQL);
        $db->table('see_catalog_versions')->insert([
            'catalog_number' => '20', 'version' => '2024-anexo-i',
            'name' => $source['name'], 'classification' => $source['classification'],
            'source_sha256' => self::SHA256, 'source_url' => $source['source_url'], 'item_count' => 14,
        ]);
        $db->table('see_catalog_version_items')->insert(array_map(
            static fn (array $row, int $offset): array => [
                'catalog_number' => '20', 'version' => '2024-anexo-i', 'code' => $row[0],
                'description' => $row[1], 'source_row' => $offset + 1,
            ], $rows, array_keys($rows)
        ));
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrations')->dropIfExists('see_catalog_version_items');
        Schema::connection('pgsql_migrations')->dropIfExists('see_catalog_versions');
    }
};
