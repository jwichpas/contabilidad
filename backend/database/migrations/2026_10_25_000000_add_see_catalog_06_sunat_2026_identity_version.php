<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Preserve the supplied nine rows; publish the 2026 annex's thirteen identity references separately. */
return new class extends Migration
{
    private const FILE = 'see_catalog_06_sunat_2026_000049_anexo_i.json';
    private const SHA256 = '6004bf701d75b3bc0308557606cce471be1c6ba96f958b7f560a4be81b106f21';
    private const URL = 'https://www.sunat.gob.pe/legislacion/superin/2026/anexo-000049-2026.pdf';

    public function up(): void
    {
        $path = database_path('reference_data/'.self::FILE);
        if (! is_file($path) || ! hash_equals(self::SHA256, hash_file('sha256', $path))) {
            throw new RuntimeException('Fuente SUNAT SEE 06 de 2026 ausente o modificada.');
        }
        $source = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = $source['rows'] ?? [];
        if (($source['catalog_number'] ?? null) !== '06'
            || ($source['version'] ?? null) !== '2026-000049-anexo-i'
            || ($source['classification'] ?? null) !== 'SUNAT_ANNEX_I_2026_IDENTITY_REFERENCE'
            || ($source['source_url'] ?? null) !== self::URL
            || count($rows) !== 13
            || array_column($rows, 0) !== ['0', '1', '4', '6', '7', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H']
            || $rows[9][1] !== 'TAM- Tarjeta Andina de Migración'
            || $rows[12][1] !== 'CARNE PERMISO TEMP.PERMAN -CPP'
            || count(array_filter($rows, static fn ($row): bool => count($row) === 5
                && in_array($row[3], ['A', 'N'], true)
                && in_array($row[4], ['F', 'V'], true))) !== 13) {
            throw new RuntimeException('La tabla de identidad SUNAT 2026 no coincide con el anexo cotejado.');
        }

        $db = DB::connection('pgsql_migrations');
        $db->table('see_catalog_versions')->insert([
            'catalog_number' => '06', 'version' => $source['version'],
            'name' => $source['name'], 'classification' => $source['classification'],
            'source_sha256' => self::SHA256, 'source_url' => self::URL,
            'item_count' => count($rows),
        ]);
        $db->table('see_catalog_version_items')->insert(array_map(
            static fn (array $row, int $offset): array => [
                'catalog_number' => '06', 'version' => '2026-000049-anexo-i',
                'code' => $row[0], 'description' => $row[1],
                'details' => json_encode([
                    'Longitud fuente' => $row[2],
                    'Formato fuente' => $row[3],
                    'Modalidad fuente' => $row[4],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'source_row' => $offset + 1,
            ], $rows, array_keys($rows)
        ));
    }

    public function down(): void
    {
        throw new RuntimeException('Las ediciones SEE publicadas requieren una migración compensatoria revisada.');
    }
};
