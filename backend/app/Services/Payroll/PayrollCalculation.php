<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use Illuminate\Validation\ValidationException;

final class PayrollCalculation
{
    public function worker(array $worker, array $attendance, string $healthMinimum, array $parameters = []): array
    {
        $family = '0.00';
        if ($worker['family_allowance'] ?? false) {
            $family = $parameters['family_allowance'] ?? throw ValidationException::withMessages([
                'family_allowance' => 'Falta la RMV aplicable para calcular la asignación familiar de '.$worker['code'].'.',
            ]);
        }
        if ($worker['night_shift'] ?? false) {
            $minimum = $parameters['night_minimum'] ?? throw ValidationException::withMessages([
                'night_shift' => 'Falta la RMV aplicable para validar la jornada nocturna de '.$worker['code'].'.',
            ]);
            if (bccomp($worker['salary'], $minimum, 2) < 0) {
                throw ValidationException::withMessages([
                    'night_shift' => 'Revise la remuneración de '.$worker['code'].'. El mínimo nocturno del periodo es S/ '.$minimum.'.',
                ]);
            }
        }
        $daily = bcdiv($worker['salary'], '30', 10);
        $ordinaryDaily = bcdiv(bcadd($worker['salary'], $family, 2), '30', 10);
        $minute = bcdiv($ordinaryDaily, (string) ($worker['daily_hours'] * 60), 10);
        $weightedOvertime = bcadd(bcmul((string) $attendance['overtime_25'], $this->multiplier(PayrollParameters::OVERTIME_FIRST), 4),
            bcmul((string) $attendance['overtime_35'], $this->multiplier(PayrollParameters::OVERTIME_REST), 4), 4);
        $basic = $this->round(bcmul($daily, (string) $attendance['paid_days'], 8));
        $overtime = $this->round(bcmul($minute, $weightedOvertime, 8));
        $gross = bcadd(bcadd(bcadd($basic, $overtime, 2), $family, 2), $attendance['taxable_extra'], 2);
        $pension = '0.00';
        $pensionParts = [];
        if ($worker['pension'] === 'ONP') {
            $pension = $this->percent($gross, PayrollParameters::ONP);
        } elseif (bccomp($gross, '0', 2) > 0) {
            $rates = $attendance['afp_rates'] ?? null;
            if ($rates === null) {
                throw ValidationException::withMessages(['afp_rates' => 'Registre la prima, comisión y tope SBS del mes para '.$worker['code'].'.']);
            }
            $insured = bccomp($gross, $rates['insurance_ceiling'], 2) > 0 ? $rates['insurance_ceiling'] : $gross;
            $contribution = $this->percent($gross, PayrollParameters::AFP);
            $insurance = $this->percent($insured, $rates['insurance']);
            $commission = $this->percent($gross, ($worker['afp_commission'] ?? null) === 'BALANCE' ? '0' : $rates['commission']);
            $pension = bcadd(bcadd($contribution, $insurance, 2), $commission, 2);
            if ($parameters !== []) {
                $pensionParts = ['afp_contribution' => $contribution, 'afp_insurance' => $insurance, 'afp_commission' => $commission];
            }
        }

        $vacationPay = $this->round(bcmul($ordinaryDaily, (string) $attendance['vacation_paid_days'], 8));
        $earnings = bcadd($gross, $attendance['nontaxable_extra'], 2);
        $net = bcsub(bcsub(bcsub($earnings, $pension, 2), $attendance['income_tax'], 2), $attendance['other_deductions'], 2);
        if (bccomp($net, '0', 2) < 0) {
            throw ValidationException::withMessages(['deductions' => 'Los descuentos superan los ingresos de '.$worker['code'].'.']);
        }
        $healthBasis = bccomp($gross, '0', 2) === 0 ? '0' : (bccomp($gross, $healthMinimum, 2) < 0 ? $healthMinimum : $gross);
        $essalud = $this->percent($healthBasis, PayrollParameters::ESSALUD);

        $provisions = isset($attendance['provisions']) ? $this->provisions($attendance['provisions']) : [];

        return ['basic' => $basic, 'overtime' => $overtime, 'pensionable' => $gross, 'gross' => $earnings,
            'pension' => $pension, 'vacation_pay' => $vacationPay, 'regular_expense' => bcsub($earnings, $vacationPay, 2), 'essalud' => $essalud, 'income_tax' => $attendance['income_tax'],
            'other_deductions' => $attendance['other_deductions'], 'net' => $net,
            'employer_cost' => bcadd($earnings, $essalud, 2)] + ($family !== '0.00' ? ['family_allowance' => $family] : []) + $pensionParts + $provisions;
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
            'bonus' => $this->percent($amount, ($input['health'] ?? 'ESSALUD') === 'EPS' ? PayrollParameters::BONUS_EPS : PayrollParameters::ESSALUD)];
    }

    private function provisions(array $input): array
    {
        $basis = $input['gratification_month'] ? $input['gratification_base'] : '0';
        $bonusRate = $input['coverage'] === 'EPS' ? PayrollParameters::BONUS_EPS : PayrollParameters::ESSALUD;
        $gratification = $this->round(bcdiv($basis, '6', 10));
        $bonus = $this->round(bcdiv(bcmul($basis, $bonusRate, 10), '600', 10));
        $ctsBase = bcadd($input['cts_base'], bcdiv($input['prior_gratification'], '6', 10), 10);
        $cts = $this->round(bcdiv(bcmul($ctsBase, (string) $input['cts_days'], 10), '360', 10));
        $vacation = $this->round(bcdiv(bcmul($input['vacation_base'], (string) $input['vacation_days'], 10), '360', 10));

        return [
            'provision_gratification' => $gratification,
            'provision_gratification_bonus' => $bonus,
            'provision_cts' => $cts,
            'provision_vacation' => $vacation,
            'provision_total' => bcadd(bcadd($gratification, $bonus, 2), bcadd($cts, $vacation, 2), 2),
        ];
    }

    private function multiplier(string $rate): string
    {
        return bcadd('1', bcdiv($rate, '100', 4), 4);
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
