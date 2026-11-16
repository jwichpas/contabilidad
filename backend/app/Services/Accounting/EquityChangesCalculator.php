<?php

declare(strict_types=1);

namespace App\Services\Accounting;

/**
 * NIC 1 / NIC 8 preliminary bridge. The journal proves movements but not
 * their legal nature (capital contributions, dividends, OCI, restatements).
 * Therefore unclassified equity movements stay explicitly unclassified.
 */
final class EquityChangesCalculator
{
    /** @param list<array<string,mixed>> $accounts */
    public function calculate(array $accounts): array
    {
        $components = [];
        $priorResult = '0.0000';
        $result = '0.0000';
        $warnings = [
            'Los movimientos de patrimonio no están tipificados como aportes, dividendos, reservas, ajustes NIC 8 u ORI.',
            'El resultado integral total y sus efectos en ORI requieren comprobación documental.',
            'No se incluyen cifras comparativas ni un estado auditado de variaciones del patrimonio.',
        ];
        foreach ($accounts as $account) {
            $code = (string) $account['code'];
            // Keep 9 and 79 out of income/expense totals (analytic offset).
            $isResult = $code !== '' && ! str_starts_with($code, '9')
                && ! str_starts_with($code, '79')
                && (in_array($account['account_type'], ['REVENUE','EXPENSE','COST'], true)
                    || str_starts_with($code, '88'));
            $opening = bcsub($account['opening_credit'], $account['opening_debit'], 4);
            $period = bcsub($account['period_credit'], $account['period_debit'], 4);
            $openingEntry = bcsub($account['opening_entry_credit'], $account['opening_entry_debit'], 4);
            if ($isResult) {
                $priorResult = bcadd($priorResult, $opening, 4);
                $result = bcadd($result, bcsub($period, $openingEntry, 4), 4);
                if (bccomp($openingEntry, '0', 4) !== 0) {
                    $warnings[] = 'Hay asientos OPENING contra cuentas de resultados: revisar apertura y cierre.';
                }
                continue;
            }
            if ($account['account_type'] !== 'EQUITY' || str_starts_with($code, '88')) {
                continue;
            }
            $key = match (substr($code,0,2)) {
                '50' => 'CAPITAL', '52' => 'ADDITIONAL_CAPITAL',
                '56' => 'UNREALIZED', '57' => 'SURPLUS',
                '58' => 'RESERVES', '59' => 'RETAINED',
                default => 'OTHER_EQUITY',
            };
            if (! isset($components[$key])) {
                $components[$key] = [
                    'key' => $key, 'label' => [
                        'CAPITAL' => 'Capital social',
                        'ADDITIONAL_CAPITAL' => 'Capital adicional',
                        'UNREALIZED' => 'Resultados no realizados (revisar ORI)',
                        'SURPLUS' => 'Excedente de revaluación',
                        'RESERVES' => 'Reservas',
                        'RETAINED' => 'Resultados acumulados',
                        'OTHER_EQUITY' => 'Otro patrimonio sin asignación específica',
                    ][$key],
                    'opening' => '0.0000', 'opening_entries' => '0.0000',
                    'other_movements' => '0.0000', 'closing' => '0.0000',
                    'account_ids' => [],
                ];
            }
            $row = &$components[$key];
            $regularMovement = bcsub($period, $openingEntry, 4);
            $row['opening'] = bcadd($row['opening'], $opening, 4);
            $row['opening_entries'] = bcadd($row['opening_entries'], $openingEntry, 4);
            $row['other_movements'] = bcadd($row['other_movements'], $regularMovement, 4);
            $row['closing'] = bcadd($row['closing'], bcadd($opening, $period, 4), 4);
            $row['account_ids'][] = $account['id'];
            unset($row);
        }
        $components = array_values($components);
        $totals = ['opening'=>'0.0000','opening_entries'=>'0.0000','other_movements'=>'0.0000','closing'=>'0.0000'];
        foreach ($components as $row) {
            foreach ($totals as $key => $value) {
                $totals[$key] = bcadd($value, $row[$key], 4);
            }
        }
        $openingTotal = bcadd($totals['opening'], $priorResult, 4);
        $closing = bcadd(bcadd($totals['closing'], $priorResult, 4), $result, 4);
        $bridge = bcadd(
            bcadd($openingTotal, $totals['opening_entries'], 4),
            bcadd($totals['other_movements'], $result, 4), 4
        );
        return [
            'components' => $components,
            'prior_unclosed_result' => $priorResult,
            'period_result' => $result,
            'opening_equity' => $openingTotal,
            'opening_entries' => $totals['opening_entries'],
            'other_equity_movements' => $totals['other_movements'],
            'closing_equity_including_result' => $closing,
            'bridge_difference' => bcsub($closing, $bridge, 4),
            'is_preliminary' => true,
            'oci_assessed' => false,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }
}
