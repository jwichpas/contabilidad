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
            $db = DB::connection('pgsql_migrations');

            $schema = $db->selectOne(<<<'SQL'
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
                    (SELECT count(DISTINCT table_name) FROM information_schema.role_table_grants
                     WHERE table_schema = 'public' AND grantee = 'anon'
                       AND privilege_type = 'SELECT') AS anon_select_grants
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

            $result = [
                'phase' => 'F0',
                'mode' => 'READ_ONLY',
                'database' => (string) $db->getDatabaseName(),
                'schema' => array_map('intval', (array) $schema),
                'ledger' => array_map('intval', (array) $ledger),
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
                $this->warn('Los permisos/RLS requieren remediación en una futura migración Laravel; este comando no cambia nada.');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('No fue posible obtener la línea base: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
