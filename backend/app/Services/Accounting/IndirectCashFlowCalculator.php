<?php

declare(strict_types=1);

namespace App\Services\Accounting;

/**
 * NIC 7 indirect operating cash flow. Working-capital changes exclude
 * explicitly typed OPENING entries, which are already in opening cash.
 * No balancing plug is invented: a difference against the direct method
 * makes the report non-reconciled.
 */
final class IndirectCashFlowCalculator
{
    /** @param list<array<string,mixed>> $accounts @param array<string,mixed> $direct */
    public function calculate(array $accounts, array $direct): array
    {
        $values = array_fill_keys(['net_result','noncash_depreciation','receivables','inventory',
            'trade_payables','tax_liabilities','other_working_capital'], '0.0000');
        $sources = [];
        $warnings = [
            'Método indirecto preliminar: clasificaciones basadas en cuentas PCGE y movimientos registrados.',
            'Las variaciones del capital de trabajo excluyen asientos tipificados OPENING.',
            'Verificar movimientos no monetarios, reclasificaciones y política de intereses/dividendos NIC 7.',
        ];
        $depreciationCounterpart = '0.0000';
        foreach ($accounts as $row) {
            $code = (string) $row['code'];
            $period = bcsub($row['period_debit'], $row['period_credit'], 4);
            $openingEntry = bcsub($row['opening_entry_debit'], $row['opening_entry_credit'], 4);
            $realChange = bcsub($period, $openingEntry, 4);
            if (bccomp($realChange, '0', 4) === 0) {
                continue;
            }
            if (str_starts_with($code, '39')) {
                $depreciationCounterpart = bcadd($depreciationCounterpart,
                    bcsub('0', $realChange, 4), 4);
                continue;
            }
            if (str_starts_with($code, '9') || str_starts_with($code, '79')) {
                continue;
            }
            $isResult = in_array($row['account_type'], ['REVENUE', 'EXPENSE', 'COST'], true)
                || str_starts_with($code, '88');
            if ($isResult) {
                $values['net_result'] = bcsub($values['net_result'], $realChange, 4);
                if (str_starts_with($code, '684')) {
                    $values['noncash_depreciation'] = bcadd($values['noncash_depreciation'], $realChange, 4);
                }
                if (str_starts_with($code, '68') && ! str_starts_with($code, '684')) {
                    $warnings[] = 'Hay otros gastos cuenta 68: comprobar si corresponden ajustes no monetarios.';
                }
                if (str_starts_with($code, '77')) {
                    $warnings[] = 'Ingresos financieros presentes: revisar su categoría de flujo conforme a política NIIF.';
                }
                continue;
            }
            $group = match (true) {
                preg_match('/^(12|13|14|16|17|18)/', $code) === 1 => 'receivables',
                preg_match('/^2[0-9]/', $code) === 1 => 'inventory',
                preg_match('/^(41|42|43|44|46|47|48|49)/', $code) === 1 => 'trade_payables',
                str_starts_with($code, '40') => 'tax_liabilities',
                default => null,
            };
            if ($group !== null) {
                // Assets 12/20 are debit-normal: subtract their increase.
                // Liabilities 40/42 are credit-normal: their increase is -debit+credit.
                $values[$group] = bcsub($values[$group], $realChange, 4);
                $sources[$group][] = (int) $row['id'];
            }
        }
        if (bccomp($values['noncash_depreciation'], $depreciationCounterpart, 4) !== 0) {
            $warnings[] = 'Depreciación 684 y contrapartida 39 no coinciden: verificar el ajuste no monetario.';
        }
        $operation = '0.0000';
        foreach ($values as $amount) {
            $operation = bcadd($operation, $amount, 4);
        }
        $d = $direct['totals'];
        $difference = bcsub($operation, $d['operating_local'], 4);
        $directClosing = $d['closing_local'];
        $indirectExpected = bcadd(
            bcadd($d['opening_local'], $operation, 4),
            bcadd($d['investing_local'], bcadd($d['financing_local'],
                bcadd($d['exchange_effect_local'], $d['other_adjustments_local'], 4), 4), 4), 4
        );
        $closingDifference = bcsub($indirectExpected, $directClosing, 4);
        $reconciled = bccomp($difference,'0',4) === 0 && bccomp($closingDifference,'0',4) === 0
            && $direct['is_reconciled'] && $direct['classification_complete'];
        if (! $reconciled) {
            $warnings[] = 'La conciliación con el método directo y/o el efectivo en libros está pendiente. No se añade una partida de ajuste ficticia.';
        }
        return [
            'adjustments' => $values, 'account_sources' => $sources,
            'operating_indirect' => $operation, 'operating_direct' => $d['operating_local'],
            'operating_difference' => $difference,
            'investing_local' => $d['investing_local'], 'financing_local' => $d['financing_local'],
            'exchange_effect_local' => $d['exchange_effect_local'],
            'other_adjustments_local' => $d['other_adjustments_local'],
            'opening_local' => $d['opening_local'], 'closing_book_local' => $directClosing,
            'closing_indirect_local' => $indirectExpected, 'closing_difference' => $closingDifference,
            'reconciled' => $reconciled, 'is_preliminary' => true,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }
}
