<?php

declare(strict_types=1);

namespace App\Services\Accounting;

/**
 * Conservative Formato 3.18 *presentation* inference from a single voucher.
 *
 * Never changes the accounting entry or its activity/area, only labels an
 * already-classified cash flow when its non-cash counterparty is unambiguous.
 * There is no heuristic matching of free-text descriptions or tax IDs.
 */
final class CashFlowConceptResolver
{
    /**
     * @param list<object> $cashLines
     * @param list<object> $nonCashLines
     */
    public function resolve(
        array $cashLines,
        array $nonCashLines,
        string $activity,
        string $cashAmount,
        bool $isReversal
    ): ?string {
        if (! in_array($activity, ['OPERATING', 'INVESTING', 'FINANCING'], true)
            || count($cashLines) !== 1 || $nonCashLines === []
            || bccomp($cashAmount, '0', 4) === 0) {
            return null;
        }

        // No proportional allocation. Every noncash line must exactly balance
        // the sole cash line, which must be the only cash leg in the voucher.
        $opposite = '0.0000';
        foreach ($nonCashLines as $counterpart) {
            $opposite = bcadd(
                $opposite,
                bcsub((string) $counterpart->debit_local, (string) $counterpart->credit_local, 4),
                4
            );
        }
        if (bccomp(bcadd($cashAmount, $opposite, 4), '0.0000', 4) !== 0) {
            return null;
        }

        // Reversed vouchers retain the original meaning while reversing signs.
        $originalCash = $isReversal ? bcsub('0', $cashAmount, 4) : $cashAmount;
        $inflow = bccomp($originalCash, '0', 4) > 0;
        $counterpartDirection = $inflow ? -1 : 1;
        foreach ($nonCashLines as $counterpart) {
            $net = bcsub((string) $counterpart->debit_local, (string) $counterpart->credit_local, 4);
            $originalNet = $isReversal ? bcsub('0', $net, 4) : $net;
            if (bccomp($originalNet, '0', 4) !== $counterpartDirection) {
                return null; // Mixed voucher: don't guess how the cash was used.
            }
        }

        $codes = array_map(static fn (object $line): string => (string) $line->account_code, $nonCashLines);
        if (count($codes) > 1) {
            // Direct cash sale: 70 credit + optional IGV 40 credit. All other
            // composite or split vouchers need explicit coding or review.
            if ($activity === 'OPERATING' && $inflow
                && count(array_filter($codes, static fn (string $c): bool => str_starts_with($c, '70'))) === 1
                && count(array_filter($codes, static fn (string $c): bool =>
                    ! str_starts_with($c, '70') && ! str_starts_with($c, '4011'))) === 0) {
                return '1102';
            }

            return null;
        }

        $code = $codes[0];
        if ($activity === 'OPERATING' && $inflow
            && (str_starts_with($code, '12') || str_starts_with($code, '13')
                || str_starts_with($code, '70'))) {
            return '1102'; // Receivable collection or direct sale.
        }
        if ($activity === 'OPERATING' && ! $inflow) {
            return match (true) {
                str_starts_with($code, '42') || str_starts_with($code, '43')
                    || str_starts_with($code, '63') => '1201',
                str_starts_with($code, '41') => '1202',
                str_starts_with($code, '40') => '1203',
                str_starts_with($code, '455') || str_starts_with($code, '67') => '1206',
                default => null,
            };
        }
        if ($activity === 'FINANCING' && $inflow) {
            return match (true) {
                str_starts_with($code, '451') || str_starts_with($code, '452')
                    || str_starts_with($code, '453') || str_starts_with($code, '454') => '1703',
                str_starts_with($code, '50') || str_starts_with($code, '52') => '1704',
                default => null,
            };
        }
        if ($activity === 'FINANCING' && ! $inflow) {
            return match (true) {
                str_starts_with($code, '451') || str_starts_with($code, '452')
                    || str_starts_with($code, '453') || str_starts_with($code, '454') => '1802',
                str_starts_with($code, '455') || str_starts_with($code, '67') => '1805',
                default => null,
            };
        }

        return null;
    }
}
