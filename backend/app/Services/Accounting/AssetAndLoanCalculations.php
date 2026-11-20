<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class AssetAndLoanCalculations
{
    public const TAX_YEAR = 2025;

    public function loan(array $input): array
    {
        $initial = bcsub($input['principal'], $input['transaction_costs'], 2);
        $payments = $input['payments'];
        $total = array_reduce($payments, static fn (string $sum, string $payment): string => bcadd($sum, $payment, 2), '0.00');
        if (bccomp($initial, '0', 2) <= 0 || bccomp($total, $initial, 2) < 0 || bccomp($payments[array_key_last($payments)], '0', 2) <= 0) {
            throw ValidationException::withMessages(['payments' => 'Revise el desembolso neto y las cuotas de la obligación.']);
        }

        $low = '0';
        $high = '1';
        if (bccomp($this->presentValue($payments, $high), $initial, 12) > 0) {
            throw ValidationException::withMessages(['payments' => 'La tasa mensual supera el rango del simulador (100%).']);
        }
        for ($i = 0; $i < 52; $i++) {
            $mid = bcdiv(bcadd($low, $high, 18), '2', 18);
            if (bccomp($this->presentValue($payments, $mid), $initial, 12) > 0) {
                $low = $mid;
            } else {
                $high = $mid;
            }
        }
        $rate = $total === $initial ? '0' : $high;
        $balance = $initial;
        $rows = [];
        foreach ($payments as $index => $payment) {
            $interest = $this->round(bcmul($balance, $rate, 18));
            if ($index === count($payments) - 1) {
                // Carry the accumulated cent rounding to the last period.
                $interest = bcsub($payment, $balance, 2);
            }
            $closing = bcsub(bcadd($balance, $interest, 2), $payment, 2);
            if (bccomp($closing, '0', 2) < 0 || bccomp($interest, '0', 2) < 0) {
                throw ValidationException::withMessages(['payments' => 'El cronograma no admite estas cuotas con redondeo a céntimos.']);
            }
            $rows[] = [
                'month' => $index + 1, 'opening' => $balance, 'payment' => bcadd($payment, '0', 2),
                'effective_interest' => $interest, 'amortization' => bcsub($payment, $interest, 2),
                'closing' => $closing,
            ];
            $balance = $closing;
        }

        $netTaxInterest = $this->positive(bcsub($input['tax_interest_expense'], $input['tax_interest_income'], 2));
        $limit = $input['apply_ebitda_limit']
            ? $this->round(bcmul($input['previous_tax_ebitda'], '0.30', 4)) : null;
        $excess = $limit === null ? '0.00' : $this->positive(bcsub($netTaxInterest, $limit, 2));

        return [
            'initial_carrying_amount' => $initial,
            'effective_monthly_percent' => bcadd(bcmul($rate, '100', 16), '0.000000005', 8),
            'effective_annual_percent' => bcadd(bcmul(bcsub(bcpow(bcadd('1', $rate, 18), '12', 18), '1', 18), '100', 16), '0.000000005', 8),
            'total_cash_payments' => $total,
            'total_effective_interest' => bcsub($total, $initial, 2),
            'tax_year' => self::TAX_YEAR,
            'net_tax_interest' => $netTaxInterest, 'ebitda_limit' => $limit, 'tax_excess' => $excess,
            'rows' => $rows,
        ];
    }

    public function asset(array $input): array
    {
        if (bccomp($input['residual'], $input['book_cost'], 2) > 0
            || bccomp($input['prior_tax_depreciation'], $input['tax_cost'], 2) > 0) {
            throw ValidationException::withMessages(['book_cost' => 'Revise el valor residual y la depreciación fiscal acumulada frente a sus bases.']);
        }

        $from = CarbonImmutable::create(self::TAX_YEAR, 1, 1, 0, 0, 0, 'UTC');
        $until = $from->addYear();
        $available = CarbonImmutable::parse($input['available_from'], 'UTC');
        $lifeEnd = $available->addMonthsNoOverflow((int) $input['useful_months']);
        $lifeDays = (int) $available->diffInDays($lifeEnd);
        $end = isset($input['stop_on']) ? $lifeEnd->min(CarbonImmutable::parse($input['stop_on'], 'UTC')) : $lifeEnd;
        $depreciable = bcsub($input['book_cost'], $input['residual'], 2);
        $accumulated = function (CarbonImmutable $date) use ($available, $end, $depreciable, $lifeDays): string {
            $days = $date->min($end) <= $available ? 0 : (int) $available->diffInDays($date->min($end));
            return $this->round(bcdiv(bcmul($depreciable, (string) $days, 2), (string) $lifeDays, 12));
        };

        $rows = [];
        $openingBookDepreciation = $accumulated($from);
        $previous = $openingBookDepreciation;
        for ($month = 1; $month <= 12; $month++) {
            $book = $accumulated($from->addMonths($month));
            $rows[] = ['month' => $month, 'book_depreciation' => bcsub($book, $previous, 2),
                'accumulated_book' => $book, 'carrying_amount' => bcsub($input['book_cost'], $book, 2)];
            $previous = $book;
        }

        $taxStart = isset($input['tax_use_from']) ? CarbonImmutable::parse($input['tax_use_from'], 'UTC')->startOfMonth() : $until;
        $firstEligible = $taxStart->max($from);
        $lastEligible = (int) $input['last_eligible_month'];
        $months = $firstEligible >= $until ? 0 : max(0, $lastEligible - $firstEligible->month + 1);
        $annualRate = match ($input['tax_category']) {
            'BUILDING' => '5',
            'DATA_EQUIPMENT' => '25',
            'VEHICLE' => '20',
            'OTHER' => '10',
        };
        $yearlyMaximum = $this->round(bcdiv(
            bcmul($input['tax_cost'], bcmul($annualRate, (string) $months, 2), 4), '1200', 12
        ));
        $taxOpening = bcsub($input['tax_cost'], $input['prior_tax_depreciation'], 2);
        $taxAllowance = $this->minimum($yearlyMaximum, $taxOpening);
        if ($input['tax_category'] !== 'BUILDING') {
            $taxAllowance = $this->minimum($taxAllowance, $input['recorded_depreciation']);
        }
        $yearlyBook = bcsub($previous, $openingBookDepreciation, 2);
        $bookClosing = bcsub($input['book_cost'], $previous, 2);
        $taxClosing = bcsub($taxOpening, $taxAllowance, 2);

        return [
            'tax_year' => self::TAX_YEAR, 'tax_rate' => $annualRate, 'tax_months' => $months,
            'book_depreciation' => $yearlyBook, 'book_carrying_amount' => $bookClosing,
            'tax_depreciation_limit' => $yearlyMaximum, 'tax_depreciation' => $taxAllowance,
            'tax_base_closing' => $taxClosing, 'period_adjustment' => bcsub($yearlyBook, $taxAllowance, 2),
            'carrying_vs_tax_base' => bcsub($bookClosing, $taxClosing, 2), 'rows' => $rows,
        ];
    }

    private function presentValue(array $payments, string $rate): string
    {
        $sum = '0';
        $factor = bcadd('1', $rate, 18);
        for ($i = count($payments) - 1; $i >= 0; $i--) {
            $sum = bcdiv(bcadd($payments[$i], $sum, 18), $factor, 18);
        }
        return $sum;
    }

    private function round(string $value): string
    {
        return bcadd($value, '0.005', 2);
    }

    private function positive(string $amount): string
    {
        return bccomp($amount, '0', 2) < 0 ? '0.00' : $amount;
    }

    private function minimum(string $first, string $second): string
    {
        return bcadd(bccomp($first, $second, 2) <= 0 ? $first : $second, '0', 2);
    }
}
