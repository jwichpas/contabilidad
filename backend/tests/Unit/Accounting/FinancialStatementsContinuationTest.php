<?php

declare(strict_types=1);

use App\Services\Accounting\TrialBalanceCalculator;
use App\Services\Accounting\EquityChangesCalculator;
use App\Services\Accounting\IndirectCashFlowCalculator;

function fixtureFinancialPeriodAccounts(): array
{
    $fixture = json_decode(file_get_contents(__DIR__.'/../../Fixtures/financial_lifecycle_2026.json'), true, 512, JSON_THROW_ON_ERROR);
    $accounts = [];
    foreach ($fixture['accounts'] as $symbol => $definition) {
        $code = explode('_', $symbol, 2)[0].'_'.explode('_', $symbol, 2)[1];
        $number = match ($symbol) {
            '10_EFECTIVO' => '104100', '12_CXC' => '121200',
            '20_MERCADERIAS' => '201110', '33_PPE' => '336110',
            '39_DEP_ACUM' => '395270', '40_IGV' => '401110',
            '40_RENTA' => '401710', '42_CXP' => '421200',
            '45_PRESTAMO' => '451100', '45_INTERES' => '455110',
            '50_CAPITAL' => '501100', '60_COMPRAS' => '601100',
            '61_VARIACION' => '611100', '63_ALQUILER' => '635200',
            '67_INTERESES' => '673110', '68_DEPRECIACION' => '684150',
            '69_COSTO_VENTAS' => '691210', '70_VENTAS' => '701200',
            '88_IMP_RENTA' => '881000',
        };
        $accounts[$symbol] = [
            'id' => count($accounts) + 1, 'code' => $number, 'name' => $definition['label'],
            'account_type' => match ($definition['kind']) {
                'asset' => 'ASSET', 'liability' => 'LIABILITY', 'equity' => 'EQUITY',
                'revenue' => 'REVENUE', default => 'EXPENSE',
            },
            'rubric' => 'NONE',
            'opening_debit' => '0.0000', 'opening_credit' => '0.0000',
            'period_debit' => '0.0000', 'period_credit' => '0.0000',
            'opening_entry_debit' => '0.0000', 'opening_entry_credit' => '0.0000',
        ];
    }
    foreach ($fixture['entries'] as $entry) {
        foreach ($entry['lines'] as $line) {
            $row = &$accounts[$line['account']];
            $row['period_debit'] = bcadd($row['period_debit'], $line['debit'], 4);
            $row['period_credit'] = bcadd($row['period_credit'], $line['credit'], 4);
            if ($entry['type'] === 'OPENING') {
                $row['opening_entry_debit'] = bcadd($row['opening_entry_debit'], $line['debit'], 4);
                $row['opening_entry_credit'] = bcadd($row['opening_entry_credit'], $line['credit'], 4);
            }
            unset($row);
        }
    }
    return array_values($accounts);
}

it('balances the formal trial balance and preserves 14 fixture journal amounts', function (): void {
    $statement = (new TrialBalanceCalculator())->calculate(fixtureFinancialPeriodAccounts());
    expect($statement['balanced'])->toBeTrue();
    expect($statement['totals']['movement_debit'])->toBe('161430.7000');
    expect($statement['totals']['movement_credit'])->toBe('161430.7000');
    expect($statement['differences']['closing'])->toBe('0.0000');
    expect($statement['rows'])->toHaveCount(19);
});

it('separates opening capital and current profit in the preliminary equity bridge', function (): void {
    $statement = (new EquityChangesCalculator())->calculate(fixtureFinancialPeriodAccounts());
    expect($statement['opening_equity'])->toBe('0.0000');
    expect($statement['opening_entries'])->toBe('60000.0000');
    expect($statement['period_result'])->toBe('3849.3000');
    expect($statement['closing_equity_including_result'])->toBe('63849.3000');
    expect($statement['bridge_difference'])->toBe('0.0000');
    expect($statement['oci_assessed'])->toBeFalse();
});

it('reconciles indirect cash flow with direct cash flow without balancing plugs', function (): void {
    $fixture = json_decode(file_get_contents(__DIR__.'/../../Fixtures/financial_lifecycle_2026.json'), true, 512, JSON_THROW_ON_ERROR);
    $direct = [
        'totals' => [
            'opening_local' => $fixture['expected']['cash_opening'],
            'closing_local' => $fixture['expected']['cash_closing'],
            'operating_local' => $fixture['expected']['cash_operating'],
            'investing_local' => $fixture['expected']['cash_investing'],
            'financing_local' => $fixture['expected']['cash_financing'],
            'exchange_effect_local' => '0.0000', 'other_adjustments_local' => '0.0000',
        ],
        'is_reconciled' => true, 'classification_complete' => true,
    ];
    $statement = (new IndirectCashFlowCalculator())->calculate(fixtureFinancialPeriodAccounts(), $direct);
    expect($statement['adjustments']['net_result'])->toBe('3849.3000');
    expect($statement['adjustments']['noncash_depreciation'])->toBe('300.0000');
    expect($statement['adjustments']['receivables'])->toBe('-8880.0000');
    expect($statement['adjustments']['inventory'])->toBe('-3200.0000');
    expect($statement['adjustments']['trade_payables'])->toBe('8160.0000');
    expect($statement['adjustments']['tax_liabilities'])->toBe('2330.7000');
    expect($statement['operating_indirect'])->toBe('2560.0000');
    expect($statement['closing_indirect_local'])->toBe('62560.0000');
    expect($statement['operating_difference'])->toBe('0.0000');
    expect($statement['reconciled'])->toBeTrue();

    $direct['totals']['operating_local'] = '2561.0000';
    $notReconciled = (new IndirectCashFlowCalculator())->calculate(fixtureFinancialPeriodAccounts(), $direct);
    expect($notReconciled['reconciled'])->toBeFalse();
    expect($notReconciled['operating_difference'])->toBe('-1.0000');
    expect($notReconciled['warnings'])->not()->toBeEmpty();
});
