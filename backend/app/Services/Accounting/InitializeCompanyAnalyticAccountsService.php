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

    public function installForCompany(int $companyId): void
    {
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
}
