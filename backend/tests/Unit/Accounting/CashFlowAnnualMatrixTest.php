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


it('derives client collections and supplier payments from PCGE without changing OPE areas', function (): void {
    $z = '0.0000';
    $rows = [
        // Demo J01: opening cash must not become revenue.
        flowRow(1, 1, '2026-01-01', 'OPENING', '104100', 'ASSET', '40000.0000', $z, 'OPE-IN', 'OPERATING', 'INFLOW'),
        flowRow(1, 2, '2026-01-01', 'OPENING', '501100', 'EQUITY', $z, '40000.0000'),
        // Demo J02: bank loan draw.
        flowRow(2, 3, '2026-01-05', 'STANDARD', '104100', 'ASSET', '24000.0000', $z, 'FIN-IN', 'FINANCING', 'INFLOW'),
        flowRow(2, 4, '2026-01-05', 'STANDARD', '451100', 'LIABILITY', $z, '24000.0000'),
        // Demo J07: customer trade receivable collected. This is NOT 16000 accrued sales.
        flowRow(3, 5, '2026-01-20', 'STANDARD', '104100', 'ASSET', '10000.0000', $z, 'OPE-IN', 'OPERATING', 'INFLOW'),
        flowRow(3, 6, '2026-01-20', 'STANDARD', '121200', 'ASSET', $z, '10000.0000'),
        // Demo J08: settlement of trade payables.
        flowRow(4, 7, '2026-01-22', 'STANDARD', '421200', 'LIABILITY', '6000.0000', $z),
        flowRow(4, 8, '2026-01-22', 'STANDARD', '104100', 'ASSET', $z, '6000.0000', 'OPE-OUT', 'OPERATING', 'OUTFLOW'),
        // Demo J09: repayment of bank principal.
        flowRow(5, 9, '2026-01-25', 'STANDARD', '451100', 'LIABILITY', '4000.0000', $z),
        flowRow(5, 10, '2026-01-25', 'STANDARD', '104100', 'ASSET', $z, '4000.0000', 'FIN-OUT', 'FINANCING', 'OUTFLOW'),
        // Demo J11: payment of interest classified as operating by company policy.
        flowRow(6, 11, '2026-01-25', 'STANDARD', '455110', 'LIABILITY', '240.0000', $z),
        flowRow(6, 12, '2026-01-25', 'STANDARD', '104100', 'ASSET', $z, '240.0000', 'OPE-OUT', 'OPERATING', 'OUTFLOW'),
        // Demo J12: expense paid on account 63 (rented services).
        flowRow(7, 13, '2026-01-27', 'STANDARD', '635200', 'EXPENSE', '1200.0000', $z),
        flowRow(7, 14, '2026-01-27', 'STANDARD', '104100', 'ASSET', $z, '1200.0000', 'OPE-OUT', 'OPERATING', 'OUTFLOW'),
    ];
    $report = (new CashFlowCalculator())->calculate($rows, '2026-01-01', '2026-12-31', [], 'ALL', null, 1, true);
    $groups = $report['monthly'][0]['groups'];
    $get = static function (string $concept) use ($groups): array {
        $matches = array_values(array_filter($groups, static fn (array $g): bool =>
            ($g['concept_code'] ?? '') === $concept));
        expect($matches)->not->toBeEmpty();
        return [
            'inflow_local' => array_reduce($matches, static fn (string $sum, array $g): string =>
                bcadd($sum, $g['inflow_local'], 4), '0.0000'),
            'outflow_local' => array_reduce($matches, static fn (string $sum, array $g): string =>
                bcadd($sum, $g['outflow_local'], 4), '0.0000'),
        ];
    };
    expect($get('1102')['inflow_local'])->toBe('10000.0000');
    expect($get('1201')['outflow_local'])->toBe('7200.0000'); // proveedores 6000 + alquiler 1200
    expect($get('1703')['inflow_local'])->toBe('24000.0000');
    expect($get('1802')['outflow_local'])->toBe('4000.0000');
    expect($get('1206')['outflow_local'])->toBe('240.0000');
    expect($report['totals']['operating_local'])->toBe('2560.0000');
    expect($report['totals']['financing_local'])->toBe('20000.0000');
    expect($report['totals']['opening_local'])->toBe('40000.0000');
    expect($report['totals']['closing_local'])->toBe('62560.0000');
    expect($report['is_reconciled'])->toBeTrue();
});


it('applies scoped account and area presentation rules without altering cash totals', function (): void {
    $z = '0.0000';
    $rows = [
        flowRow(1, 1, '2026-01-04', 'STANDARD', '104100', 'ASSET', '100.0000', $z, 'OPE-IN', 'OPERATING', 'INFLOW'),
        flowRow(1, 2, '2026-01-04', 'STANDARD', '121200', 'ASSET', $z, '100.0000'),
        flowRow(2, 3, '2026-01-06', 'STANDARD', '104100', 'ASSET', '50.0000', $z, 'OPE-OTHER', 'OPERATING', 'INFLOW'),
        flowRow(2, 4, '2026-01-06', 'STANDARD', '121200', 'ASSET', $z, '50.0000'),
    ];
    $defaults = (new CashFlowCalculator())->calculate(
        $rows, '2026-01-01', '2026-12-31', [], 'ALL', null, 1, true
    );
    $company = (new CashFlowCalculator())->calculate(
        $rows, '2026-01-01', '2026-12-31', [], 'ALL', null, 1, true, [
            'area_rules' => [[
                'area_code' => 'OPE-IN', 'section' => 'OPERATING',
                'direction' => 'INFLOW', 'target_code' => 'C0001',
            ]],
            'account_rules' => [[
                'account_code' => '121200', 'section' => 'OPERATING',
                'direction' => 'INFLOW', 'target_code' => '1107',
            ]],
        ]
    );
    $get = static function (array $statement, string $target): array {
        $candidates = array_values(array_filter($statement['monthly'][0]['groups'],
            static fn (array $group): bool => ($group['concept_code'] ?? null) === $target));
        expect($candidates)->toHaveCount(1);
        return $candidates[0];
    };
    expect($get($company, 'C0001')['inflow_local'])->toBe('100.0000');
    expect($get($company, '1107')['inflow_local'])->toBe('50.0000');
    expect($get($defaults, '1102')['inflow_local'])->toBe('100.0000');
    expect($defaults['totals'])->toBe($company['totals']);
    expect($company['is_reconciled'])->toBeTrue();
});
