<?php

declare(strict_types=1);

namespace App\Support\Reference;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Inserts an approved immutable reference source with grouped bulk INSERTs.
 *
 * Only used from the F2 installer, inside its outer transaction, when every
 * target table is empty. Ordinary Eloquent seeders retain updateOrCreate.
 * Different optional fields are grouped so PostgreSQL column defaults are
 * preserved exactly as with Eloquent (e.g. is_foreign_trade).
 */
final class GlobalReferenceBatchWriter
{
    private const TABLES = [
        'currencies',
        'accounting_standards',
        'financial_statement_rubrics',
        'sunat_table_01_payment_methods',
        'sunat_table_02_identity_document_types',
        'sunat_table_03_financial_institutions',
        'sunat_table_05_inventory_types',
        'sunat_table_06_measurement_units',
        'sunat_table_08_electronic_books',
        'sunat_table_10_electronic_document_types',
        'sunat_table_11_customs_dependencies',
        'sunat_table_12_operation_types',
    ];

    /**
     * @param class-string<Model> $modelClass
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $extraAttributes
     */
    public static function insert(string $modelClass, array $rows, array $extraAttributes = []): void
    {
        if (! is_subclass_of($modelClass, Model::class)) {
            throw new RuntimeException('Clase de catálogo inválida para carga global.');
        }

        $table = (new $modelClass)->getTable();
        if (! in_array($table, self::TABLES, true) || $rows === []) {
            throw new RuntimeException('Tabla o fuente no aprobada para carga global.');
        }

        if (DB::table($table)->exists()) {
            throw new RuntimeException('Tabla global no vacía: '.$table);
        }

        $now = now()->toDateTimeString();
        $seenCodes = [];
        $groups = [];
        foreach ($rows as $row) {
            $row = array_merge($row, $extraAttributes);
            $code = $row['code'] ?? null;
            if (! is_string($code) || $code === '' || isset($seenCodes[$code])) {
                throw new RuntimeException('Código de catálogo duplicado o inválido: '.$table);
            }
            $seenCodes[$code] = true;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;

            // Eloquent insert() for multi-row batches assumes identical columns.
            // Group by sorted column signature; omitted columns use DB defaults.
            ksort($row, SORT_STRING);
            $signature = implode('|', array_keys($row));
            $groups[$signature][] = $row;
        }

        foreach ($groups as $group) {
            foreach (array_chunk($group, 80) as $batch) {
                DB::table($table)->insert($batch);
            }
        }

        if ((int) DB::table($table)->count() !== count($rows)) {
            throw new RuntimeException('Cantidad incompleta tras carga global: '.$table);
        }
    }
}
