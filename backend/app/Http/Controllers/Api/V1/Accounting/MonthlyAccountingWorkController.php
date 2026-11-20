<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Services\Accounting\AssetAndLoanCalculations;
use App\Services\Accounting\MonthlyAccountingWork;
use App\Support\Tenant\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class MonthlyAccountingWorkController
{
    private const MONEY = ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/D'];

    public function __construct(private MonthlyAccountingWork $work, private TenantContext $tenant) {}

    public function show(string $month): JsonResponse
    {
        return response()->json(['data' => $this->work->state($this->work->period($month))]);
    }

    public function alerts(): JsonResponse
    {
        return response()->json(['data' => $this->work->alerts(CarbonImmutable::today('America/Lima')->startOfMonth())]);
    }

    public function confirm(Request $request, string $month): JsonResponse
    {
        $period = $this->work->period($month);
        $values = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'prico' => ['required', 'boolean'],
            'retention' => ['required', 'boolean'],
            'perception' => ['required', 'boolean'],
            'workers' => ['required', 'boolean'],
            'afp' => ['required', 'boolean'],
            'cts' => ['required', 'boolean'],
            'gratification' => ['required', 'boolean'],
            'assets' => ['required', 'boolean'],
            'internal_due' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$period->endOfMonth()->toDateString(),
                'before_or_equal:'.$period->addMonth()->endOfMonth()->toDateString()],
            'evidence' => ['required', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($period, $request, $values): void {
            $this->work->lockCompany();
            $preview = $this->work->preview($period, $values);
            if (!$preview['tax_profile_ready']) {
                throw ValidationException::withMessages(['calendar' => 'Guarde el perfil de Vencimientos SUNAT y confirme el tramo de ingresos aplicable.']);
            }
            $undated = array_values(array_filter($preview['tasks'], static fn (array $task) => !$task['due'] && !($task['annual'] ?? false)));
            if ($undated !== []) {
                throw ValidationException::withMessages(['calendar' => 'Registre las fechas que faltan en Vencimientos SUNAT: '.implode(', ', array_column($undated, 'label')).'.']);
            }
            $this->work->append($period, MonthlyAccountingWork::CONFIG, (int) $values['expected_revision'], $request->user()->id,
                ['values' => collect($values)->except('expected_revision')->all(), 'tasks' => $preview['tasks'],
                    'calendar_revision' => $preview['calendar_revision']]);
        });

        return $this->show($month);
    }

    public function task(Request $request, string $month, string $code): JsonResponse
    {
        $period = $this->work->period($month);
        $values = $request->validate([
            'done' => ['required', 'boolean'],
            'expected_revision' => ['required', 'integer', 'min:0'],
            'evidence' => ['required', 'string', 'max:500'],
        ]);
        DB::transaction(function () use ($period, $code, $request, $values): void {
            $this->work->lockCompany();
            $this->work->requireConfirmation($period);
            $task = collect($this->work->state($period)['tasks'])->firstWhere('code', $code);
            abort_if(!$task, 404, 'La tarea no corresponde a la empresa para este periodo.');
            abort_if($values['done'] && !$task['due'], 409, 'Registre el vencimiento antes de completar esta tarea.');
            if ($values['done'] && in_array($code, ['payroll', 'depreciation'], true)) {
                $paper = $this->work->workpaper($period, $code);
                abort_if($paper['revision'] === 0, 409, 'Genere y guarde el papel de trabajo antes de completar esta tarea.');
                abort_if($code === 'depreciation' && $paper['data']['totals']['tax_pending'] > 0, 409,
                    'Complete los datos fiscales pendientes y vuelva a generar la depreciación.');
            }
            $this->work->append($period, 'task-'.$code, (int) $values['expected_revision'], $request->user()->id,
                ['done' => (bool) $values['done'], 'evidence' => $values['evidence'], 'task_due' => $task['due']]);
        });

        return $this->show($month);
    }

    public function paper(string $month, string $code): JsonResponse
    {
        return response()->json($this->work->workpaper($this->work->period($month), $code));
    }

    public function depreciation(Request $request, string $month, AssetAndLoanCalculations $calculations): JsonResponse
    {
        $period = $this->work->period($month);
        $values = $request->validate(['expected_revision' => ['required', 'integer', 'min:0']]);
        DB::transaction(function () use ($request, $period, $values, $calculations): void {
            $this->work->lockCompany();
            $this->ensureWorkpaperCanChange($period, 'depreciation');
            $assets = DB::table('company_financial_auxiliary_revisions')
                ->where('company_id', $this->tenant->requireCompanyId(self::class))
                ->where('kind', 'asset')->selectRaw('DISTINCT ON (reference) reference, revision, label, inputs')
                ->orderBy('reference')->orderByDesc('revision')->cursor();
            $rows = [];
            $totals = ['book' => '0.00', 'tax' => '0.00', 'assets' => 0, 'tax_pending' => 0];
            foreach ($assets as $asset) {
                $input = json_decode($asset->inputs, true, 512, JSON_THROW_ON_ERROR);
                $taxConfirmed = (int) ($input['tax_year'] ?? AssetAndLoanCalculations::TAX_YEAR) === $period->year;
                $result = $calculations->asset($input, $period->year);
                $row = $result['rows'][$period->month - 1];
                $tax = $taxConfirmed ? $row['tax_depreciation'] : null;
                $rows[] = ['reference' => $asset->reference, 'label' => $asset->label, 'source_revision' => (int) $asset->revision,
                    'book' => $row['book_depreciation'], 'tax' => $tax, 'carrying_amount' => $row['carrying_amount']];
                $totals['book'] = bcadd($totals['book'], $row['book_depreciation'], 2);
                $totals['tax'] = bcadd($totals['tax'], $tax ?? '0', 2);
                $totals['assets']++;
                $totals['tax_pending'] += (int) !$taxConfirmed;
            }
            abort_if($rows === [], 422, 'Registre las fichas de activos fijos antes de generar la depreciación.');
            $this->work->append($period, 'depreciation', (int) $values['expected_revision'], $request->user()->id, compact('rows', 'totals'));
        });

        return $this->paper($month, 'depreciation');
    }

    public function payroll(Request $request, string $month): JsonResponse
    {
        $period = $this->work->period($month);
        $values = $request->validate([
            'expected_revision' => ['required', 'integer', 'min:0'],
            'source' => ['required', 'string', 'max:250'],
            'rows' => ['required', 'array', 'list', 'min:1', 'max:1000'],
            'rows.*' => ['array:worker,regular,other,pension,income_tax,other_deductions,employer_contributions'],
            'rows.*.worker' => ['required', 'string', 'max:80', 'distinct:ignore_case'],
            'rows.*.regular' => self::MONEY,
            'rows.*.other' => self::MONEY,
            'rows.*.pension' => self::MONEY,
            'rows.*.income_tax' => self::MONEY,
            'rows.*.other_deductions' => self::MONEY,
            'rows.*.employer_contributions' => self::MONEY,
        ]);
        $totals = ['gross' => '0.00', 'deductions' => '0.00', 'net' => '0.00', 'employer_contributions' => '0.00',
            'employer_cost' => '0.00', 'workers' => count($values['rows'])];
        $rows = [];
        foreach ($values['rows'] as $i => $row) {
            $gross = bcadd($row['regular'], $row['other'], 2);
            $deductions = bcadd(bcadd($row['pension'], $row['income_tax'], 2), $row['other_deductions'], 2);
            $net = bcsub($gross, $deductions, 2);
            if (bccomp($net, '0', 2) < 0) {
                throw ValidationException::withMessages(["rows.$i.other_deductions" => 'Las retenciones y descuentos superan el ingreso de '.$row['worker'].'.']);
            }
            $cost = bcadd($gross, $row['employer_contributions'], 2);
            $rows[] = $row + ['gross' => $gross, 'deductions' => $deductions, 'net' => $net, 'employer_cost' => $cost];
            foreach (['gross' => $gross, 'deductions' => $deductions, 'net' => $net,
                'employer_contributions' => $row['employer_contributions'], 'employer_cost' => $cost] as $key => $value) {
                $totals[$key] = bcadd($totals[$key], $value, 2);
            }
        }
        DB::transaction(function () use ($request, $period, $values, $rows, $totals): void {
            $this->work->lockCompany();
            $this->ensureWorkpaperCanChange($period, 'payroll');
            $this->work->append($period, 'payroll', (int) $values['expected_revision'], $request->user()->id,
                ['source' => $values['source'], 'rows' => $rows, 'totals' => $totals]);
        });

        return $this->paper($month, 'payroll');
    }

    private function ensureWorkpaperCanChange(CarbonImmutable $period, string $code): void
    {
        $this->work->requireConfirmation($period);
        $task = collect($this->work->state($period)['tasks'])->firstWhere('code', $code);
        abort_if(!$task, 409, 'Confirme que esta obligación aplica a la empresa para el periodo.');
        abort_if($task['status'] === 'done', 409, 'Reabra la tarea con un motivo antes de modificar el papel de trabajo.');
    }
}
