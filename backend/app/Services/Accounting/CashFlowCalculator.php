<?php

declare(strict_types=1);

namespace App\Services\Accounting;

/** Pure decimal reducer. Input is ordered by date, entry_id and line_id. */
final class CashFlowCalculator
{
    public const SECTIONS = ['OPERATING', 'INVESTING', 'FINANCING', 'UNCLASSIFIED', 'TRANSFER', 'EXCHANGE_EFFECT', 'OPENING', 'OTHER_ADJUSTMENT'];

    /**
     * Only cash-side areas classify a payment: counterpart balances are not cash flows.
     * No float, proportional allocation, description heuristics or default activity.
     *
     * @param iterable<object> $rows Posted lines, including originals marked REVERSED.
     * @param list<int> $fxAccountIds Configured gain/loss accounts of this company.
     * @return array<string, mixed>
     */
    public function calculate(iterable $rows, string $from, string $to, array $fxAccountIds = [], string $section = 'ALL', ?int $areaId = null, int $page = 1): array
    {
        $totals = array_fill_keys([
            'opening_book_local', 'opening_entries_local', 'opening_local', 'closing_local',
            'operating_local', 'investing_local', 'financing_local', 'net_flow_local',
            'exchange_effect_local', 'other_adjustments_local', 'unclassified_local',
        ], '0.0000');
        $counts = array_fill_keys(self::SECTIONS, 0);
        $groups = [];
        $accounts = [];
        $unconfigured = [];
        $details = [];
        $detailCount = 0;
        $entry = [];
        $previousId = null;

        $consume = function (array $lines) use ($from, $to, $fxAccountIds, $section, $areaId, $page, &$totals, &$counts, &$groups, &$accounts, &$unconfigured, &$details, &$detailCount): void {
            if ($lines === []) {
                return;
            }
            $head = $lines[0];
            $date = (string) $head->entry_date;
            if ($date > $to) {
                return;
            }
            $cash = [];
            $noncash = [];
            $cashNet = '0.0000';
            $hasDebit = false;
            $hasCredit = false;
            $hasForeignMovement = false;
            foreach ($lines as $line) {
                $amount = bcsub((string) $line->debit_local, (string) $line->credit_local, 4);
                $isCash = $line->rubric === 'ESF_ACT_CORR_EFE' || $line->cash_flow_category === 'CASH_EQUIVALENT';
                if (! $isCash && str_starts_with((string) $line->account_code, '10') && $line->rubric === 'NONE') {
                    $unconfigured[(int) $line->account_id] = ['id' => (int) $line->account_id, 'code' => $line->account_code, 'name' => $line->account_name];
                }
                if ($isCash) {
                    $cash[] = $line;
                    $cashNet = bcadd($cashNet, $amount, 4);
                    $hasDebit = $hasDebit || bccomp($amount, '0', 4) > 0;
                    $hasCredit = $hasCredit || bccomp($amount, '0', 4) < 0;
                    $hasForeignMovement = $hasForeignMovement || bccomp((string) $line->debit_foreign, (string) $line->credit_foreign, 4) !== 0;
                    $id = (int) $line->account_id;
                    $accounts[$id] ??= ['id' => $id, 'code' => $line->account_code, 'name' => $line->account_name, 'opening_local' => '0.0000', 'closing_local' => '0.0000'];
                    $accounts[$id]['closing_local'] = bcadd($accounts[$id]['closing_local'], $amount, 4);
                    $totals['closing_local'] = bcadd($totals['closing_local'], $amount, 4);
                    if ($date < $from) {
                        $accounts[$id]['opening_local'] = bcadd($accounts[$id]['opening_local'], $amount, 4);
                        $totals['opening_book_local'] = bcadd($totals['opening_book_local'], $amount, 4);
                    }
                } elseif (bccomp($amount, '0', 4) !== 0
                    && ! str_starts_with((string) $line->account_code, '9')
                    && ! str_starts_with((string) $line->account_code, '79')
                    && $line->account_type !== 'MEMORANDUM') {
                    $noncash[] = $line;
                }
            }
            if ($date < $from || $cash === []) {
                return;
            }

            $isReversal = $head->entry_type === 'REVERSAL';
            $effectiveType = $isReversal ? ($head->original_entry_type ?? 'REVERSAL') : $head->entry_type;
            $isTransfer = count($cash) >= 2 && $noncash === [] && bccomp($cashNet, '0', 4) === 0;
            $isFx = $effectiveType === 'ADJUSTMENT' && ! $hasForeignMovement && $noncash !== [];
            foreach ($noncash as $line) {
                $isFx = $isFx && in_array((int) $line->account_id, $fxAccountIds, true);
            }

            foreach ($cash as $line) {
                $amount = bcsub((string) $line->debit_local, (string) $line->credit_local, 4);
                if (bccomp($amount, '0', 4) === 0) {
                    continue;
                }
                $kind = 'UNCLASSIFIED';
                $reason = null;
                $originalAmount = $isReversal ? bcsub('0', $amount, 4) : $amount;
                if ($effectiveType === 'OPENING' && $date === $from) {
                    $kind = 'OPENING';
                    $totals['opening_entries_local'] = bcadd($totals['opening_entries_local'], $amount, 4);
                    $id = (int) $line->account_id;
                    $accounts[$id]['opening_local'] = bcadd($accounts[$id]['opening_local'], $amount, 4);
                } elseif (in_array($effectiveType, ['OPENING', 'CLOSING'], true)) {
                    $kind = 'OTHER_ADJUSTMENT';
                    $reason = $effectiveType === 'OPENING' ? 'LATE_OPENING' : 'CLOSING_CASH';
                } elseif ($isTransfer) {
                    $kind = 'TRANSFER';
                } elseif ($isFx) {
                    $kind = 'EXCHANGE_EFFECT';
                } elseif ($hasDebit && $hasCredit) {
                    $reason = 'MIXED_CASH_SIDES';
                } elseif (! in_array($effectiveType, ['STANDARD', 'ADJUSTMENT'], true)) {
                    $reason = 'UNKNOWN_REVERSAL';
                } elseif ($line->area_id === null || ! in_array($line->activity_category, ['OPERATING', 'INVESTING', 'FINANCING'], true)) {
                    $reason = 'MISSING_AREA';
                } elseif (($line->flow_direction === 'INFLOW' && bccomp($originalAmount, '0', 4) < 0)
                    || ($line->flow_direction === 'OUTFLOW' && bccomp($originalAmount, '0', 4) > 0)) {
                    $reason = 'AREA_DIRECTION';
                } else {
                    // Historical inactive areas remain readable, just like extornos.
                    $kind = $line->activity_category;
                }
                $counts[$kind]++;
                $totalKey = match ($kind) {
                    'OPERATING' => 'operating_local',
                    'INVESTING' => 'investing_local',
                    'FINANCING' => 'financing_local',
                    'EXCHANGE_EFFECT' => 'exchange_effect_local',
                    'OTHER_ADJUSTMENT' => 'other_adjustments_local',
                    'UNCLASSIFIED' => 'unclassified_local',
                    default => null,
                };
                if ($totalKey !== null) {
                    $totals[$totalKey] = bcadd($totals[$totalKey], $amount, 4);
                }
                if (in_array($kind, ['OPERATING', 'INVESTING', 'FINANCING'], true)) {
                    $key = $kind.'|'.$line->area_id;
                    $groups[$key] ??= [
                        'section' => $kind, 'area_id' => (int) $line->area_id,
                        'code' => $line->area_code, 'name' => $line->area_name,
                        'inflow_local' => '0.0000', 'outflow_local' => '0.0000', 'net_local' => '0.0000', 'count' => 0,
                    ];
                    // A reversed receipt reduces receipts, rather than inflating payments.
                    $side = bccomp($originalAmount, '0', 4) > 0 ? 'inflow_local' : 'outflow_local';
                    $sideAmount = $side === 'inflow_local' ? $amount : bcsub('0', $amount, 4);
                    $groups[$key][$side] = bcadd($groups[$key][$side], $sideAmount, 4);
                    $groups[$key]['net_local'] = bcadd($groups[$key]['net_local'], $amount, 4);
                    $groups[$key]['count']++;
                }
                if (($section === 'ALL' || $section === $kind) && ($areaId === null || (int) $line->area_id === $areaId)) {
                    $detailCount++;
                    if ($detailCount > ($page - 1) * 50 && count($details) < 50) {
                        $details[] = [
                            'id' => (int) $line->line_id, 'entry_id' => (int) $line->entry_id,
                            'entry_date' => $date, 'entry_number' => $line->entry_number,
                            'entry_type' => $line->entry_type, 'status' => $line->status,
                            'description' => $line->description,
                            'account_code' => $line->account_code, 'account_name' => $line->account_name,
                            'area_code' => $line->area_code, 'area_name' => $line->area_name,
                            'section' => $kind, 'reason' => $reason, 'amount_local' => $amount,
                        ];
                    }
                }
            }
        };

        foreach ($rows as $row) {
            if ($previousId !== null && (int) $row->entry_id !== $previousId) {
                $consume($entry);
                $entry = [];
            }
            $entry[] = $row;
            $previousId = (int) $row->entry_id;
        }
        $consume($entry);
        $totals['opening_local'] = bcadd($totals['opening_book_local'], $totals['opening_entries_local'], 4);
        $totals['net_flow_local'] = bcadd(bcadd($totals['operating_local'], $totals['investing_local'], 4), $totals['financing_local'], 4);
        $totals['change_local'] = bcsub($totals['closing_local'], $totals['opening_local'], 4);
        $totals['expected_closing_local'] = bcadd(bcadd(bcadd($totals['opening_local'], $totals['net_flow_local'], 4), $totals['exchange_effect_local'], 4), $totals['other_adjustments_local'], 4);
        // Unclassified amounts stay OUTSIDE this equality. Opposite omissions may
        // net to zero, so counts and coverage also gate classification_complete.
        $totals['difference_local'] = bcsub($totals['closing_local'], $totals['expected_closing_local'], 4);
        $classificationComplete = $counts['UNCLASSIFIED'] === 0 && $counts['OTHER_ADJUSTMENT'] === 0 && $unconfigured === [];
        $groups = array_values($groups);
        usort($groups, static fn (array $a, array $b): int => strcmp($a['section'].'|'.$a['code'], $b['section'].'|'.$b['code']));
        return [
            'from' => $from, 'to' => $to, 'method' => 'DIRECT', 'is_preliminary' => true,
            'totals' => $totals, 'counts' => $counts, 'groups' => $groups,
            'cash_accounts' => array_values($accounts), 'unconfigured_accounts' => array_values($unconfigured),
            'classification_complete' => $classificationComplete,
            'is_reconciled' => $classificationComplete && bccomp($totals['difference_local'], '0', 4) === 0,
            'details' => ['section' => $section, 'area_id' => $areaId, 'rows' => $details, 'page' => $page, 'per_page' => 50, 'total' => $detailCount, 'last_page' => max(1, (int) ceil($detailCount / 50))],
        ];
    }
}
