<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Services\Accounting\AnnualTaxCloseCalculator;
use App\Services\Accounting\AnnualTaxCloseTrialBalance;
use App\Services\Security\AuditLoggerService;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final readonly class AnnualTaxCloseController
{
    private const TABLE = 'company_annual_tax_close_revisions';

    public function __construct(
        private TenantContext $tenant,
        private AnnualTaxCloseCalculator $calculator,
        private AuditLoggerService $audit,
    ) {}

    public function show(Request $request, int $year): JsonResponse
    {
        $query = $this->revisions($year);
        $filter = $request->validate(['revision' => ['sometimes', 'integer', 'min:1']]);
        $latest = (clone $query)->orderByDesc('revision')->first();
        $row = isset($filter['revision'])
            ? (clone $query)->where('revision', $filter['revision'])->first()
            : $latest;
        abort_if(isset($filter['revision']) && !$row, 404, 'Revisión inexistente.');

        $history = $query->orderByDesc('revision')->limit(25)
            ->get(['revision', 'status', 'reason', 'created_by', 'created_at']);

        return response()->json(['data' => [
            'year' => $year,
            'rule_version' => $row->rule_version ?? AnnualTaxCloseCalculator::VERSION,
            'latest_revision' => (int) ($latest->revision ?? 0),
            'revision' => (int) ($row->revision ?? 0),
            'status' => $row->status ?? 'DRAFT',
            'inputs' => $row ? json_decode($row->inputs, true, 512, JSON_THROW_ON_ERROR) : null,
            'result' => $row ? json_decode($row->result, true, 512, JSON_THROW_ON_ERROR) : null,
            'history' => $history,
        ]]);
    }

    public function trialBalance(Request $request, AnnualTaxCloseTrialBalance $balance, int $year): JsonResponse
    {
        $this->revisions($year);
        $input = $request->validate(['opening' => ['nullable', 'in:historical,entry']]);

        return response()->json(['data' => $balance->prepare($year, $input['opening'] ?? null)]);
    }

    public function save(Request $request, int $year): JsonResponse
    {
        $query = $this->revisions($year);
        $amount = ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/D'];
        $percent = ['required', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/D', 'numeric', 'between:0,100'];
        $reference = ['nullable', 'string', 'max:500'];
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'company_id' => ['prohibited'],
            'status' => ['prohibited'],
            'inputs' => ['required', 'array:book_profit,book_reference,prior_losses_used,participation_rate,participation_deductible,participation_admin,participation_sales,participation_reference,adjustments,itan_assets,itan_additions,itan_deductions,itan_paid,itan_at_close,itan_reference,advances,ciprl_available,ciprl_applied,ciprl_reference,deferred'],
            'inputs.book_profit' => ['required', 'string', 'regex:/^-?\d{1,12}(\.\d{1,2})?$/D'],
            'inputs.book_reference' => $reference,
            'inputs.prior_losses_used' => $amount,
            'inputs.participation_rate' => $percent,
            'inputs.participation_deductible' => $amount,
            'inputs.participation_admin' => ['sometimes', ...$amount],
            'inputs.participation_sales' => ['sometimes', ...$amount],
            'inputs.participation_reference' => $reference,
            'inputs.adjustments' => ['present', 'array', 'list', 'max:250'],
            'inputs.adjustments.*' => ['array:label,direction,kind,book_base,tax_base,account,reference'],
            'inputs.adjustments.*.label' => ['required', 'string', 'max:150'],
            'inputs.adjustments.*.direction' => ['required', 'in:ADDITION,DEDUCTION'],
            'inputs.adjustments.*.kind' => ['required', 'in:PERMANENT,TEMPORARY'],
            'inputs.adjustments.*.book_base' => $amount,
            'inputs.adjustments.*.tax_base' => $amount,
            'inputs.adjustments.*.account' => ['nullable', 'string', 'regex:/^\d{2,8}$/D'],
            'inputs.adjustments.*.reference' => $reference,
            'inputs.itan_assets' => $amount,
            'inputs.itan_additions' => $amount,
            'inputs.itan_deductions' => $amount,
            'inputs.itan_paid' => $amount,
            'inputs.itan_at_close' => $amount,
            'inputs.itan_reference' => $reference,
            'inputs.advances' => ['present', 'array', 'list', 'max:12'],
            'inputs.advances.*' => ['array:month,assessed,cash_paid,itan_used,reference'],
            'inputs.advances.*.month' => ['required', 'integer', 'between:1,12', 'distinct'],
            'inputs.advances.*.assessed' => $amount,
            'inputs.advances.*.cash_paid' => $amount,
            'inputs.advances.*.itan_used' => $amount,
            'inputs.advances.*.reference' => $reference,
            'inputs.ciprl_available' => $amount,
            'inputs.ciprl_applied' => $amount,
            'inputs.ciprl_reference' => $reference,
            'inputs.deferred' => ['present', 'array', 'list', 'max:250'],
            'inputs.deferred.*' => ['array:label,kind,opening_tax,closing_difference,rate,recognize_closing,reference'],
            'inputs.deferred.*.label' => ['required', 'string', 'max:150'],
            'inputs.deferred.*.kind' => ['required', 'in:ASSET,LIABILITY'],
            'inputs.deferred.*.opening_tax' => $amount,
            'inputs.deferred.*.closing_difference' => $amount,
            'inputs.deferred.*.rate' => $percent,
            'inputs.deferred.*.recognize_closing' => ['required', 'boolean'],
            'inputs.deferred.*.reference' => $reference,
        ]);

        $inputs = $data['inputs'];
        $result = $this->calculator->calculate($inputs);

        return response()->json(['data' => $this->append(
            $request, $year, $query, (int) $data['expected_revision'], ['DRAFT'], 'DRAFT', $inputs, $result
        )]);
    }

    public function transition(Request $request, int $year, string $action): JsonResponse
    {
        $query = $this->revisions($year);
        $data = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:1'],
            'reason' => [in_array($action, ['return', 'rectify'], true) ? 'required' : 'nullable', 'string', 'max:500'],
        ]);

        [$from, $to] = match ($action) {
            'submit' => ['DRAFT', 'IN_REVIEW'],
            'approve' => ['IN_REVIEW', 'APPROVED'],
            'return' => ['IN_REVIEW', 'DRAFT'],
            'rectify' => ['APPROVED', 'DRAFT'],
        };

        return response()->json(['data' => $this->append(
            $request, $year, $query, (int) $data['expected_revision'], [$from], $to, reason: $data['reason'] ?? null
        )]);
    }

    private function revisions(int $year): \Illuminate\Database\Query\Builder
    {
        abort_if($year !== AnnualTaxCloseCalculator::YEAR, 404, 'Ejercicio sin reglas configuradas.');
        abort_unless(Schema::hasTable(self::TABLE), 503, 'Aplique la migración del cierre tributario.');

        return DB::table(self::TABLE)->where('company_id', $this->tenant->requireCompanyId(self::class))
            ->where('year', $year);
    }

    private function append(
        Request $request,
        int $year,
        \Illuminate\Database\Query\Builder $query,
        int $expected,
        array $from,
        string $status,
        ?array $inputs = null,
        ?array $result = null,
        ?string $reason = null,
    ): array {
        $company = $this->tenant->requireCompanyId(self::class);
        $actor = (int) $request->user()->id;

        return DB::transaction(function () use ($request, $year, $query, $expected, $from, $status, $inputs, $result, $reason, $company, $actor) {
            DB::table('companies')->where('id', $company)->lockForUpdate()->first();
            $latest = $query->orderByDesc('revision')->first();
            $current = (int) ($latest->revision ?? 0);
            $before = $latest->status ?? 'DRAFT';
            if ($current !== $expected || !in_array($before, $from, true)) {
                throw new ConflictHttpException('El expediente cambió. Recargue antes de continuar.');
            }

            $inputs ??= json_decode($latest->inputs, true, 512, JSON_THROW_ON_ERROR);
            $result ??= json_decode($latest->result, true, 512, JSON_THROW_ON_ERROR);
            if ($status === 'IN_REVIEW') {
                $this->checkReferences($inputs);
            }
            $version = $latest->rule_version ?? AnnualTaxCloseCalculator::VERSION;
            $revision = $current + 1;
            DB::table(self::TABLE)->insert([
                'company_id' => $company, 'year' => $year, 'revision' => $revision,
                'status' => $status, 'rule_version' => $version, 'reason' => $reason,
                'inputs' => json_encode($inputs, JSON_THROW_ON_ERROR),
                'result' => json_encode($result, JSON_THROW_ON_ERROR),
                'created_by' => $actor, 'created_at' => now(),
            ]);
            $this->audit->log('ANNUAL_TAX_CLOSE_REVISION', $company, $actor, self::TABLE, "$year:$revision",
                ['revision' => $current, 'status' => $before],
                ['revision' => $revision, 'status' => $status, 'reason' => $reason], $request);

            return ['revision' => $revision, 'status' => $status];
        });
    }

    private function checkReferences(array $inputs): void
    {
        $errors = [];
        $check = static function (string $key, ?string $value) use (&$errors): void {
            if (trim($value ?? '') === '') {
                $errors["inputs.$key"] = 'Ingrese la referencia de sustento.';
            }
        };

        $check('book_reference', $inputs['book_reference'] ?? null);
        if (bccomp($inputs['participation_rate'], '0', 2) > 0 || bccomp($inputs['prior_losses_used'], '0', 2) > 0) {
            $check('participation_reference', $inputs['participation_reference'] ?? null);
        }
        foreach (['itan' => 'itan_paid', 'ciprl' => 'ciprl_applied'] as $prefix => $field) {
            if (bccomp($inputs[$field], '0', 2) > 0) {
                $check($prefix.'_reference', $inputs[$prefix.'_reference'] ?? null);
            }
        }
        foreach (['adjustments', 'advances', 'deferred'] as $group) {
            foreach ($inputs[$group] as $index => $line) {
                $check("$group.$index.reference", $line['reference'] ?? null);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
