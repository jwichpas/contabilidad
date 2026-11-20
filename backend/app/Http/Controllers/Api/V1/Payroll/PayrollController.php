<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Services\Payroll\PayrollCalculation;
use App\Services\Payroll\PayrollExports;
use App\Services\Payroll\PayrollLedger;
use App\Services\Payroll\PayrollParameters;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final readonly class PayrollController
{
    private const MONEY = ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/D'];
    private const RATE = ['required', 'string', 'regex:/^\d{1,2}(\.\d{1,4})?$/D', 'numeric', 'between:0,10'];
    private const REVISION = ['required', 'integer', 'min:0'];

    public function __construct(
        private PayrollLedger $ledger,
        private PayrollCalculation $calculation,
        private PayrollParameters $parameters,
    ) {}

    public function show(string $month): JsonResponse
    {
        $eligible = $this->eligible($month);
        $daily = $this->ledger->records('day', $month.'-');
        $attendance = $this->ledger->records('attendance', $month.'-');
        $versions = $this->dayVersions($daily);
        $pending = [];
        foreach ($eligible as $person) {
            $code = $person['data']['code'];
            $issue = $this->attendanceIssue($person, $attendance["$month-$code"] ?? null, $versions[$code] ?? []);
            if ($issue !== null) {
                $pending[] = ['worker' => $code, 'message' => $issue];
            }
        }

        return $this->json([
            'workers' => array_values($this->ledger->records('worker')),
            'eligible' => array_values($eligible),
            'daily' => array_values($daily),
            'attendance' => array_values($attendance),
            'attendance_pending' => $pending,
            'parameters' => $this->parameters->forMonth($month),
            'vacations' => array_values($this->ledger->records('vacation')),
            'benefits' => array_values($this->ledger->records('benefit', $month.'-')),
            'payroll' => $this->ledger->latest('payroll', $month),
        ]);
    }

    public function worker(Request $request, string $worker): JsonResponse
    {
        $data = $request->validate([
            'expected_revision' => self::REVISION,
            'dni' => ['required', 'string', 'regex:/^\d{8}$/D'],
            'surname' => ['required', 'string', 'max:60'],
            'second_surname' => ['nullable', 'string', 'max:60'],
            'given_names' => ['required', 'string', 'max:80'],
            'position' => ['required', 'string', 'max:120'],
            'effective_month' => ['required', 'date_format:Y-m'],
            'hired_on' => ['required', 'date_format:Y-m-d'],
            'ended_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:hired_on'],
            'salary' => [...self::MONEY, 'numeric', 'gt:0'],
            'daily_hours' => ['required', 'integer', 'between:4,8'],
            'pension' => ['required', 'in:ONP,AFP'],
            'afp' => ['nullable', 'required_if:pension,AFP', 'in:HA,IN,PR,RI'],
            'family_allowance' => ['sometimes', 'boolean'],
            'night_shift' => ['sometimes', 'boolean'],
            'afp_commission' => ['nullable', 'in:FLOW,BALANCE', 'exclude_unless:pension,AFP'],
            'cuspp' => ['nullable', 'required_if:pension,AFP', 'regex:/^[A-Z0-9]{12}$/D'],
            'vacation_granted' => ['required', 'integer', 'between:0,1000'],
            'reference' => ['required', 'string', 'max:500'],
        ], [
            'cuspp.regex' => 'El CUSPP debe tener 12 caracteres alfanuméricos en mayúsculas.',
        ]);
        return $this->write($request, 'worker', $worker, $data, function (array $input) use ($worker): array {
            $current = $this->ledger->latest('worker', $worker)['data'] ?? null;
            $this->check($current && $input['effective_month'] < $current['effective_month'], 'effective_month',
                'La vigencia debe ser igual o posterior a la revisión actual.');
            $this->check($current && $input['dni'] !== $current['dni'], 'dni', 'El DNI identifica el expediente y requiere una rectificación administrativa.');
            $this->check(collect($this->ledger->records('worker'))->contains(
                fn (array $record) => $record['reference'] !== $worker && $record['data']['dni'] === $input['dni']),
                'dni', 'Ya existe un trabajador con ese DNI.');
            $this->check($input['vacation_granted'] < $this->vacationDays($worker), 'vacation_granted',
                'Los días reconocidos no pueden quedar por debajo de los descansos registrados.');
            $previous = array_intersect_key($current ?? [], array_flip(['family_allowance', 'night_shift', 'afp_commission']));
            if ($input['pension'] !== 'AFP') {
                unset($previous['afp_commission']);
            }
            return ['code' => $worker] + $input + $previous;
        });
    }

    public function day(Request $request, string $month, string $worker, string $date): JsonResponse
    {
        $data = $request->validate([
            'expected_revision' => self::REVISION,
            'date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', 'in:WORKED,REST,VACATION,PAID_LEAVE,UNPAID'],
            'ordinary' => ['required', 'integer', 'between:0,480'],
            'extra' => ['required', 'integer', 'between:0,960'],
            'reference' => ['required', 'string', 'max:500'],
        ]);
        $this->check($date !== $data['date'] || !str_starts_with($date, $month.'-'), 'date', 'La fecha debe pertenecer al periodo.');
        return $this->write($request, 'day', "$month-$worker-$date", $data, function (array $input) use ($month, $worker, $date): array {
            $person = $this->eligible($month)[$worker]['data'] ?? null;
            abort_unless($person && $date >= $person['hired_on'] && $date <= ($person['ended_on'] ?? '9999-12-31'),
                404, 'La fecha está fuera del vínculo laboral.');
            abort_if(($this->ledger->latest('payroll', $month)['data']['approved'] ?? false), 409, 'La planilla del periodo está aprobada.');
            $this->check($input['ordinary'] > $person['daily_hours'] * 60 ||
                ($input['status'] !== 'WORKED' && $input['ordinary'] + $input['extra'] !== 0),
                'ordinary', 'Revise la jornada pactada y el estado del día.');
            return ['worker' => $worker] + $input;
        });
    }

    public function attendance(Request $request, string $month, string $worker): JsonResponse
    {
        $data = $request->validate([
            'expected_revision' => self::REVISION,
            'paid_days' => ['required', 'integer', 'between:0,30'],
            'vacation_paid_days' => ['required', 'integer', 'between:0,30', 'lte:paid_days'],
            'ordinary_minutes' => ['required', 'integer', 'between:0,21659'],
            'overtime_25' => ['required', 'integer', 'between:0,21659'],
            'overtime_35' => ['required', 'integer', 'between:0,21659'],
            'taxable_extra' => self::MONEY, 'nontaxable_extra' => self::MONEY,
            'income_tax' => self::MONEY, 'other_deductions' => self::MONEY,
            'reference' => ['required', 'string', 'max:500'],
            'afp_rates' => ['nullable', 'array:insurance,commission,insurance_ceiling,source', 'required_array_keys:insurance,commission,insurance_ceiling,source'],
            'afp_rates.insurance' => ['required_with:afp_rates', ...array_slice(self::RATE, 1)],
            'afp_rates.commission' => ['required_with:afp_rates', ...array_slice(self::RATE, 1)],
            'afp_rates.insurance_ceiling' => ['required_with:afp_rates', ...array_slice(self::MONEY, 1), 'numeric', 'gt:0'],
            'afp_rates.source' => ['required_with:afp_rates', 'string', 'max:250'],
            'provisions' => ['nullable', 'array:gratification_base,gratification_month,cts_base,prior_gratification,cts_days,vacation_base,vacation_days,coverage,reference',
                'required_array_keys:gratification_base,gratification_month,cts_base,prior_gratification,cts_days,vacation_base,vacation_days,coverage,reference'],
            'provisions.gratification_base' => ['required_with:provisions', ...array_slice(self::MONEY, 1)],
            'provisions.gratification_month' => ['required_with:provisions', 'boolean'],
            'provisions.cts_base' => ['required_with:provisions', ...array_slice(self::MONEY, 1)],
            'provisions.prior_gratification' => ['required_with:provisions', ...array_slice(self::MONEY, 1)],
            'provisions.cts_days' => ['required_with:provisions', 'integer', 'between:0,30'],
            'provisions.vacation_base' => ['required_with:provisions', ...array_slice(self::MONEY, 1)],
            'provisions.vacation_days' => ['required_with:provisions', 'integer', 'between:0,30'],
            'provisions.coverage' => ['required_with:provisions', 'in:ESSALUD,EPS'],
            'provisions.reference' => ['required_with:provisions', 'string', 'max:500'],
        ]);
        return $this->write($request, 'attendance', "$month-$worker", $data, function (array $input) use ($month, $worker): array {
            $workerRecord = $this->eligible($month)[$worker] ?? null;
            $person = $workerRecord['data'] ?? null;
            abort_unless($person, 404, 'Trabajador sin vigencia para el periodo solicitado.');
            abort_if(($this->ledger->latest('payroll', $month)['data']['approved'] ?? false), 409, 'La planilla del periodo está aprobada.');
            [$first, $last] = $this->bounds($month);
            $start = max($first, $person['hired_on']);
            $end = min($last, $person['ended_on'] ?? $last);
            $days = $start === $first && $end === $last ? 30 : min(30, (int) CarbonImmutable::parse($start)->diffInDays($end) + 1);
            $vacation = 0;
            foreach ($this->ledger->records('vacation', $worker.'-') as $record) {
                $rest = $record['data'];
                if ($rest['worker'] === $worker && $rest['from'] <= $last && $rest['to'] >= $first) {
                    $vacation += (int) CarbonImmutable::parse(max($first, $rest['from']))->diffInDays(min($last, $rest['to'])) + 1;
                }
            }
            $this->check($input['vacation_paid_days'] > $vacation, 'vacation_paid_days', 'Los días de vacaciones exceden el descanso registrado.');
            $this->check($input['paid_days'] > $days || ($input['paid_days'] == 0 && $input['ordinary_minutes'] > 0),
                'paid_days', 'Revise los días remunerados respecto de la jornada y el vínculo laboral.');
            $this->check($input['vacation_paid_days'] > $input['paid_days'], 'vacation_paid_days',
                'Los días de vacaciones deben estar incluidos en los días remunerados.');
            $this->check($input['overtime_25'] + $input['overtime_35'] > 21659, 'overtime_35', 'El sobretiempo excede el máximo de la jornada PLAME.');
            if (! array_key_exists('provisions', $input)) {
                $previous = $this->ledger->latest('attendance', "$month-$worker")['data']['provisions'] ?? null;
                if ($previous !== null) {
                    $input['provisions'] = $previous;
                }
            }
            return ['worker' => $worker, 'worker_revision' => $workerRecord['revision'], 'daily_versions' => $this->dayVersions($this->ledger->records('day', $month.'-'))[$worker] ?? []] + $input;
        });
    }

    public function run(Request $request, string $month): JsonResponse
    {
        $data = $request->validate([
            'expected_revision' => self::REVISION,
            'health_minimum' => [...self::MONEY, 'numeric', 'gt:0'],
            'source' => ['required', 'string', 'max:500'],
        ]);
        return $this->write($request, 'payroll', $month, $data, function (array $input) use ($month): array {
            abort_if(($this->ledger->latest('payroll', $month)['data']['approved'] ?? false), 409, 'La planilla ya está aprobada.');
            $parameters = $this->parameters->forMonth($month);
            if ($parameters['minimum']) {
                $this->check(bccomp($input['health_minimum'], $parameters['minimum']['amount'], 2) !== 0,
                    'health_minimum', 'Use la RMV publicada para la base mínima de EsSalud del periodo.');
            }
            return $this->snapshot($month, $input, $parameters) + ['approved' => false, 'parameters' => $parameters];
        });
    }

    public function approve(Request $request, string $month): JsonResponse
    {
        $data = $request->validate(['expected_revision' => self::REVISION]);
        return $this->write($request, 'payroll', $month, $data, function () use ($month): array {
            $draft = $this->ledger->latest('payroll', $month)['data'] ?? null;
            abort_if(!$draft || $draft['approved'], 409, 'Genere un cálculo pendiente antes de aprobarlo.');
            $parameters = $draft['parameters'] ?? [];
            $minimum = ($draft['parameters'] ?? $this->parameters->forMonth($month))['minimum'];
            if ($minimum) {
                abort_if(bccomp($draft['health_minimum'], $minimum['amount'], 2) !== 0, 409,
                    'La base mínima del borrador difiere de la RMV del periodo. Recalcule la planilla.');
            }
            $current = $this->snapshot($month, ['health_minimum' => $draft['health_minimum'], 'source' => $draft['source']], $parameters);
            abort_if($current['rows'] != $draft['rows'], 409, 'Cambió un trabajador o su asistencia. Vuelva a calcular el periodo.');
            return array_replace($draft, ['approved' => true]);
        });
    }

    public function vacation(Request $request, string $worker): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'reference' => ['required', 'string', 'max:500'],
        ]);
        return $this->write($request, 'vacation', $worker.'-'.$data['from'], $data + ['expected_revision' => 0],
            function (array $input) use ($worker): array {
                $person = $this->ledger->latest('worker', $worker)['data'] ?? null;
                abort_unless($person, 404, 'Trabajador no encontrado.');
                $this->check($input['from'] < $person['hired_on'] || $input['to'] > ($person['ended_on'] ?? '9999-12-31'),
                    'from', 'Las vacaciones deben estar dentro del vínculo laboral.');
                $days = (int) CarbonImmutable::parse($input['from'])->diffInDays($input['to']) + 1;
                $this->check($days + $this->vacationDays($worker) > $person['vacation_granted'],
                    'to', 'El descanso supera los días de derecho reconocidos al trabajador.');
                $this->check(collect($this->ledger->records('vacation', $worker.'-'))->contains(
                    fn (array $record) => $record['data']['worker'] === $worker
                        && $record['data']['from'] <= $input['to'] && $record['data']['to'] >= $input['from']),
                    'from', 'El descanso se superpone a un registro anterior.');
                return ['worker' => $worker, 'days' => $days] + $input;
            });
    }

    public function benefit(Request $request, string $month, string $worker, string $benefit): JsonResponse
    {
        $data = $request->validate([
            'expected_revision' => self::REVISION,
            'base' => self::MONEY, 'months' => ['required', 'integer', 'between:0,6'],
            'days' => ['required', 'integer', 'between:0,180'],
            'previous_gratification' => ['nullable', ...array_slice(self::MONEY, 1)],
            'health' => ['required', 'in:ESSALUD,EPS'],
            'posting_date' => ['required', 'date_format:Y-m-d'],
            'withholding' => self::MONEY,
            'reference' => ['required', 'string', 'max:500'],
        ]);
        $this->check(!str_starts_with($data['posting_date'], $month.'-'), 'posting_date', 'La fecha contable debe estar en el periodo del beneficio.');
        $this->check(!in_array(substr($month, -2), $benefit === 'CTS' ? ['05', '11'] : ['07', '12'], true),
            'period', 'Seleccione el mes ordinario de pago del beneficio.');
        $this->check(($benefit === 'CTS' && ($data['days'] > 29 || ((int) $data['months'] === 6 && $data['days'] > 0)))
            || ($benefit === 'GRATIFICATION' && $data['days'] > $data['months'] * 30), 'days', 'Revise el tiempo computable del semestre.');
        return $this->write($request, 'benefit', "$month-$worker-$benefit", $data, function (array $input) use ($month, $worker, $benefit): array {
            abort_unless(isset($this->eligible($month)[$worker]), 404, 'Trabajador sin vigencia para el periodo solicitado.');
            $result = $this->calculation->benefit($benefit, $input);
            $total = bcadd($result['amount'], $result['bonus'], 2);
            $tax = bcadd($input['withholding'], '0', 2);
            $this->check(($benefit === 'CTS' && $tax !== '0.00') || bccomp($tax, $total, 2) > 0,
                'withholding', 'Revise la retención del beneficio.');
            $net = bcsub($total, $tax, 2);
            $lines = [];
            foreach ([
                [$benefit === 'CTS' ? '6291' : '6214', $result['amount'], true],
                ['622', $result['bonus'], true], ['40173', $tax, false],
                [$benefit === 'CTS' ? '4151' : '4114', $net, false],
            ] as [$account, $amount, $debit]) {
                if ($amount !== '0.00') {
                    $lines[] = ['account' => $account, 'debit' => $debit ? $amount : '0.00', 'credit' => $debit ? '0.00' : $amount];
                }
            }
            $label = $benefit === 'CTS' ? 'CTS' : 'Gratificación';
            return ['worker' => $worker, 'kind' => $benefit, 'result' => $result + ['net' => $net],
                'journal' => $lines === [] ? null : ['date' => $input['posting_date'], 'type' => 'ADJUSTMENT',
                    'description' => "$label $month · $worker", 'lines' => $lines]] + $input;
        });
    }

    public function download(string $month, string $format, PayrollExports $export): Response
    {
        $payroll = $this->ledger->latest('payroll', $month)['data'] ?? null;
        abort_unless($payroll && $payroll['approved'], 409, 'Apruebe la planilla antes de exportar.');
        $ruc = (string) DB::table('companies')->where('id', $this->ledger->company())->value('tax_id');
        abort_unless(preg_match('/^\d{11}$/D', $ruc), 422, 'Configure el RUC de la empresa.');
        $period = str_replace('-', '', $month);
        $name = $format === 'jor' ? "0601$period$ruc.jor" : "AFPNET-$ruc-$period.txt";
        $content = $format === 'jor' ? $export->plame($payroll['rows']) : $export->afp($payroll['rows'], $month);
        return response($content, 200, ['Cache-Control' => 'no-store', 'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }

    private function snapshot(string $month, array $input, array $parameters): array
    {
        $eligible = $this->eligible($month);
        $this->check($eligible === [], 'workers', 'Registre trabajadores vigentes para el periodo.');
        $attendance = $this->ledger->records('attendance', $month.'-');
        $versions = $this->dayVersions($this->ledger->records('day', $month.'-'));
        $rows = [];
        $totals = array_fill_keys(['gross','regular_expense','vacation_pay','net','pension','essalud','income_tax','other_deductions','employer_cost','onp','afp'], '0.00');
        foreach ($eligible as $person) {
            $code = $person['data']['code'];
            $daily = $attendance["$month-$code"] ?? null;
            $issue = $this->attendanceIssue($person, $daily, $versions[$code] ?? []);
            if ($issue !== null) {
                throw ValidationException::withMessages(['attendance' => $code.' · '.$issue]);
            }
            $amounts = $this->calculation->worker($person['data'], $daily['data'], $input['health_minimum'], $parameters);
            $rows[] = ['worker' => $person['data'], 'worker_revision' => $person['revision'], 'attendance_revision' => $daily['revision'],
                'attendance' => $daily['data'], 'amounts' => $amounts];
            if (isset($amounts['provision_total'])) {
                $totals['provision_total'] ??= '0.00';
            }
            foreach ($totals as $key => $value) {
                $totals[$key] = bcadd($value, $amounts[$key] ?? '0', 2);
            }
            $pensionKey = strtolower($person['data']['pension']);
            $totals[$pensionKey] = bcadd($totals[$pensionKey], $amounts['pension'], 2);
        }
        [, $date] = $this->bounds($month);
        $lines = [];
        foreach ([
            ['621', 'regular_expense', true], ['6215', 'vacation_pay', true], ['6271', 'essalud', true], ['4111', 'net', false],
            ['4031','essalud',false], ['4032','onp',false], ['417','afp',false],
            ['40173','income_tax',false], ['46','other_deductions',false],
        ] as [$account, $key, $debit]) {
            if ($totals[$key] !== '0.00') {
                $lines[] = ['account' => $account, 'debit' => $debit ? $totals[$key] : '0.00', 'credit' => $debit ? '0.00' : $totals[$key]];
            }
        }
        return $input + ['rows' => $rows, 'totals' => $totals,
            'journal' => $lines === [] ? null : ['date' => $date, 'type' => 'ADJUSTMENT', 'description' => 'Devengo de planilla '.$month, 'lines' => $lines]];
    }

    private function bounds(string $month): array
    {
        $first = CarbonImmutable::parse($month.'-01', 'America/Lima');
        return [$first->toDateString(), $first->endOfMonth()->toDateString()];
    }

    private function eligible(string $month): array
    {
        [$first, $last] = $this->bounds($month);
        return array_filter($this->ledger->workforce($month), fn (array $record) => $record['data']['hired_on'] <= $last
            && ($record['data']['ended_on'] ?? '9999-12-31') >= $first);
    }

    private function attendanceIssue(array $person, ?array $attendance, array $dayVersions): ?string
    {
        return match (true) {
            $attendance === null => 'Falta confirmar la asistencia.',
            ($attendance['data']['worker_revision'] ?? null) !== $person['revision'] =>
                'Cambió la ficha del trabajador. Vuelva a confirmar su asistencia.',
            ($attendance['data']['daily_versions'] ?? []) !== $dayVersions =>
                'Cambió la asistencia diaria. Actualice el cierre de asistencia.',
            default => null,
        };
    }

    private function dayVersions(array $daily): array
    {
        $versions = [];
        foreach ($daily as $record) {
            $versions[$record['data']['worker']][] = $record['reference'].':'.$record['revision'];
        }

        return $versions;
    }

    private function vacationDays(string $worker): int
    {
        return collect($this->ledger->records('vacation', $worker.'-'))->filter(
            fn (array $record) => $record['data']['worker'] === $worker)->sum('data.days');
    }

    private function write(Request $request, string $kind, string $key, array $data, callable $prepare): JsonResponse
    {
        $revision = (int) $data['expected_revision'];
        unset($data['expected_revision']);
        $result = DB::transaction(function () use ($request, $kind, $key, $revision, $data, $prepare): array {
            $this->ledger->lock();
            return $this->ledger->save($kind, $key, $revision, $request->user()->id, $prepare($data));
        });
        return $this->json($result);
    }

    private function check(bool $invalid, string $field, string $message): void
    {
        if ($invalid) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    private function json(array $data): JsonResponse
    {
        return response()->json(['data' => $data], 200, ['Cache-Control' => 'no-store']);
    }
}
