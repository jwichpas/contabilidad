<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\AccountingAccount;
use App\Models\CostCenter;
use App\Support\Tenant\TenantContext;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Installs PRIVATE analytical class-9 accounts and cost-center destinations.
 *
 * PCGE master templates stay unchanged: 94/95 are enterprise-specific
 * analytical classifications, not asserted to be official global PCGE rows.
 * Only six-digit leaf accounts are postable; 791000 must already exist.
 * Idempotent, fail-closed for conflicting existing codes or destinations.
 */
final readonly class InitializeCompanyAnalyticAccountsService
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @return list<array{code: string, parent: ?string, name: string, level: int, operational: bool, rubric: string}>
     */
    public static function accountDefinitions(): array
    {
        return [
            ['code'=>'9', 'parent'=>null, 'name'=>'Contabilidad analítica por función', 'level'=>1, 'operational'=>false, 'rubric'=>'NONE'],
            ['code'=>'94', 'parent'=>'9', 'name'=>'Gastos de administración', 'level'=>2, 'operational'=>false, 'rubric'=>'NONE'],
            ['code'=>'941', 'parent'=>'94', 'name'=>'Administración general', 'level'=>3, 'operational'=>false, 'rubric'=>'NONE'],
            ['code'=>'9411', 'parent'=>'941', 'name'=>'Gastos administrativos distribuidos', 'level'=>4, 'operational'=>false, 'rubric'=>'NONE'],
            ['code'=>'94110', 'parent'=>'9411', 'name'=>'Gastos administrativos - agrupación', 'level'=>5, 'operational'=>false, 'rubric'=>'NONE'],
            ['code'=>'941100', 'parent'=>'94110', 'name'=>'Gastos administrativos - movimiento', 'level'=>6, 'operational'=>true, 'rubric'=>'ERF_GASTOS_ADMIN_94'],
            ['code'=>'95', 'parent'=>'9', 'name'=>'Gastos de venta', 'level'=>2, 'operational'=>false, 'rubric'=>'NONE'],
            ['code'=>'951', 'parent'=>'95', 'name'=>'Ventas y distribución', 'level'=>3, 'operational'=>false, 'rubric'=>'NONE'],
            ['code'=>'9511', 'parent'=>'951', 'name'=>'Gastos comerciales distribuidos', 'level'=>4, 'operational'=>false, 'rubric'=>'NONE'],
            ['code'=>'95110', 'parent'=>'9511', 'name'=>'Gastos comerciales - agrupación', 'level'=>5, 'operational'=>false, 'rubric'=>'NONE'],
            ['code'=>'951100', 'parent'=>'95110', 'name'=>'Gastos de ventas - movimiento', 'level'=>6, 'operational'=>true, 'rubric'=>'ERF_GASTOS_VENTAS_95'],
        ];
    }

    /** @return list<array{code:string,name:string,debit_code:string,credit_code:string}> */
    public static function centerDefinitions(): array
    {
        return [
            ['code'=>'CC-ADM', 'name'=>'Administración general', 'debit_code'=>'941100', 'credit_code'=>'791000'],
            ['code'=>'CC-VTA', 'name'=>'Comercial y ventas', 'debit_code'=>'951100', 'credit_code'=>'791000'],
        ];
    }

    public function installForCompany(int $companyId, bool $bulk = false): void
    {
        if ($bulk) {
            // An entirely new tenant only. Fail closed if analytical accounts
            // or cost centers are present; never overwrite configured tenants.
            $this->installForNewCompany($companyId);
            return;
        }

        $this->tenantContext->run($companyId, static function (): void {
            DB::transaction(static function (): void {
                $bridge = AccountingAccount::query()->where('code', '791000')->first();
                if ($bridge === null || ! $bridge->is_operational || ! $bridge->is_active
                    || strlen((string) $bridge->code) !== 6) {
                    throw new DomainException('Se requiere la cuenta 791000 operativa, de la misma empresa.');
                }

                /** @var array<string, AccountingAccount> $accounts */
                $accounts = [];
                foreach (self::accountDefinitions() as $spec) {
                    $parent = $spec['parent'] === null ? null : ($accounts[$spec['parent']] ?? null);
                    if ($spec['parent'] !== null && $parent === null) {
                        throw new DomainException('Padre analítico inexistente: '.$spec['parent']);
                    }

                    $account = AccountingAccount::query()->where('code', $spec['code'])->first();
                    if ($account !== null) {
                        if ((int) $account->level !== $spec['level']
                            || ($parent === null ? $account->parent_id !== null
                                : (int) $account->parent_id !== (int) $parent->id)
                            || $account->account_type->value !== 'COST'
                            || ! $account->is_active
                            || (bool) $account->is_operational !== $spec['operational']) {
                            throw new DomainException('Código analítico existente incompatible: '.$spec['code']);
                        }
                    } else {
                        $account = AccountingAccount::query()->create([
                            'code'=>$spec['code'],
                            'name'=>$spec['name'],
                            'parent_id'=>$parent?->id,
                            'level'=>$spec['level'],
                            'path'=>$parent === null ? $spec['code'] : $parent->path.'.'.$spec['code'],
                            'account_type'=>'COST',
                            'balance_type'=>'DEBIT',
                            'currency_mode'=>'PEN',
                            'requires_currency'=>false,
                            'requires_auxiliary'=>false,
                            'entity_requirement_type'=>'NONE',
                            'requires_cost_center'=>false,
                            'has_automatic_destination'=>false,
                            'requires_cash_flow_area'=>false,
                            'cash_flow_category'=>'NONE',
                            'financial_statement_rubric'=>$spec['rubric'],
                            'is_operational'=>$spec['operational'],
                            'is_active'=>true,
                        ]);
                    }

                    $accounts[$spec['code']] = $account;
                }

                foreach (self::centerDefinitions() as $spec) {
                    $debit = $accounts[$spec['debit_code']] ?? null;
                    if ($debit === null || ! $debit->is_operational) {
                        throw new DomainException('Destino analítico incorrecto: '.$spec['debit_code']);
                    }

                    $center = CostCenter::query()->where('code', $spec['code'])->first();
                    if ($center !== null) {
                        if ((int) $center->destination_debit_account_id !== (int) $debit->id
                            || (int) $center->destination_credit_account_id !== (int) $bridge->id) {
                            throw new DomainException('Amarre de centro de costos existente incompatible: '.$spec['code']);
                        }
                    } else {
                        CostCenter::query()->create([
                            'code'=>$spec['code'],
                            'name'=>$spec['name'],
                            'destination_debit_account_id'=>$debit->id,
                            'destination_credit_account_id'=>$bridge->id,
                            'is_active'=>true,
                        ]);
                    }
                }
            });
        });
    }
    /**
     * Bulk initialization ONLY for a brand-new company in the enclosing
     * onboarding transaction. Six hierarchical INSERTs + one center INSERT
     * replace ~26 remote Eloquent SELECT/INSERT round trips. Hierarchy
     * triggers and FORCE RLS remain enabled; no BYPASSRLS or SET ROLE.
     */
    private function installForNewCompany(int $companyId): void
    {
        $this->tenantContext->run($companyId, static function () use ($companyId): void {
            DB::transaction(static function () use ($companyId): void {
                $bridge = AccountingAccount::query()->where('code', '791000')->first();
                if ($bridge === null || !$bridge->is_operational || !$bridge->is_active
                    || strlen((string) $bridge->code) !== 6) {
                    throw new DomainException('Se requiere la cuenta 791000 operativa del mismo tenant.');
                }

                $codes = array_column(self::accountDefinitions(), 'code');
                if (DB::table('accounting_accounts')
                    ->where('company_id', $companyId)
                    ->whereIn('code', $codes)->exists()
                    || DB::table('cost_centers')->where('company_id', $companyId)->exists()) {
                    throw new DomainException(
                        'Las cuentas analíticas o centros de costo ya existen. No usar inicialización rápida.'
                    );
                }

                $levels = [];
                foreach (self::accountDefinitions() as $item) {
                    $levels[$item['level']][] = $item;
                }

                for ($level = 1; $level <= 6; $level++) {
                    $specs = $levels[$level] ?? [];
                    if ($specs === []) continue;
                    $json = json_encode($specs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    $inserted = DB::select(<<<'SQL'
                        INSERT INTO public.accounting_accounts (
                            company_id,parent_id,code,name,level,path,
                            account_type,balance_type,currency_mode,
                            requires_currency,requires_auxiliary,entity_requirement_type,
                            requires_cost_center,has_automatic_destination,
                            requires_cash_flow_area,cash_flow_category,
                            financial_statement_rubric,is_operational,is_active,
                            created_at,updated_at
                        )
                        SELECT ?,parent.id,src.code,src.name,src.level,
                            CASE WHEN src.level=1 THEN src.code ELSE parent.path||'.'||src.code END,
                            'COST','DEBIT','PEN',FALSE,FALSE,'NONE',
                            FALSE,FALSE,FALSE,'NONE',src.rubric,src.operational,TRUE,
                            now(),now()
                        FROM jsonb_to_recordset(?::jsonb) AS src (
                            code text,parent text,name text,level integer,
                            operational boolean,rubric text
                        )
                        LEFT JOIN public.accounting_accounts parent
                            ON parent.company_id=? AND parent.code=src.parent
                        WHERE (src.parent IS NULL AND src.level=1) OR parent.id IS NOT NULL
                        RETURNING id,code,path
                        SQL,[$companyId,$json,$companyId]);
                    if (count($inserted)!==count($specs)) {
                        throw new DomainException(
                            'No se crearon todas las cuentas analíticas del nivel '.$level
                        );
                    }
                }

                $destinations = DB::table('accounting_accounts')
                    ->where('company_id',$companyId)
                    ->whereIn('code',['941100','951100'])->get(['id','code','is_operational'])
                    ->keyBy('code');
                $now = now();
                $rows = [];
                foreach (self::centerDefinitions() as $spec) {
                    $debit = $destinations->get($spec['debit_code']);
                    if ($debit === null || !$debit->is_operational) {
                        throw new DomainException('No existe destino analítico '.$spec['debit_code']);
                    }
                    $rows[] = [
                        'company_id' => $companyId,
                        'code' => $spec['code'],
                        'name' => $spec['name'],
                        'destination_debit_account_id' => $debit->id,
                        'destination_credit_account_id' => $bridge->id,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('cost_centers')->insert($rows);
            });
        });
    }

}
