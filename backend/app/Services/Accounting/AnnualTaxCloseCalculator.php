<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use Illuminate\Validation\ValidationException;

final class AnnualTaxCloseCalculator
{
    public const YEAR = 2025;
    public const VERSION = 'PERU-RG-2025-v1';

    public function __construct(private readonly AnnualTaxCloseJournalProposals $journal) {}

    /** Amounts in PEN. The 2025 working paper rounds submitted totals to soles. */
    public function calculate(array $input): array
    {
        $adjustments = [
            'addition_permanent' => '0.00', 'addition_temporary' => '0.00',
            'deduction_permanent' => '0.00', 'deduction_temporary' => '0.00',
        ];
        $adjustmentLines = [];
        foreach ($input['adjustments'] as $i => $line) {
            $amount = bcsub($line['book_base'], $line['tax_base'], 2);
            $addition = $line['direction'] === 'ADDITION';
            if (bccomp($amount, '0', 2) === ($addition ? -1 : 1)) {
                throw ValidationException::withMessages([
                    "inputs.adjustments.$i.tax_base" => 'Las bases no coinciden con el sentido del reparo.',
                ]);
            }
            if (!$addition) {
                $amount = bcsub('0', $amount, 2);
            }
            $key = strtolower($line['direction'].'_'.$line['kind']);
            $adjustments[$key] = bcadd($adjustments[$key], $amount, 2);
            $adjustmentLines[] = $amount;
        }

        $additions = $this->whole(bcadd($adjustments['addition_permanent'], $adjustments['addition_temporary'], 2));
        $deductions = $this->whole(bcadd($adjustments['deduction_permanent'], $adjustments['deduction_temporary'], 2));
        $profit = $this->whole($input['book_profit']);
        $adjusted = bcsub(bcadd($profit, $additions, 2), $deductions, 2);
        $losses = $this->whole($input['prior_losses_used']);
        $this->requireAtMost($input['prior_losses_used'], $this->positive($adjusted), 'prior_losses_used');
        $participationBase = $this->positive(bcsub($adjusted, $losses, 2));
        $participation = $this->whole(bcmul($participationBase, bcdiv($input['participation_rate'], '100', 4), 6));
        $deductibleParticipation = $this->whole($input['participation_deductible']);
        $this->requireAtMost($input['participation_deductible'], $participation, 'participation_deductible');
        $adminParticipation = $input['participation_admin'] ?? '0';
        $salesParticipation = $input['participation_sales'] ?? '0';
        $this->requireAtMost(bcadd($adminParticipation, $salesParticipation, 2), $participation, 'participation_admin');
        $taxable = $this->positive(bcsub($participationBase, $deductibleParticipation, 2));
        $tax = $this->whole(bcmul($taxable, '0.295', 5));

        $itanBase = $this->positive(bcsub(
            bcadd($input['itan_assets'], $input['itan_additions'], 2), $input['itan_deductions'], 2
        ));
        $itanAssessed = $this->whole(bcmul($this->positive(bcsub($itanBase, '1000000', 2)), '0.004', 5));
        $this->requireAtMost($input['itan_paid'], $itanAssessed, 'itan_paid');
        $paid = '0.00';
        $itanAgainstAdvances = '0.00';
        foreach ($input['advances'] as $i => $row) {
            $settled = bcadd($row['cash_paid'], $row['itan_used'], 2);
            $this->requireAtMost($settled, $row['assessed'], "advances.$i.assessed");
            $paid = bcadd($paid, $row['cash_paid'], 2);
            $itanAgainstAdvances = bcadd($itanAgainstAdvances, $row['itan_used'], 2);
        }

        $this->requireAtMost(
            bcadd($itanAgainstAdvances, $input['itan_at_close'], 2), $input['itan_paid'], 'itan_at_close'
        );
        $advances = $this->whole(bcadd($paid, $itanAgainstAdvances, 2));
        $afterAdvances = bcsub($tax, $advances, 2);
        $this->requireAtMost($input['itan_at_close'], $this->positive($afterAdvances), 'itan_at_close');
        $settlement = bcsub($afterAdvances, $input['itan_at_close'], 2);
        $this->requireAtMost($input['ciprl_applied'], $input['ciprl_available'], 'ciprl_applied');
        $this->requireAtMost($input['ciprl_applied'], $this->positive($settlement), 'ciprl_applied');

        $deferred = ['asset_opening' => '0.00', 'asset_closing' => '0.00',
            'liability_opening' => '0.00', 'liability_closing' => '0.00'];
        foreach ($input['deferred'] as $line) {
            $kind = strtolower($line['kind']);
            if ($kind === 'liability' || $line['recognize_closing']) {
                $deferred[$kind.'_closing'] = bcadd($deferred[$kind.'_closing'],
                    $this->whole(bcmul($line['closing_difference'], bcdiv($line['rate'], '100', 4), 6)), 2);
            }
            $deferred[$kind.'_opening'] = bcadd($deferred[$kind.'_opening'], $line['opening_tax'], 2);
        }

        $deferredExpense = bcsub(
            bcsub($deferred['liability_closing'], $deferred['liability_opening'], 2),
            bcsub($deferred['asset_closing'], $deferred['asset_opening'], 2), 2
        );
        $taxExpense = bcadd($tax, $deferredExpense, 2);

        $result = $adjustments + $deferred + [
            'adjustment_amounts' => $adjustmentLines,
            'casilla_103' => $additions, 'casilla_105' => $deductions,
            'adjusted_income' => $adjusted, 'participation_base' => $participationBase,
            'participation' => $participation, 'taxable_income' => $taxable, 'current_tax' => $tax,
            'itan_base' => $itanBase, 'itan_assessed' => $itanAssessed,
            'advances_cash' => $paid, 'itan_in_advances' => $itanAgainstAdvances, 'advances_total' => $advances,
            'settlement_before_ciprl' => $this->positive($settlement),
            'credit_balance' => $this->positive(bcsub('0', $settlement, 2)),
            'cash_to_pay' => $this->positive(bcsub($settlement, $input['ciprl_applied'], 2)),
            'deferred_expense' => $deferredExpense, 'tax_expense' => $taxExpense,
            'net_profit' => bcsub(bcsub($profit, $participation, 2), $taxExpense, 2),
        ];
        $result['journal_proposals'] = $this->journal->build($result, $adminParticipation, $salesParticipation);

        return $result;
    }

    private function whole(string $value): string
    {
        $half = bccomp($value, '0', 6) < 0 ? '-0.5' : '0.5';

        return bcadd(bcadd($value, $half, 0), '0', 2);
    }

    private function positive(string $value): string
    {
        return bccomp($value, '0', 2) < 0 ? '0.00' : $value;
    }

    private function requireAtMost(string $amount, string $limit, string $field): void
    {
        if (bccomp($amount, $limit, 2) > 0) {
            throw ValidationException::withMessages(["inputs.$field" => "El importe excede el límite disponible de S/ $limit."]);
        }
    }
}
