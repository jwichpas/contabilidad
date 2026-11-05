<?php

declare(strict_types=1);

// Standalone, database-free verification of the production PHP calculator.
require_once __DIR__.'/../app/Services/Accounting/CashFlowCalculator.php';

use App\Services\Accounting\CashFlowCalculator;

$calculator = new CashFlowCalculator;
$passed = 0;
$check = static function (string $name, callable $test) use (&$passed): void {
    $test();
    $passed++;
    echo '[PASS] '.$name.PHP_EOL;
};
$equal = static function (mixed $actual, mixed $expected): void {
    if ($actual !== $expected) {
        throw new RuntimeException('Expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
};
$line = static function (int $entry, string $amount, array $replace = []): object {
    return (object) array_replace([
        'entry_id' => $entry, 'line_id' => $entry * 10, 'entry_date' => '2026-02-01',
        'entry_number' => 'TEST-'.$entry, 'entry_type' => 'STANDARD', 'original_entry_type' => null, 'status' => 'POSTED',
        'account_id' => 1, 'account_code' => '104101', 'account_name' => 'Banco prueba', 'account_type' => 'ASSET',
        'rubric' => 'ESF_ACT_CORR_EFE', 'cash_flow_category' => 'CASH_EQUIVALENT',
        'debit_local' => str_starts_with($amount, '-') ? '0.0000' : $amount,
        'credit_local' => str_starts_with($amount, '-') ? substr($amount, 1) : '0.0000',
        'debit_foreign' => '0.0000', 'credit_foreign' => '0.0000',
        'area_id' => 10, 'area_code' => 'OPE-01', 'area_name' => 'Cobros de clientes',
        'activity_category' => 'OPERATING', 'flow_direction' => 'BOTH', 'description' => 'Movimiento de prueba',
    ], $replace);
};
$counterpart = static fn (int $entry, string $amount, array $replace = []): object => $line($entry, $amount, array_replace([
    'line_id' => $entry * 10 + 1, 'account_id' => 2, 'account_code' => '121201', 'account_name' => 'Clientes',
    'rubric' => 'ESF_ACT_CORR_CXC_COM', 'cash_flow_category' => 'NONE', 'area_id' => null,
], $replace));
$report = static fn (array $rows, string $from = '2026-01-01', string $to = '2026-12-31', array $fx = [], string $section = 'ALL', ?int $area = null, int $page = 1): array => $calculator->calculate($rows, $from, $to, $fx, $section, $area, $page);

$check('empty ledger is exact and has no invented cash movements', function () use ($report, $equal): void {
    $r = $report([]);
    $equal($r['totals']['closing_local'], '0.0000');
    $equal($r['details']['total'], 0);
    $equal($r['groups'], []);
});
$check('opening + receipts - asset purchase + loan reconciles to ledger', function () use ($report, $line, $counterpart, $equal): void {
    $r = $report([
        $line(1, '1000.0000', ['entry_date' => '2025-12-31']), $counterpart(1, '-1000.0000', ['entry_date' => '2025-12-31']),
        $line(2, '300.0000'), $counterpart(2, '-300.0000'),
        $line(3, '-500.0000', ['area_id' => 20, 'activity_category' => 'INVESTING']), $counterpart(3, '500.0000', ['account_code' => '333101']),
        $line(4, '700.0000', ['area_id' => 30, 'activity_category' => 'FINANCING']), $counterpart(4, '-700.0000', ['account_code' => '451101']),
    ]);
    $equal($r['totals']['opening_local'], '1000.0000');
    $equal($r['totals']['operating_local'], '300.0000');
    $equal($r['totals']['investing_local'], '-500.0000');
    $equal($r['totals']['financing_local'], '700.0000');
    $equal($r['totals']['closing_local'], '1500.0000');
    $equal($r['totals']['difference_local'], '0.0000');
    $equal($r['is_reconciled'], true);
    $equal($r['details']['total'], 3);
});
$check('only cash-side areas count, counterpart areas never double a payment', function () use ($report, $line, $counterpart, $equal): void {
    $r = $report([$line(1, '50.0000'), $counterpart(1, '-50.0000', ['area_id' => 99, 'activity_category' => 'FINANCING'])]);
    $equal($r['totals']['operating_local'], '50.0000');
    $equal($r['totals']['financing_local'], '0.0000');
    $equal($r['details']['total'], 1);
});
$check('internal bank transfers are excluded even if both lines have operating areas', function () use ($report, $line, $equal): void {
    $r = $report([$line(1, '80.0000'), $line(1, '-80.0000', ['line_id' => 11, 'account_id' => 3, 'account_code' => '104102'])]);
    $equal($r['counts']['TRANSFER'], 2);
    $equal($r['totals']['net_flow_local'], '0.0000');
    $equal($r['groups'], []);
    $equal($r['is_reconciled'], true);
});
$check('cash transfer plus bank commission requires review without arbitrary allocation', function () use ($report, $line, $counterpart, $equal): void {
    $r = $report([$line(1, '98.0000'), $line(1, '-100.0000', ['line_id' => 11, 'account_id' => 3]), $counterpart(1, '2.0000', ['account_code' => '639101'])]);
    $equal($r['counts']['UNCLASSIFIED'], 2);
    $equal($r['totals']['difference_local'], '-2.0000');
    $equal($r['is_reconciled'], false);
});
$check('original reversed receipts and extornos cancel without inflating gross payments', function () use ($report, $line, $counterpart, $equal): void {
    $r = $report([
        $line(1, '60.0000', ['status' => 'REVERSED', 'flow_direction' => 'INFLOW']), $counterpart(1, '-60.0000'),
        $line(2, '-60.0000', ['entry_type' => 'REVERSAL', 'original_entry_type' => 'STANDARD', 'flow_direction' => 'INFLOW']), $counterpart(2, '60.0000'),
    ]);
    $equal($r['totals']['operating_local'], '0.0000');
    $equal($r['groups'][0]['inflow_local'], '0.0000');
    $equal($r['groups'][0]['outflow_local'], '0.0000');
    $equal($r['is_reconciled'], true);
});
$check('a later-period reversal reduces receipts in its own accounting date', function () use ($report, $line, $counterpart, $equal): void {
    $r = $report([
        $line(1, '60.0000', ['entry_date' => '2026-01-31', 'status' => 'REVERSED']), $counterpart(1, '-60.0000', ['entry_date' => '2026-01-31']),
        $line(2, '-60.0000', ['entry_type' => 'REVERSAL', 'original_entry_type' => 'STANDARD']), $counterpart(2, '60.0000'),
    ], '2026-02-01');
    $equal($r['totals']['opening_local'], '60.0000');
    $equal($r['groups'][0]['inflow_local'], '-60.0000');
    $equal($r['totals']['closing_local'], '0.0000');
});
$check('missing areas remain outside activities and explain the difference', function () use ($report, $line, $counterpart, $equal): void {
    $r = $report([$line(1, '25.0000', ['area_id' => null]), $counterpart(1, '-25.0000')]);
    $equal($r['totals']['unclassified_local'], '25.0000');
    $equal($r['totals']['difference_local'], '25.0000');
    $equal($r['totals']['net_flow_local'], '0.0000');
    $equal($r['details']['rows'][0]['reason'], 'MISSING_AREA');
});
$check('offsetting unclassified lines do not falsely certify a reconciled report', function () use ($report, $line, $counterpart, $equal): void {
    $r = $report([$line(1, '25.0000', ['area_id' => null]), $counterpart(1, '-25.0000'), $line(2, '-25.0000', ['area_id' => null]), $counterpart(2, '25.0000')]);
    $equal($r['totals']['difference_local'], '0.0000');
    $equal($r['counts']['UNCLASSIFIED'], 2);
    $equal($r['is_reconciled'], false);
});
$check('an opening on the first date belongs to initial cash, not financing receipts', function () use ($report, $line, $counterpart, $equal): void {
    $r = $report([$line(1, '900.0000', ['entry_type' => 'OPENING', 'entry_date' => '2026-01-01']), $counterpart(1, '-900.0000', ['entry_date' => '2026-01-01'])]);
    $equal($r['totals']['opening_entries_local'], '900.0000');
    $equal($r['totals']['opening_local'], '900.0000');
    $equal($r['totals']['change_local'], '0.0000');
    $equal($r['totals']['net_flow_local'], '0.0000');
});
$check('late openings and closing cash movements are separate review adjustments', function () use ($report, $line, $counterpart, $equal): void {
    $r = $report([$line(1, '900.0000', ['entry_type' => 'OPENING']), $counterpart(1, '-900.0000'), $line(2, '-900.0000', ['entry_type' => 'CLOSING']), $counterpart(2, '900.0000')]);
    $equal($r['counts']['OTHER_ADJUSTMENT'], 2);
    $equal($r['classification_complete'], false);
});
$check('configured FX-only local adjustment is separated from cash flows', function () use ($report, $line, $counterpart, $equal): void {
    $r = $report([$line(1, '4.0000', ['entry_type' => 'ADJUSTMENT', 'area_id' => null]), $counterpart(1, '-4.0000', ['account_id' => 77, 'account_code' => '776101'])], fx: [77]);
    $equal($r['totals']['exchange_effect_local'], '4.0000');
    $equal($r['totals']['net_flow_local'], '0.0000');
    $equal($r['is_reconciled'], true);
});
$check('FX with foreign cash movement is not assumed to be a revaluation', function () use ($report, $line, $counterpart, $equal): void {
    $r = $report([$line(1, '4.0000', ['entry_type' => 'ADJUSTMENT', 'area_id' => null, 'debit_foreign' => '1.0000']), $counterpart(1, '-4.0000', ['account_id' => 77, 'account_code' => '776101'])], fx: [77]);
    $equal($r['totals']['exchange_effect_local'], '0.0000');
    $equal($r['counts']['UNCLASSIFIED'], 1);
});
$check('cash identity uses the financial rubric, not an operating category restriction', function () use ($report, $line, $counterpart, $equal): void {
    $r = $report([$line(1, '4.0000', ['cash_flow_category' => 'OPERATING']), $counterpart(1, '-4.0000')]);
    $equal($r['totals']['operating_local'], '4.0000');
});
$check('group-10 accounts without rubric are flagged rather than silently omitted', function () use ($report, $line, $counterpart, $equal): void {
    $r = $report([$line(1, '4.0000', ['rubric' => 'NONE', 'cash_flow_category' => 'NONE']), $counterpart(1, '-4.0000')]);
    $equal(count($r['unconfigured_accounts']), 1);
    $equal($r['classification_complete'], false);
});
$check('wrong area direction is reported without guessing a cash flow', function () use ($report, $line, $counterpart, $equal): void {
    $r = $report([$line(1, '-4.0000', ['flow_direction' => 'INFLOW']), $counterpart(1, '4.0000')]);
    $equal($r['details']['rows'][0]['reason'], 'AREA_DIRECTION');
});
$check('filtering and paging change only details, never statement totals', function () use ($report, $line, $counterpart, $equal): void {
    $rows = [];
    for ($i = 1; $i <= 61; $i++) {
        $rows[] = $line($i, '0.0001');
        $rows[] = $counterpart($i, '-0.0001');
    }
    $r = $report($rows, section: 'OPERATING', area: 10, page: 2);
    $equal($r['totals']['operating_local'], '0.0061');
    $equal($r['details']['total'], 61);
    $equal(count($r['details']['rows']), 11);
    $equal($r['details']['rows'][0]['entry_id'], 51);
    $empty = $report($rows, section: 'FINANCING');
    $equal($empty['details']['total'], 0);
    $equal($empty['totals']['operating_local'], '0.0061');
});
$check('large decimal amounts keep every fractional digit', function () use ($report, $line, $counterpart, $equal): void {
    $r = $report([$line(1, '9007199254740993.0001'), $counterpart(1, '-9007199254740993.0001'), $line(2, '0.0001'), $counterpart(2, '-0.0001')]);
    $equal($r['totals']['closing_local'], '9007199254740993.0002');
});
$check('rows beyond the closing date never leak into balances or details', function () use ($report, $line, $counterpart, $equal): void {
    $r = $report([$line(1, '10.0000', ['entry_date' => '2027-01-01']), $counterpart(1, '-10.0000', ['entry_date' => '2027-01-01'])]);
    $equal($r['totals']['closing_local'], '0.0000');
    $equal($r['details']['total'], 0);
});
echo $passed.' cash-flow scenarios passed.'.PHP_EOL;
