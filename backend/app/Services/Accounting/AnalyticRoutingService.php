<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Support\Math\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Resolves an ACTIVE tenant-owned v2 routing rule; never resolves legacy
 * destination_debit_code / destination_credit_code on the PCGE account.
 *
 * DRAFT versions deliberately never generate posting instructions.
 */
final readonly class AnalyticRoutingService
{
    public function activeVersionId(int $companyId): ?int
    {
        // Serializes POST against the owner-only DRAFT -> ACTIVE / ACTIVE ->
        // RETIRED transaction. A shared advisory lock is required because
        // app_backend has SELECT but intentionally no UPDATE privileges on
        // version rows (SELECT FOR SHARE would be denied by PostgreSQL).
        // Caller is inside PostJournalEntryService::post DB transaction.
        DB::selectOne('SELECT pg_advisory_xact_lock_shared(hashtextextended(?,0))', [
            'nexus:analytic-routing:'.$companyId,
        ]);
        $versions = DB::table('accounting_destination_rule_versions')
            ->where('company_id', $companyId)
            ->where('status', 'ACTIVE')
            ->limit(2)
            ->pluck('id');

        if ($versions->count() > 1) {
            throw new DomainException('Mas de una matriz de imputacion automatica activa en la empresa.');
        }

        return $versions->isEmpty() ? null : (int) $versions->first();
    }

    /** @return array{debit_account_id:int,credit_account_id:int} */
    public function resolve(int $companyId, int $versionId, int $sourceAccountId, int $centerId): array
    {
        $route = DB::selectOne(<<<'SQL'
            SELECT r.destination_debit_account_id AS debit_id,
                   r.destination_credit_account_id AS credit_id,
                   d.code AS debit_code, h.code AS credit_code
            FROM public.accounting_destination_routes r
            JOIN public.accounting_destination_rule_versions v
                ON v.company_id=r.company_id AND v.id=r.rule_version_id
                AND v.status='ACTIVE' AND v.strategy='COST_CENTER'
            JOIN public.cost_centers cc
                ON cc.company_id=r.company_id AND cc.id=r.cost_center_id
                AND cc.is_active
                AND cc.destination_debit_account_id=r.destination_debit_account_id
                AND cc.destination_credit_account_id=r.destination_credit_account_id
            JOIN public.accounting_accounts src
                ON src.company_id=r.company_id AND src.id=r.source_account_id
                AND src.is_active AND src.is_operational AND src.has_automatic_destination
                AND src.requires_cost_center AND src.account_type='EXPENSE'
                AND length(src.code)=6 AND left(src.code,1)='6'
            JOIN public.accounting_accounts d
                ON d.company_id=r.company_id AND d.id=r.destination_debit_account_id
                AND d.is_active AND d.is_operational AND length(d.code)=6
            JOIN public.accounting_accounts h
                ON h.company_id=r.company_id AND h.id=r.destination_credit_account_id
                AND h.is_active AND h.is_operational AND length(h.code)=6
            WHERE r.company_id=? AND r.rule_version_id=?
                AND r.source_account_id=? AND r.cost_center_id=?
            SQL, [$companyId, $versionId, $sourceAccountId, $centerId]);

        if ($route === null || ! in_array((string)$route->debit_code,['941100','951100'],true)
            || (string)$route->credit_code !== '791000') {
            throw new DomainException('No existe ruta analitica operativa y aprobada para la cuenta/centro seleccionados.');
        }

        return [
            'debit_account_id' => (int)$route->debit_id,
            'credit_account_id' => (int)$route->credit_id,
        ];
    }

    /**
     * Produce EXACTLY balanced derived lines in both currencies.
     * An ordinary debit to element 6 generates debit to 94/95 and credit to 79;
     * credit adjustments generate the reverse (debit 79 / credit 94/95).
     * No arithmetic uses floats or exchange-rate recomputation.
     *
     * @param array{debit_local:string,credit_local:string,debit_foreign:string,credit_foreign:string} $source
     * @return array{debit:array{debit_local:string,credit_local:string,debit_foreign:string,credit_foreign:string},credit:array{debit_local:string,credit_local:string,debit_foreign:string,credit_foreign:string}}
     */
    public static function mirrorAmounts(array $source): array
    {
        $dl = Decimal::ofMonetary($source['debit_local'])->toString();
        $cl = Decimal::ofMonetary($source['credit_local'])->toString();
        $df = Decimal::ofMonetary($source['debit_foreign'])->toString();
        $cf = Decimal::ofMonetary($source['credit_foreign'])->toString();
        $zero = Decimal::zero()->toString();

        if (Decimal::ofMonetary($dl)->isPositive()
            && Decimal::ofMonetary($df)->isPositive()
            && Decimal::ofMonetary($cl)->isZero()
            && Decimal::ofMonetary($cf)->isZero()) {
            return [
                'debit'=>['debit_local'=>$dl,'credit_local'=>$zero,'debit_foreign'=>$df,'credit_foreign'=>$zero],
                'credit'=>['debit_local'=>$zero,'credit_local'=>$dl,'debit_foreign'=>$zero,'credit_foreign'=>$df],
            ];
        }
        if (Decimal::ofMonetary($cl)->isPositive()
            && Decimal::ofMonetary($cf)->isPositive()
            && Decimal::ofMonetary($dl)->isZero()
            && Decimal::ofMonetary($df)->isZero()) {
            return [
                'debit'=>['debit_local'=>$cl,'credit_local'=>$zero,'debit_foreign'=>$cf,'credit_foreign'=>$zero],
                'credit'=>['debit_local'=>$zero,'credit_local'=>$cl,'debit_foreign'=>$zero,'credit_foreign'=>$cf],
            ];
        }
        throw new DomainException('Partida fuente invalida para imputacion analitica exacta.');
    }
}
