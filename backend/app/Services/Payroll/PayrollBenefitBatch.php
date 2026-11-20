<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use Carbon\CarbonImmutable;

final readonly class PayrollBenefitBatch
{
    public function __construct(
        private PayrollLedger $ledger,
        private PayrollParameters $parameters,
        private PayrollCalculation $calculation,
    ) {}

    public function preview(string $month, string $kind, string $postingDate): array
    {
        $period = CarbonImmutable::parse($month.'-01', 'America/Lima');
        $cts = $kind === 'CTS';
        $semesterEnd = $period->subMonth()->endOfMonth();
        $baseMonth = $semesterEnd->format('Y-m');
        if (! $cts && $period->month === 12) {
            $semesterEnd = $period->endOfMonth();
        }
        $semesterStart = $semesterEnd->startOfMonth()->subMonths(5);
        $priorGratification = $period->subMonths($period->month === 5 ? 5 : 4);
        $previous = $cts ? $this->ledger->records('benefit', $priorGratification->format('Y-m').'-') : [];
        $existing = $this->ledger->records('benefit', $month.'-');
        $baseWorkforce = $this->ledger->workforce($baseMonth);
        $minimum = $this->parameters->forMonth($baseMonth)['family_allowance'];
        $history = [];
        $incidents = [];
        for ($date = $semesterStart; $date <= $semesterEnd; $date = $date->addMonth()) {
            $key = $date->format('Y-m');
            $payroll = $this->ledger->latest('payroll', $key)['data'] ?? null;
            foreach ($payroll['rows'] ?? [] as $row) {
                $history[$row['worker']['code']][$key] = ['row' => $row, 'approved' => $payroll['approved']];
            }
            foreach ($this->ledger->records('day', $key.'-') as $entry) {
                $day = $entry['data'];
                if (in_array($day['status'], ['ABSENT', 'UNPAID'], true)) {
                    $incidents[$day['worker']][$key][] = $day['date'];
                }
            }
        }

        $rows = [];
        foreach ($this->ledger->workforce($month) as $person) {
            $person = $person['data'];
            if ($person['hired_on'] > $period->endOfMonth()->toDateString()
                || ($person['ended_on'] ?? '9999-12-31') < $period->toDateString()) {
                continue;
            }
            $code = $person['code'];
            $summary = array_intersect_key($person, array_flip(['code', 'given_names', 'surname', 'health_coverage']));
            $record = $existing["$month-$code-$kind"] ?? null;
            if ($record) {
                $rows[] = ['worker' => $summary, 'status' => 'saved', 'record' => $record];
                continue;
            }

            $issues = [];
            $cutoff = $baseWorkforce[$code]['data'] ?? null;
            if (! $cutoff) {
                $issues[] = "Falta una ficha vigente en $baseMonth.";
            }
            if ($person['hired_on'] > $postingDate || ($person['ended_on'] ?? '9999-12-31') < $postingDate) {
                $issues[] = 'El vínculo no está vigente a la fecha contable. Revisa si corresponde una liquidación por cese.';
            }
            $base = $cutoff['salary'] ?? null;
            if ($cutoff['family_allowance'] ?? false) {
                if ($minimum === null) {
                    $issues[] = "Falta la RMV de $baseMonth para la asignación familiar.";
                } else {
                    $base = bcadd($base, $minimum, 2);
                }
            }

            $months = 0;
            $days = 0;
            $missing = [];
            $coverage = $person['health_coverage'] ?? null;
            foreach ($this->months($semesterStart, $semesterEnd) as $start) {
                $key = $start->format('Y-m');
                $first = $start->toDateString();
                $last = $start->endOfMonth()->toDateString();
                $from = max($person['hired_on'], $first);
                $to = min($person['ended_on'] ?? $last, $last);
                if ($from > $to) {
                    continue;
                }
                $complete = $from === $first && $to === $last;
                $absences = count(array_filter($incidents[$code][$key] ?? [], fn (string $day) => $day >= $from && $day <= $to));
                $calendarDays = $complete ? 30 : min(30, (int) CarbonImmutable::parse($from)->diffInDays($to) + 1);
                if ($cts) {
                    $days += max(0, $calendarDays - $absences);
                } elseif ($complete) {
                    $months++;
                    $days += min(30, $absences);
                }
                $historic = $history[$code][$key] ?? null;
                if (! ($historic['approved'] ?? false)) {
                    $missing[] = $key;
                }
                $row = $historic['row'] ?? [];
                if (bccomp($row['amounts']['overtime'] ?? '0', '0', 2) > 0
                    || bccomp($row['attendance']['taxable_extra'] ?? '0', '0', 2) > 0) {
                    $issues[] = "Revisa los conceptos variables de $key para la base computable.";
                }
                if (isset($row['attendance']['paid_days']) && $row['attendance']['paid_days'] < max(0, $calendarDays - $absences)) {
                    $issues[] = "Los días pagados de $key no coinciden con el vínculo y las incidencias registradas.";
                }
            }
            if ($cts) {
                $months = intdiv($days, 30);
                $days %= 30;
            }
            if ($months === 0) {
                $issues[] = 'No se identificó un mes computable para el pago ordinario. Revisa el caso.';
            }

            $prior = '0';
            if ($cts) {
                $priorKey = $priorGratification->format('Y-m');
                $prior = $previous["$priorKey-$code-GRATIFICATION"]['data']['result']['amount'] ?? null;
                $couldHaveReceived = $priorGratification->month === 12
                    ? $person['hired_on'] <= $priorGratification->toDateString()
                    : $person['hired_on'] < $priorGratification->toDateString();
                if ($prior === null && $couldHaveReceived) {
                    $issues[] = "Falta la gratificación de $priorKey. Verifica el importe percibido o confirma que no correspondía.";
                } elseif ($prior === null) {
                    $prior = '0';
                }
            } elseif ($coverage === null) {
                $issues[] = 'Registra EsSalud o EPS en la ficha del trabajador.';
            }

            $input = [
                'base' => $base, 'months' => $months, 'days' => $days,
                'previous_gratification' => $prior, 'health' => $coverage ?? 'ESSALUD',
                'posting_date' => $postingDate, 'withholding' => '0',
                'reference' => "Cálculo por lote del semestre ".$semesterStart->format('Y-m').' a '.$semesterEnd->format('Y-m')."; base de $baseMonth.",
            ];
            $rows[] = [
                'worker' => $summary, 'status' => $issues ? 'review' : 'ready', 'issues' => $issues, 'missing_history' => $missing,
                'input' => $input, 'result' => $issues ? null : $this->calculation->benefit($kind, $input),
            ];
        }

        return ['kind' => $kind, 'base_month' => $baseMonth, 'start' => $semesterStart->toDateString(),
            'end' => $semesterEnd->toDateString(), 'rows' => $rows,
            'token' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR))];
    }

    private function months(CarbonImmutable $first, CarbonImmutable $last): iterable
    {
        for ($month = $first; $month <= $last; $month = $month->addMonth()) {
            yield $month;
        }
    }
}
