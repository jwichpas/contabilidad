<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use Illuminate\Validation\ValidationException;

final class PayrollCalculation
{
    public function worker(array $worker, array $attendance, string $healthMinimum): array
    {
        $daily = bcdiv($worker['salary'], '30', 10);
        $minute = bcdiv($daily, (string) ($worker['daily_hours'] * 60), 10);
        $weightedOvertime = bcadd(bcmul((string) $attendance['overtime_25'], '1.25', 4),
            bcmul((string) $attendance['overtime_35'], '1.35', 4), 4);
        $basic = $this->round(bcmul($daily, (string) $attendance['paid_days'], 8));
        $overtime = $this->round(bcmul($minute, $weightedOvertime, 8));
        $gross = bcadd(bcadd($basic, $overtime, 2), $attendance['taxable_extra'], 2);
        $pension = '0.00';
        if ($worker['pension'] === 'ONP') {
            $pension = $this->percent($gross, '13');
        } elseif (bccomp($gross, '0', 2) > 0) {
            $rates = $attendance['afp_rates'] ?? null;
            if ($rates === null) {
                throw ValidationException::withMessages(['afp_rates' => 'Registre la prima, comisión y tope SBS del mes para '.$worker['code'].'.']);
            }
            $insured = bccomp($gross, $rates['insurance_ceiling'], 2) > 0 ? $rates['insurance_ceiling'] : $gross;
            $pension = bcadd(bcadd($this->percent($gross, '10'), $this->percent($insured, $rates['insurance']), 2),
                $this->percent($gross, $rates['commission']), 2);
        }

        $vacationPay = $this->round(bcmul($daily, (string) $attendance['vacation_paid_days'], 8));
        $earnings = bcadd($gross, $attendance['nontaxable_extra'], 2);
        $net = bcsub(bcsub(bcsub($earnings, $pension, 2), $attendance['income_tax'], 2), $attendance['other_deductions'], 2);
        if (bccomp($net, '0', 2) < 0) {
            throw ValidationException::withMessages(['deductions' => 'Los descuentos superan los ingresos de '.$worker['code'].'.']);
        }
        $healthBasis = bccomp($gross, '0', 2) === 0 ? '0' : (bccomp($gross, $healthMinimum, 2) < 0 ? $healthMinimum : $gross);
        $essalud = $this->percent($healthBasis, '9');

        return ['basic' => $basic, 'overtime' => $overtime, 'pensionable' => $gross, 'gross' => $earnings,
            'pension' => $pension, 'vacation_pay' => $vacationPay, 'regular_expense' => bcsub($earnings, $vacationPay, 2), 'essalud' => $essalud, 'income_tax' => $attendance['income_tax'],
            'other_deductions' => $attendance['other_deductions'], 'net' => $net,
            'employer_cost' => bcadd($earnings, $essalud, 2)];
    }

    public function benefit(string $kind, array $input): array
    {
        $basis = $input['base'];
        if ($kind === 'CTS') {
            $basis = bcadd($basis, bcdiv($input['previous_gratification'] ?? '0', '6', 10), 10);
            $days = $input['months'] * 30 + ($input['days'] ?? 0);
            $amount = $this->round(bcdiv(bcmul($basis, (string) $days, 8), '360', 8));
            return ['basis' => $this->round($basis), 'amount' => $amount, 'bonus' => '0.00'];
        }

        $days = $input['months'] * 30 - ($input['days'] ?? 0);
        $amount = $this->round(bcdiv(bcmul($basis, (string) $days, 8), '180', 8));
        return ['basis' => $this->round($basis), 'amount' => $amount,
            'bonus' => $this->percent($amount, ($input['health'] ?? 'ESSALUD') === 'EPS' ? '6.75' : '9')];
    }

    private function percent(string $base, string $rate): string
    {
        return $this->round(bcdiv(bcmul($base, $rate, 10), '100', 8));
    }

    private function round(string $value): string
    {
        return bcadd($value, '0.005', 2);
    }
}
