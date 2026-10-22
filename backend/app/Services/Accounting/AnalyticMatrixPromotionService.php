<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * F2.3g: privileged CLI-only transition, never mounted on an HTTP route.
 * Guarded Neon DEV backend. Does not execute until an explicit caller invokes
 * activate/retire. All audit records and changes share one owner transaction.
 */
final readonly class AnalyticMatrixPromotionService
{
    private const HOST = 'ep-sweet-king-b6hxra2r.c-2.sa-east-1.aws.neon.tech';

    /**
     * @return array{company_id:int,version_id:int,from:string,to:string,audit_id:int,sha256:string}
     */
    public function transition(string $taxId, int $actorUserId, string $expectedSha256, string $action): array
    {
        if (! in_array($taxId, ['20999990011','20999990012','20999990013'], true)
            || $actorUserId <= 0
            || ! preg_match('/^[a-f0-9]{64}$/D', $expectedSha256)
            || ! in_array($action, ['activate','retire'], true)) {
            throw new DomainException('Parámetros de promoción inválidos.');
        }

        $owner = (array)config('database.connections.pgsql_migrations', []);
        $runtime = (array)config('database.connections.pgsql', []);
        if (! app()->environment('local') || config('database.default') !== 'pgsql'
            || env('NEON_TEST_BRANCH_NAME', '') !== 'contabilidad-dev'
            || env('NEON_TEST_BRANCH_HOST', '') !== self::HOST
            || ($owner['host'] ?? null) !== self::HOST
            || ($runtime['host'] ?? null) !== self::HOST
            || ($owner['username'] ?? null) !== 'neondb_owner'
            || ($runtime['username'] ?? null) !== 'app_backend'
            || ($owner['database'] ?? null) !== 'neondb'
            || ($runtime['database'] ?? null) !== 'neondb'
            || ! in_array((string)($owner['sslmode'] ?? ''), ['require','verify-ca','verify-full'], true)
            || filter_var(env('NEON_USE_POOLING', false), FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException('Prohibido: esta transición está limitada a Neon contabilidad-dev.');
        }

        $db = DB::connection('pgsql_migrations');

        return $db->transaction(function () use ($db, $taxId, $actorUserId, $expectedSha256, $action): array {
            $environment = $db->selectOne(<<<'SQL'
                SELECT current_user AS role_name, current_database() AS database_name,
                    current_setting('server_version_num')::integer AS postgres_version,
                    (SELECT count(*) FROM public.migrations)::integer AS migration_count
                SQL);
            if (! $environment || $environment->role_name !== 'neondb_owner'
                || $environment->database_name !== 'neondb'
                || intdiv((int)$environment->postgres_version, 10000) !== 18
                || (int)$environment->migration_count !== 21) {
                throw new RuntimeException('Rol, base de datos o migraciones no autorizados.');
            }

            $company = $db->table('companies')->where('tax_id', $taxId)
                ->where('is_active', true)->lockForUpdate()->first();
            if (! $company) {
                throw new DomainException('Empresa DEMO inactiva o inexistente.');
            }
            $companyId = (int)$company->id;

            // Serializes promotion within this tenant. Posting must acquire a
            // matching shared advisory lock before reading ACTIVE versions.
            $db->selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [
                'nexus:analytic-routing:'.$companyId,
            ]);

            $authorized = $db->table('company_user')
                ->where('company_id', $companyId)
                ->where('user_id', $actorUserId)
                ->where('is_active', true)
                ->whereIn('role', ['ADMIN','CHIEF_ACCOUNTANT'])->exists();
            if (! $authorized) {
                throw new DomainException('El actor debe tener membresía ADMIN/CHIEF_ACCOUNTANT activa en la empresa.');
            }

            $version = $db->table('accounting_destination_rule_versions')
                ->where('company_id', $companyId)
                ->where('version_key', 'PCGE_2019_CC_V2')
                ->lockForUpdate()->first();
            if (! $version || $version->strategy !== 'COST_CENTER'
                || (int)$version->source_count !== 227
                || (int)$version->route_count !== 454) {
                throw new DomainException('Versión o metadatos de distribución no reconocidos.');
            }
            $from = $action === 'activate' ? 'DRAFT' : 'ACTIVE';
            $to = $action === 'activate' ? 'ACTIVE' : 'RETIRED';
            if ($version->status !== $from) {
                throw new DomainException('Transición prohibida; se esperaba estado '.$from.'.');
            }

            $routes = $db->select(<<<'SQL'
                SELECT src.code AS source_code, cc.code AS center_code,
                    dest.code AS debit_code, rev.code AS credit_code
                FROM public.accounting_destination_routes r
                JOIN public.accounting_accounts src
                    ON src.company_id=r.company_id AND src.id=r.source_account_id
                    AND src.is_active AND src.is_operational AND src.has_automatic_destination
                    AND src.requires_cost_center AND src.account_type='EXPENSE'
                    AND length(src.code)=6 AND left(src.code,1)='6'
                JOIN public.cost_centers cc
                    ON cc.company_id=r.company_id AND cc.id=r.cost_center_id
                    AND cc.is_active
                    AND cc.destination_debit_account_id=r.destination_debit_account_id
                    AND cc.destination_credit_account_id=r.destination_credit_account_id
                JOIN public.accounting_accounts dest
                    ON dest.company_id=r.company_id AND dest.id=r.destination_debit_account_id
                    AND dest.is_active AND dest.is_operational AND length(dest.code)=6
                JOIN public.accounting_accounts rev
                    ON rev.company_id=r.company_id AND rev.id=r.destination_credit_account_id
                    AND rev.is_active AND rev.is_operational AND length(rev.code)=6
                WHERE r.company_id=? AND r.rule_version_id=?
                ORDER BY src.code, cc.code
                SQL, [$companyId, (int)$version->id]);

            $snapshot=[];$distinctSources=[];$distinctPairs=[];
            foreach ($routes as $route) {
                $expectedDebit = match ($route->center_code) {
                    'CC-ADM' => '941100',
                    'CC-VTA' => '951100',
                    default => null,
                };
                if ($expectedDebit === null || $route->debit_code !== $expectedDebit
                    || $route->credit_code !== '791000') {
                    throw new DomainException('Destino o centro no autorizado en la matriz.');
                }
                $snapshot[]=$route->source_code.'|'.$route->center_code.'|'
                    .$route->debit_code.'|'.$route->credit_code;
                $distinctSources[$route->source_code]=true;
                $distinctPairs[$route->source_code.'|'.$route->center_code]=true;
            }
            $actualSha256 = hash('sha256', implode("\n", $snapshot));
            if (count($routes) !== 454 || count($distinctSources) !== 227
                || count($distinctPairs) !== 454
                || ! hash_equals((string)$version->matrix_sha256, $actualSha256)
                || ! hash_equals($expectedSha256, $actualSha256)) {
                throw new DomainException('Snapshot incompleto o huella SHA256 distinta a la aprobada.');
            }

            $coverage=$db->selectOne(<<<'SQL'
                SELECT
                  (SELECT count(*) FROM public.accounting_accounts
                    WHERE company_id=? AND is_active AND is_operational
                      AND has_automatic_destination AND requires_cost_center
                      AND account_type='EXPENSE' AND length(code)=6 AND left(code,1)='6')::integer AS sources,
                  (SELECT count(*) FROM public.cost_centers
                    WHERE company_id=? AND is_active)::integer AS centers
                SQL,[$companyId,$companyId]);
            if (!$coverage || (int)$coverage->sources !== 227 || (int)$coverage->centers !== 2) {
                throw new DomainException('Cobertura incompleta: cuentas o centros de costos cambiaron.');
            }

            $changed = $db->table('accounting_destination_rule_versions')
                ->where('company_id', $companyId)->where('id', (int)$version->id)
                ->where('status', $from)->update(['status'=>$to]);
            if ($changed !== 1) {
                throw new RuntimeException('La versión cambió durante la transacción.');
            }
            $event=$action==='activate'?'ANALYTIC_MATRIX_ACTIVATED':'ANALYTIC_MATRIX_RETIRED';
            $auditId=$db->table('audit_logs')->insertGetId([
                'company_id'=>$companyId,
                'user_id'=>$actorUserId,
                'event_type'=>$event,
                'auditable_type'=>'App\\Accounting\\DestinationRuleVersion',
                'auditable_id'=>(string)$version->id,
                'old_values'=>json_encode(['status'=>$from,'sha256'=>$actualSha256], JSON_THROW_ON_ERROR),
                'new_values'=>json_encode([
                    'status'=>$to,'sha256'=>$actualSha256,'routes'=>454,
                    'sources'=>227,'centers'=>2,'channel'=>'owner_cli',
                ],JSON_THROW_ON_ERROR),
                'severity'=>'WARNING',
                'user_agent'=>'nexus-analytic-matrix-cli',
            ]);
            if ((int)$auditId <= 0) {
                throw new RuntimeException('No se pudo registrar la auditoría obligatoria.');
            }
            return [
                'company_id'=>$companyId,'version_id'=>(int)$version->id,
                'from'=>$from,'to'=>$to,'audit_id'=>(int)$auditId,
                'sha256'=>$actualSha256,
            ];
        }, 3);
    }
}
