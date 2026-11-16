<?php

declare(strict_types=1);

use App\Services\Accounting\CashFlowCalculator;

function flowRow(
    int $entryId, int $lineId, string $date, string $type,
    string $code, string $accountType, string $debit, string $credit,
    ?string $areaCode = null, ?string $activity = null, ?string $direction = null
): object {
    $cash = str_starts_with($code, '10');
    return (object) [
        'line_id' => $lineId, 'entry_id' => $entryId, 'entry_date' => $date,
        'entry_number' => sprintf('01-%05d', $entryId), 'entry_type' => $type,
        'original_entry_type' => null, 'status' => 'POSTED',
        'account_id' => $cash ? 1 : $lineId,
        'account_code' => $code, 'account_name' => $code, 'account_type' => $accountType,
        'rubric' => $cash ? 'ESF_ACT_CORR_EFE' : 'NONE',
        'cash_flow_category' => $cash ? 'CASH_EQUIVALENT' : 'NONE',
        'debit_local' => $debit, 'credit_local' => $credit,
        'debit_foreign' => $debit, 'credit_foreign' => $credit,
        'area_id' => $areaCode === null ? null : $lineId,
        'area_code' => $areaCode, 'area_name' => $areaCode,
        'activity_category' => $activity, 'flow_direction' => $direction,
        'description' => 'Prueba sintética',
    ];
}

it('builds 12 exact linked months with January opening and no false future flows', function (): void {
    $z = '0.0000';
    $rows = [
        flowRow(1, 1, '2026-01-01', 'OPENING', '104100', 'ASSET', '400.0000', $z, 'OPE-IN', 'OPERATING', 'INFLOW'),
        flowRow(1, 2, '2026-01-01', 'OPENING', '501100', 'EQUITY', $z, '400.0000'),
        flowRow(2, 3, '2026-01-05', 'STANDARD', '104100', 'ASSET', '350.1250', $z, 'F318-1102', 'OPERATING', 'INFLOW'),
        flowRow(2, 4, '2026-01-05', 'STANDARD', '121200', 'ASSET', $z, '350.1250'),
        flowRow(3, 5, '2026-02-07', 'STANDARD', '421200', 'LIABILITY', '100.0000', $z),
        flowRow(3, 6, '2026-02-07', 'STANDARD', '104100', 'ASSET', $z, '100.0000', 'OPE-OUT', 'OPERATING', 'OUTFLOW'),
        flowRow(4, 7, '2026-02-09', 'STANDARD', '104100', 'ASSET', '200.0000', $z, 'FIN-IN', 'FINANCING', 'INFLOW'),
        flowRow(4, 8, '2026-02-09', 'STANDARD', '451100', 'LIABILITY', $z, '200.0000'),
    ];
    $annual = (new CashFlowCalculator())->calculate($rows, '2026-01-01', '2026-12-31', [], 'ALL', null, 1, true);
    $m = $annual['monthly'];
    expect($m)->toHaveCount(12);
    expect($annual['totals']['opening_local'])->toBe('400.0000');
    expect($annual['totals']['operating_local'])->toBe('250.1250');
    expect($annual['totals']['financing_local'])->toBe('200.0000');
    expect($annual['totals']['closing_local'])->toBe('850.1250');
    expect($m[0]['opening_local'])->toBe('400.0000');
    expect($m[0]['closing_local'])->toBe('750.1250');
    expect($m[1]['opening_local'])->toBe('750.1250');
    expect($m[1]['closing_local'])->toBe('850.1250');
    expect($m[2]['opening_local'])->toBe('850.1250');
    expect($m[11]['closing_local'])->toBe('850.1250');
    expect($m[1]['financing_local'])->toBe('200.0000');
    expect($m[1]['operating_local'])->toBe('-100.0000');
    expect($m[0]['groups'][0]['inflow_local'])->toBe('350.1250');
    expect($annual['is_reconciled'])->toBeTrue();
    expect($m[0]['is_reconciled'])->toBeTrue();
    expect($m[1]['is_reconciled'])->toBeTrue();
});

it('does not hide unknown cash flow areas even when their amounts offset', function (): void {
    $z = '0.0000';
    $rows = [
        flowRow(1, 1, '2026-01-03', 'STANDARD', '104100', 'ASSET', '80.0000', $z),
        flowRow(1, 2, '2026-01-03', 'STANDARD', '121200', 'ASSET', $z, '80.0000'),
        flowRow(2, 3, '2026-01-04', 'STANDARD', '421200', 'LIABILITY', '80.0000', $z),
        flowRow(2, 4, '2026-01-04', 'STANDARD', '104100', 'ASSET', $z, '80.0000'),
    ];
    $annual = (new CashFlowCalculator())->calculate($rows, '2026-01-01', '2026-12-31', [], 'ALL', null, 1, true);
    expect($annual['totals']['difference_local'])->toBe('0.0000');
    expect($annual['classification_complete'])->toBeFalse();
    expect($annual['is_reconciled'])->toBeFalse();
    expect($annual['monthly'][0]['unclassified_count'])->toBe(2);
    expect($annual['monthly'][0]['is_reconciled'])->toBeFalse();
});
