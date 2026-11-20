<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final readonly class AnnualTaxCloseTrialBalance
{
    private const COLUMNS = [
        'opening_debit', 'opening_credit', 'movement_debit', 'movement_credit',
        'transfer_debit', 'transfer_credit',
    ];

    public function __construct(private PeriodLedgerService $ledger) {}

    public function prepare(int $year, ?string $openingSource): array
    {
        $source = $this->ledger->read("$year-01-01", "$year-12-31", splitClosing: true);
        if ($source['currency'] !== 'PEN') {
            throw new ConflictHttpException('El papel de trabajo FV 710 requiere moneda funcional PEN.');
        }
        $catalog = json_decode(file_get_contents(database_path('reference_data/sunat_fv710_trial_balance_2025.json')),
            true, 512, JSON_THROW_ON_ERROR);
        $codes = array_fill_keys($catalog['codes'], true);
        $historical = $explicit = false;
        foreach ($source['accounts'] as $account) {
            $historical = $historical || bccomp($account['opening_debit'], $account['opening_credit'], 4) !== 0;
            $explicit = $explicit || bccomp($account['annual_opening_debit'], $account['annual_opening_credit'], 4) !== 0;
        }
        if (($openingSource === 'entry' && !$explicit) || ($openingSource === 'historical' && !$historical && $explicit)) {
            throw new ConflictHttpException('El origen elegido no contiene saldos de apertura.');
        }
        $ambiguous = $historical && $explicit && $openingSource === null;
        $openingSource ??= $ambiguous ? null : ($explicit ? 'entry' : 'historical');
        $empty = array_fill_keys(self::COLUMNS, '0.0000');
        $totals = $mapped = $empty;
        $rows = $unmapped = [];
        foreach ($source['accounts'] as $account) {
            $opening = match ($openingSource) {
                'entry' => bcsub($account['annual_opening_debit'], $account['annual_opening_credit'], 4),
                'historical' => bcsub($account['opening_debit'], $account['opening_credit'], 4),
                default => '0.0000',
            };
            $amounts = [
                'opening_debit' => TrialBalanceCalculator::positive($opening),
                'opening_credit' => TrialBalanceCalculator::negative($opening),
                'movement_debit' => bcsub(bcsub($account['period_debit'], $account['annual_opening_debit'], 4), $account['transfer_debit'], 4),
                'movement_credit' => bcsub(bcsub($account['period_credit'], $account['annual_opening_credit'], 4), $account['transfer_credit'], 4),
                'transfer_debit' => $account['transfer_debit'],
                'transfer_credit' => $account['transfer_credit'],
            ];
            if ($amounts === $empty) {
                continue;
            }
            $code = $account['code'];
            while ($code !== '' && !isset($codes[$code])) {
                $code = substr($code, 0, -1);
            }
            foreach ($amounts as $key => $value) {
                $totals[$key] = bcadd($totals[$key], $value, 4);
            }
            if ($code === '') {
                $unmapped[] = ['code' => $account['code'], 'name' => $account['name']] + $amounts;
                continue;
            }
            $rows[$code] ??= ['code' => $code, 'accounts' => []] + $empty;
            $rows[$code]['accounts'][] = $account['code'];
            foreach ($amounts as $key => $value) {
                $rows[$code][$key] = bcadd($rows[$code][$key], $value, 4);
                $mapped[$key] = bcadd($mapped[$key], $value, 4);
            }
        }
        ksort($rows, SORT_STRING);

        return [
            'year' => $year, 'currency' => $source['currency'], 'opening_source' => $openingSource,
            'opening_ambiguous' => $ambiguous, 'historical_opening_available' => $historical,
            'entry_opening_available' => $explicit, 'rows' => array_values($rows), 'unmapped' => $unmapped,
            'totals' => $totals, 'mapped_totals' => $mapped,
            'balanced' => !$ambiguous && $totals['opening_debit'] === $totals['opening_credit']
                && $totals['movement_debit'] === $totals['movement_credit']
                && $totals['transfer_debit'] === $totals['transfer_credit'],
        ];
    }
}
