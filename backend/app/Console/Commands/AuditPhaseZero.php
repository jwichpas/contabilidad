<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Inventario no destructivo para la Fase 0 del README-PLAN-MAESTRO.
 * Usa la conexión administrativa definida en Laravel únicamente para SELECT.
 * Nunca crea, modifica ni elimina tablas, políticas, registros o roles.
 */
final class AuditPhaseZero extends Command
{
    protected $signature = 'contabilidad:auditar-fase0 {--json : Emitir solo JSON}';

    protected $description = 'Audita la línea base PostgreSQL de desarrollo sin modificar la base';

    public function handle(): int
    {
        try {
            // Este diagnóstico no debe ejecutarse accidentalmente sobre otro proyecto PostgreSQL.
            // El identificador no es secreto: pertenece al entorno DEV de contabilidad.
            $connection = config('database.connections.pgsql_migrations', []);
            $locator = implode(' ', array_map('strval', [
                $connection['host'] ?? '',
                $connection['url'] ?? '',
                $connection['username'] ?? '',
            ]));
            if (! str_contains($locator, 'bnjwxphwtkhblvhsnylj')) {
                $this->error('La conexión pgsql_migrations no identifica contabilidad-database. Revise su .env.');
                return self::FAILURE;
            }

            $db = DB::connection('pgsql_migrations');
            $db->beginTransaction();
            // Se aplica al comienzo de la transacción; PostgreSQL impedirá toda escritura accidental.
            $db->statement('SET TRANSACTION READ ONLY');

            $schema = $db->selectOne(<<<'SQL'
                WITH target_sequences AS MATERIALIZED (
                    SELECT c.oid FROM pg_catalog.pg_class c
                    JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                    WHERE n.nspname = 'public' AND c.relkind = 'S'
                )
                SELECT
                    (SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                     WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')) AS tables,
                    (SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                     WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND NOT c.relrowsecurity) AS rls_disabled,
                    (SELECT count(*) FROM pg_indexes WHERE schemaname = 'public') AS indexes,
                    (SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                     WHERE n.nspname = 'public') AS functions,
                    (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
                     JOIN pg_namespace n ON n.oid = c.relnamespace
                     WHERE n.nspname = 'public' AND NOT t.tgisinternal) AS triggers,
                    (SELECT count(*) FROM pg_policies WHERE schemaname = 'public') AS policies,
                    (SELECT count(*) FROM public.migrations) AS laravel_migrations,
                    (SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                     WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
                       AND has_table_privilege('anon', c.oid, 'SELECT')) AS anon_select_grants,
                    (SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                     WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
                       AND has_table_privilege('authenticated', c.oid, 'SELECT')) AS authenticated_select_grants,
                    (SELECT count(*) FROM target_sequences c
                     WHERE has_sequence_privilege('anon', c.oid, 'USAGE')) AS anon_sequences,
                    (SELECT count(*) FROM target_sequences c
                     WHERE has_sequence_privilege('authenticated', c.oid, 'USAGE')) AS authenticated_sequences,
                    (SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                     WHERE n.nspname = 'public'
                       AND has_function_privilege('anon', p.oid, 'EXECUTE')) AS anon_functions,
                    (SELECT count(*) FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                     WHERE n.nspname = 'public'
                       AND has_function_privilege('authenticated', p.oid, 'EXECUTE')) AS authenticated_functions
                SQL);

            $ledger = $db->selectOne(<<<'SQL'
                WITH entries AS (
                    SELECT e.id, e.company_id, count(l.id) AS lines,
                           coalesce(sum(l.debit_local), 0) AS dl,
                           coalesce(sum(l.credit_local), 0) AS cl,
                           coalesce(sum(l.debit_foreign), 0) AS df,
                           coalesce(sum(l.credit_foreign), 0) AS cf
                    FROM public.journal_entries e
                    LEFT JOIN public.journal_entry_lines l
                      ON l.journal_entry_id = e.id AND l.company_id = e.company_id
                    GROUP BY e.id, e.company_id
                )
                SELECT count(*) AS entries,
                       coalesce(sum(lines), 0) AS lines,
                       count(*) FILTER (WHERE lines < 2 OR dl <> cl OR df <> cf) AS invalid_entries,
                       (SELECT count(*) FROM public.journal_entry_lines l
                        LEFT JOIN public.journal_entries e
                          ON e.id = l.journal_entry_id AND e.company_id = l.company_id
                        WHERE e.id IS NULL) AS orphan_lines,
                       (SELECT count(*) FROM public.journal_entry_lines l
                        LEFT JOIN public.accounting_accounts a
                          ON a.id = l.accounting_account_id AND a.company_id = l.company_id
                        WHERE a.id IS NULL) AS invalid_account_references
                FROM entries
                SQL);

            $migrationFiles = glob(database_path('migrations').'/*.php') ?: [];
            $expectedMigrations = array_map(
                static fn (string $file): string => pathinfo($file, PATHINFO_FILENAME),
                $migrationFiles
            );
            $registeredMigrations = array_map(
                static fn (object $row): string => (string) $row->migration,
                $db->select('SELECT migration FROM public.migrations ORDER BY migration')
            );
            sort($expectedMigrations);
            sort($registeredMigrations);

            // No se ejecuta ningún migrador. Comparación estrictamente por nombre.
            $migrations = [
                'files' => count($expectedMigrations),
                'applied' => count($registeredMigrations),
                'not_applied' => array_values(array_diff($expectedMigrations, $registeredMigrations)),
                'not_in_repository' => array_values(array_diff($registeredMigrations, $expectedMigrations)),
                'note' => 'NAME_COMPARISON_ONLY_NO_SCHEMA_REBUILD',
            ];

            $db->commit();

            $result = [
                'phase' => 'F0',
                'mode' => 'READ_ONLY',
                'database' => (string) $db->getDatabaseName(),
                'schema' => array_map('intval', (array) $schema),
                'ledger' => array_map('intval', (array) $ledger),
                'migrations' => $migrations,
                'status' => 'DIAGNOSTIC_ONLY_NOT_PHASE_APPROVAL',
            ];

            if ($this->option('json')) {
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            } else {
                $this->info('Fase 0 — diagnóstico de solo lectura (sin DDL/DML)');
                $this->table(['Indicador', 'Valor'], collect($result['schema'])
                    ->map(fn (int $value, string $name): array => [$name, $value])->values()->all());
                $this->table(['Integridad contable', 'Valor'], collect($result['ledger'])
                    ->map(fn (int $value, string $name): array => [$name, $value])->values()->all());
                $this->table(['Migraciones Laravel', 'Resultado'], [
                    ['Archivos', $migrations['files']],
                    ['Aplicadas', $migrations['applied']],
                    ['Pendientes', count($migrations['not_applied'])],
                    ['Sin archivo fuente', count($migrations['not_in_repository'])],
                ]);
                $this->warn('Auditoría de solo lectura. Consulte los conteos: el estado F0 requiere además pruebas funcionales y recuperación.');
            }

            return self::SUCCESS;
         } catch (Throwable $e) {
            // No imprimir excepciones PDO: pueden incluir identificadores de conexión o SQL sensible.
            try {
                $connection = DB::connection('pgsql_migrations');
                if ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
            } catch (Throwable) {
                // El rollback es de mejor esfuerzo si la conexión falló.
            }
            $this->error('No fue posible obtener la línea base. Revise la conexión y los logs seguros de Laravel.');
            return self::FAILURE;
        }
    }
}
