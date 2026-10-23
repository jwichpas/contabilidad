<?php

declare(strict_types=1);

namespace App\Services\Company;

use App\Models\AccountingAccount;
use App\Models\AuditLog;
use App\Models\CostCenter;
use App\Services\Accounting\InitializeCompanyAnalyticAccountsService;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Writes and obligatory audit share one transaction under the active tenant. */
final readonly class CostCenterMasterService
{
    private const FIELDS = [
        'code', 'name', 'destination_debit_account_id',
        'destination_credit_account_id', 'is_active',
    ];

    public function __construct(private TenantContext $tenantContext) {}

    public function save(array $data, ?int $id, Request $request): CostCenter
    {
        $companyId = $this->tenantContext->requireCompanyId(CostCenter::class);

        try {
            return DB::transaction(function () use ($data, $id, $request, $companyId): CostCenter {
                $this->lockRouting($companyId);
                $center = $id === null ? new CostCenter() : CostCenter::query()->lockForUpdate()->findOrFail($id);
                if ($id !== null && ! $center->is_active) {
                    throw ValidationException::withMessages(['cost_center' => 'No se puede editar un centro desactivado.']);
                }
                $old = $id === null ? null : $center->only(self::FIELDS);
                $center->fill(array_intersect_key($data, array_flip(self::FIELDS)));
                if ($id === null) {
                    $center->is_active = true;
                }
                $destinationsChanged = $id === null || $center->isDirty([
                    'destination_debit_account_id', 'destination_credit_account_id',
                ]);
                if ($destinationsChanged) {
                    if ($id !== null && $this->hasHistoryOrRoutes($companyId, $id)) {
                        throw ValidationException::withMessages([
                            'destination_debit_account_id' => 'El centro tiene movimientos o rutas versionadas. Cree otro centro para un destino diferente.',
                        ]);
                    }
                    $this->validateDestinations($center);
                }
                if ($id === null || $center->isDirty()) {
                    $center->save();
                    $this->audit($id === null ? 'COST_CENTER_CREATED' : 'COST_CENTER_UPDATED',
                        $center, $old, $request, $companyId);
                }

                return $center->load(['destinationDebitAccount', 'destinationCreditAccount']);
            });
        } catch (QueryException $exception) {
            // The savepoint has rolled back before returning a validation error.
            // Do not misreport an unrelated unique violation (e.g. audit) as a duplicate code.
            if (($exception->errorInfo[0] ?? '') === '23505'
                && str_contains((string) ($exception->errorInfo[2] ?? ''), 'uq_cost_centers_company_code')) {
                throw ValidationException::withMessages(['code' => 'Ya existe ese código de centro de costo en la empresa.']);
            }
            throw $exception;
        }
    }

    public function deactivate(int $id, Request $request): CostCenter
    {
        $companyId = $this->tenantContext->requireCompanyId(CostCenter::class);

        return DB::transaction(function () use ($id, $request, $companyId): CostCenter {
            $this->lockRouting($companyId);
            $center = CostCenter::query()->lockForUpdate()->findOrFail($id);
            if ($center->is_active) {
                $referenced = DB::table('accounting_destination_routes as r')
                    ->join('accounting_destination_rule_versions as v', function ($join): void {
                        $join->on('v.company_id', '=', 'r.company_id')->on('v.id', '=', 'r.rule_version_id');
                    })
                    ->where('r.company_id', $companyId)->where('r.cost_center_id', $id)
                    ->whereIn('v.status', ['DRAFT', 'ACTIVE'])->exists();
                if ($referenced) {
                    throw ValidationException::withMessages([
                        'cost_center' => 'El centro participa en una matriz DRAFT o ACTIVE. Revise la matriz antes de desactivarlo.',
                    ]);
                }
                $old = $center->only(self::FIELDS);
                $center->is_active = false;
                $center->save();
                $this->audit('COST_CENTER_DEACTIVATED', $center, $old, $request, $companyId);
            }

            return $center->load(['destinationDebitAccount', 'destinationCreditAccount']);
        });
    }

    private function lockRouting(int $companyId): void
    {
        // Same key as POST (shared) and matrix promotion (exclusive), taken
        // BEFORE locking center/account rows. No company row lock or DDL.
        DB::selectOne('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', [
            'nexus:analytic-routing:'.$companyId,
        ]);
    }

    private function hasHistoryOrRoutes(int $companyId, int $id): bool
    {
        return DB::table('journal_entry_lines')->where('company_id', $companyId)
            ->where('cost_center_id', $id)->exists()
            || DB::table('accounting_destination_routes')->where('company_id', $companyId)
                ->where('cost_center_id', $id)->exists();
    }

    private function validateDestinations(CostCenter $center): void
    {
        // Lock in deterministic order so account deactivation cannot race validation.
        $accounts = AccountingAccount::query()->whereIn('id', [
            $center->destination_debit_account_id, $center->destination_credit_account_id,
        ])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $definitions = InitializeCompanyAnalyticAccountsService::centerDefinitions();
        foreach ([
            'destination_debit_account_id' => array_column($definitions, 'debit_code'),
            'destination_credit_account_id' => array_column($definitions, 'credit_code'),
        ] as $field => $supportedCodes) {
            $account = $accounts->get($center->getAttribute($field));
            if ($account === null || ! $account->is_active || ! $account->is_operational
                || strlen((string) $account->code) !== 6
                || ! in_array((string) $account->code, $supportedCodes, true)) {
                throw ValidationException::withMessages([
                    $field => 'Seleccione una cuenta activa y operativa de esta empresa. Destinos admitidos: '.implode(', ', array_unique($supportedCodes)).'.',
                ]);
            }
        }
    }

    private function audit(string $event, CostCenter $center, ?array $old, Request $request, int $companyId): void
    {
        AuditLog::query()->create([
            'company_id' => $companyId,
            'user_id' => $request->user()?->id,
            'event_type' => $event,
            'auditable_type' => CostCenter::class,
            'auditable_id' => (string) $center->id,
            'old_values' => $old,
            'new_values' => $center->only(self::FIELDS),
            'ip_address' => substr((string) $request->ip(), 0, 45),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'severity' => 'INFO',
        ]);
    }
}
