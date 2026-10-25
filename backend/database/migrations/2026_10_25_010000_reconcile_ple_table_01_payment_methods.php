<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Archive the original PLE seed before publishing the separately sourced SUNAT Annex 3 edition. */
return new class extends Migration
{
    private const FILE = 'ple_table_01_sunat_anexo_3_2015.json';
    private const SHA256 = 'b65eb22b6a5bcdb637e2a839a645a1a8f70274306da8b77fd91a64c3257df29c';
    private const URL = 'https://www.sunat.gob.pe/orientacion/amazon/ple/RS-169-2015-SUNAT-PLEver5.pdf';
    private const CODES = [
        '001', '002', '003', '004', '005', '006', '007', '008', '009', '010', '011', '012', '013',
        '101', '102', '103', '104', '105', '106', '107', '108', '999',
    ];

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Fuente PLE 01 ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $source['rows'] ?? [];
        if (($source['table_number'] ?? null) !== '01'
            || ($source['version'] ?? null) !== '2015-06-anexo-3'
            || ($source['classification'] ?? null) !== 'SUNAT_PLE_2015_ANNEX_3_REFERENCE'
            || ($source['source_url'] ?? null) !== self::URL
            || array_column($rows, 0) !== self::CODES
            || $rows[10][1] !== 'DOCUMENTOS EMITIDOS POR LAS EDPYMES Y LAS COOPERATIVAS DE AHORRO Y CRÉDITO NO AUTORIZADAS A CAPTAR DEPÓSITOS DEL PÚBLICO') {
            throw new RuntimeException('La Tabla 01 PLE no coincide con el anexo cotejado.');
        }

        $db = DB::connection('pgsql_migrations');
        $old = $db->table('sunat_table_01_payment_methods')->orderBy('code')->get();
        $oldCodes = $old->pluck('code')->all();
        $expectedOld = array_values(array_diff(self::CODES, ['012', '013']));
        if ($oldCodes !== $expectedOld || $old->firstWhere('code', '011')?->name !== 'LETRAS DE CAMBIO') {
            throw new RuntimeException('La carga PLE 01 ya difiere de la versión heredada; requiere conciliación manual.');
        }

        Schema::connection('pgsql_migrations')->create('ple_payment_method_versions', function (Blueprint $table): void {
            $table->string('version', 32)->primary();
            $table->string('classification', 80);
            $table->string('source_url', 500)->nullable();
            $table->string('source_sha256', 64);
            $table->unsignedSmallInteger('item_count');
            $table->timestampTz('created_at')->useCurrent();
        });
        Schema::connection('pgsql_migrations')->create('ple_payment_method_version_items', function (Blueprint $table): void {
            $table->string('version', 32);
            $table->string('code', 5);
            $table->string('name', 300);
            $table->boolean('requires_financial_entity')->default(false);
            $table->boolean('requires_operation_number')->default(false);
            $table->boolean('is_foreign_trade')->default(false);
            $table->boolean('is_active')->default(true);
            $table->primary(['version', 'code']);
            $table->foreign('version')->references('version')->on('ple_payment_method_versions');
        });

        $legacyHash = hash('sha256', json_encode($old->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $db->table('ple_payment_method_versions')->insert([
            ['version' => 'legacy-seed', 'classification' => 'FROZEN_GLOBAL_SEED_UNVERIFIED',
                'source_url' => null, 'source_sha256' => $legacyHash, 'item_count' => 20],
            ['version' => '2015-06-anexo-3', 'classification' => $source['classification'],
                'source_url' => self::URL, 'source_sha256' => self::SHA256, 'item_count' => 22],
        ]);
        $db->table('ple_payment_method_version_items')->insert($old->map(static fn (object $row): array => [
            'version' => 'legacy-seed', 'code' => $row->code, 'name' => $row->name,
            'requires_financial_entity' => $row->requires_financial_entity,
            'requires_operation_number' => $row->requires_operation_number,
            'is_foreign_trade' => $row->is_foreign_trade, 'is_active' => $row->is_active,
        ])->all());

        $byCode = $old->keyBy('code');
        $db->table('ple_payment_method_version_items')->insert(array_map(static function (array $row) use ($byCode): array {
            $prior = $byCode->get($row[0]);
            return [
                'version' => '2015-06-anexo-3', 'code' => $row[0], 'name' => $row[1],
                // These flags come from the legacy app, not from SUNAT's annex.
                'requires_financial_entity' => $prior?->requires_financial_entity ?? false,
                'requires_operation_number' => $prior?->requires_operation_number ?? false,
                'is_foreign_trade' => $prior?->is_foreign_trade ?? false,
                'is_active' => $prior?->is_active ?? true,
            ];
        }, $rows));

        foreach ($rows as [$code, $name]) {
            if ($byCode->has($code)) {
                $db->table('sunat_table_01_payment_methods')->where('code', $code)->update([
                    'name' => $name, 'updated_at' => now(),
                ]);
            } else {
                $db->table('sunat_table_01_payment_methods')->insert([
                    'code' => $code, 'name' => $name,
                    'requires_financial_entity' => false, 'requires_operation_number' => false,
                    'is_foreign_trade' => false, 'is_active' => true,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $db->unprepared('REVOKE ALL ON TABLE public.ple_payment_method_versions, public.ple_payment_method_version_items FROM app_backend');
        $db->unprepared('GRANT SELECT ON TABLE public.ple_payment_method_versions, public.ple_payment_method_version_items TO app_backend');
    }

    public function down(): void
    {
        throw new RuntimeException('La conciliación PLE 01 requiere una migración compensatoria revisada.');
    }
};
