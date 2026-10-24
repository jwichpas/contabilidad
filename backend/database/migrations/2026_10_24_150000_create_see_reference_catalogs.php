<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A separate, global SEE snapshot. PLE/SIRE tables and their installer are untouched. */
return new class extends Migration
{
    private const FILES = [
        'see_catalogs_2017_snapshot.json' => '26155f3b4458e0f93aaf9e1fe20284a82a0b248fa4714a3de3da0e556ada3597',
        'see_ubigeos_inei_snapshot.json' => '3c3d9022ae83b1750a7a2b4ab819b59644e843573ab63f09ae5e20979db67184',
    ];

    public function up(): void
    {
        $sources = [];
        foreach (self::FILES as $file => $sha256) {
            $path = database_path('reference_data/'.$file);
            if (! is_file($path) || ! hash_equals($sha256, hash_file('sha256', $path))) {
                throw new RuntimeException('Fuente SEE ausente o modificada: '.$file);
            }
            $sources[$file] = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        }

        $catalogs = $sources['see_catalogs_2017_snapshot.json']['catalogs'] ?? [];
        $ubigeos = $sources['see_ubigeos_inei_snapshot.json']['rows'] ?? [];
        if (count($catalogs) !== 33 || count($ubigeos) !== 1874
            || array_sum(array_map(static fn (array $c): int => count($c['rows'] ?? []), $catalogs)) !== 1792) {
            throw new RuntimeException('Cantidad de referencias SEE/INEI inesperada.');
        }

        $schema = Schema::connection('pgsql_migrations');
        $schema->create('see_catalogs', function (Blueprint $table): void {
            $table->string('number', 2)->primary();
            $table->string('name', 220);
            $table->string('source_sha256', 64);
            $table->string('classification', 60);
            $table->unsignedInteger('item_count');
        });
        $schema->create('see_catalog_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('catalog_number', 2);
            $table->string('code', 32);
            $table->text('description');
            $table->jsonb('details')->default('{}');
            $table->unsignedInteger('source_row');
            $table->boolean('is_ambiguous')->default(false);
            $table->foreign('catalog_number')->references('number')->on('see_catalogs')->restrictOnDelete();
            $table->unique(['catalog_number', 'source_row']);
            $table->index(['catalog_number', 'code']);
        });
        $schema->create('see_ubigeos', function (Blueprint $table): void {
            $table->string('code', 6)->primary();
            $table->string('district', 160);
            $table->string('province', 160);
            $table->string('department', 160);
            $table->unsignedInteger('population');
            $table->decimal('area_km2', 12, 2);
            $table->decimal('latitude', 9, 5);
            $table->decimal('longitude', 9, 5);
            $table->index(['department', 'province', 'district']);
        });

        // Owners install snapshots; app_backend and authenticated API users only read.
        DB::connection('pgsql_migrations')->unprepared(<<<'SQL'
            CREATE UNIQUE INDEX see_items_unambiguous_code
              ON public.see_catalog_items (catalog_number, code) WHERE is_ambiguous = false;
            REVOKE ALL ON public.see_catalogs, public.see_catalog_items, public.see_ubigeos FROM PUBLIC;
            REVOKE ALL ON SEQUENCE public.see_catalog_items_id_seq FROM PUBLIC;
            GRANT SELECT ON public.see_catalogs, public.see_catalog_items, public.see_ubigeos TO app_backend;
            SQL);

        $db = DB::connection('pgsql_migrations');
        $seenCatalogs = [];
        foreach ($catalogs as $catalog) {
            $number = $catalog['number'];
            if (! preg_match('/^\d{2}$/D', $number) || isset($seenCatalogs[$number])) {
                throw new RuntimeException('Identificador SEE duplicado o inválido.');
            }
            $seenCatalogs[$number] = true;
            $rows = $catalog['rows'];
            $db->table('see_catalogs')->insert([
                'number' => $number,
                'name' => $catalog['name'],
                'source_sha256' => $sources['see_catalogs_2017_snapshot.json']['source_sha256'],
                'classification' => $sources['see_catalogs_2017_snapshot.json']['classification'],
                'item_count' => count($rows),
            ]);
            $frequency = array_count_values(array_column($rows, 0));
            $batch = [];
            foreach ($rows as $offset => $row) {
                $batch[] = [
                    'catalog_number' => $number,
                    'code' => $row[0],
                    'description' => $row[1],
                    'details' => json_encode($row[2] ?? new stdClass(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'source_row' => $offset + 1,
                    'is_ambiguous' => $frequency[$row[0]] > 1,
                ];
                if (count($batch) === 100) {
                    $db->table('see_catalog_items')->insert($batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $db->table('see_catalog_items')->insert($batch);
            }
        }
        $seenUbigeos = [];
        foreach (array_chunk($ubigeos, 100) as $chunk) {
            $batch = [];
            foreach ($chunk as $row) {
                if (count($row) !== 8 || ! preg_match('/^\d{6}$/D', $row[0]) || isset($seenUbigeos[$row[0]])) {
                    throw new RuntimeException('Ubigeo duplicado o inválido en fuente INEI.');
                }
                $seenUbigeos[$row[0]] = true;
                $batch[] = [
                    'code' => $row[0], 'district' => $row[1], 'province' => $row[2],
                    'department' => $row[3], 'population' => $row[4],
                    'area_km2' => $row[5], 'latitude' => $row[6], 'longitude' => $row[7],
                ];
            }
            $db->table('see_ubigeos')->insert($batch);
        }
        if ($db->table('see_catalogs')->count() !== 33
            || $db->table('see_catalog_items')->count() !== 1792
            || $db->table('see_ubigeos')->count() !== 1874) {
            throw new RuntimeException('Instalación SEE incompleta.');
        }
    }

    public function down(): void
    {
        Schema::connection('pgsql_migrations')->dropIfExists('see_ubigeos');
        Schema::connection('pgsql_migrations')->dropIfExists('see_catalog_items');
        Schema::connection('pgsql_migrations')->dropIfExists('see_catalogs');
    }
};
