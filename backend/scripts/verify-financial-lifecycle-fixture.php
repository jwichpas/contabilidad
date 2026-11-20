<?php

declare(strict_types=1);

/**
 * Verificacion determinista, SIN BASE DE DATOS, de una fixture contable NIIF.
 * Solo comprueba la integridad del ejemplo y las conciliaciones esperadas.
 * No certifica que el backend HTTP ni los reportes del ERP las reproduzcan.
 *
 * Uso: php backend/scripts/verify-financial-lifecycle-fixture.php
 */

$path = dirname(__DIR__).'/tests/Fixtures/financial_lifecycle_2026.json';
if (! is_file($path)) {
    // Facilita ejecutarlo por separado antes de publicar en el repositorio.
    $path = __DIR__.'/financial_lifecycle_2026.json';
}
if (! is_file($path)) {
    fwrite(STDERR, "[FAIL] Fixture no encontrada.\n");
    exit(1);
}

try {
    $f = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($f) || ! isset($f['entries'], $f['accounts'], $f['expected'])) {
        throw new RuntimeException('Estructura incompleta.');
    }

    /** Convierte importes de 4 decimales a enteros con escala 10000, sin float. */
    function unit(string $amount): int
    {
        if (! preg_match('/^(-?)(\d+)\.(\d{4})$/', $amount, $m)) {
            throw new RuntimeException('Decimal invalido de cuatro lugares: '.$amount);
        }
        $n = ((int) $m[2]) * 10000 + (int) $m[3];
        return $m[1] === '-' ? -$n : $n;
    }

    function formatted(int $value): string
    {
        return ($value < 0 ? '-' : '').intdiv(abs($value), 10000).'.'.str_pad((string) (abs($value) % 10000), 4, '0', STR_PAD_LEFT);
    }

    $seen = [];
    $balance = array_fill_keys(array_keys($f['accounts']), 0);
    $flows = ['OPENING' => 0, 'OPERATING' => 0, 'INVESTING' => 0, 'FINANCING' => 0];
    $debits = 0;
    $credits = 0;
    $lineCount = 0;
    $inventoryUnits = 0;
    $purchaseUnits = 0;
    $inventoryStartUnits = 0;
    $inventoryCostStart = 0;
    $inventoryPurchaseCost = 0;

    foreach ($f['entries'] as $e) {
        $id = (string) $e['id'];
        if (isset($seen[$id])) {
            throw new RuntimeException("Asiento duplicado: $id");
        }
        $seen[$id] = true;
        if (! isset($e['date']) || $e['date'] < $f['period']['from'] || $e['date'] > $f['period']['to']) {
            throw new RuntimeException("Asiento $id fuera del periodo.");
        }
        $entryDebits = $entryCredits = 0;
        foreach ($e['lines'] as $l) {
            $lineCount++;
            $a = (string) $l['account'];
            if (! array_key_exists($a, $balance)) {
                throw new RuntimeException("Cuenta inexistente: $a");
            }
            $dr = unit((string) $l['debit']);
            $cr = unit((string) $l['credit']);
            if ($dr < 0 || $cr < 0 || ($dr === 0 && $cr === 0) || ($dr !== 0 && $cr !== 0)) {
                throw new RuntimeException("Partida invalida en $id / $a.");
            }
            $entryDebits += $dr;
            $entryCredits += $cr;
            $balance[$a] += $dr - $cr;
            if ($a === '10_EFECTIVO') {
                $type = $l['cash_flow'] ?? null;
                if (! is_string($type) || ! array_key_exists($type, $flows)) {
                    throw new RuntimeException("Movimiento de efectivo sin flujo valido en $id.");
                }
                $flows[$type] += $dr - $cr;
                if ($type === 'OPENING' && $e['type'] !== 'OPENING') {
                    throw new RuntimeException("Clasificacion de apertura invalida en $id.");
                }
            } elseif (isset($l['cash_flow'])) {
                throw new RuntimeException("Flujo asignado a cuenta no monetaria en $id.");
            }
            if ($a === '20_MERCADERIAS') {
                if (! isset($l['qty_units']) || ! is_int($l['qty_units'])) {
                    throw new RuntimeException("Inventario sin unidades en $id.");
                }
                $inventoryUnits += $l['qty_units'];
                if ($e['type'] === 'OPENING') {
                    $inventoryStartUnits += $l['qty_units'];
                    $inventoryCostStart += $dr - $cr;
                } elseif ($l['qty_units'] > 0) {
                    $purchaseUnits += $l['qty_units'];
                    $inventoryPurchaseCost += $dr - $cr;
                }
            }
        }
        if ($entryDebits !== $entryCredits) {
            throw new RuntimeException("Partida doble no cuadra en $id: ".formatted($entryDebits).' != '.formatted($entryCredits));
        }
        $debits += $entryDebits;
        $credits += $entryCredits;
    }

    $sum = static fn (array $names): int => array_sum(array_map(static fn (string $a): int => $balance[$a], $names));
    $assets = $sum(['10_EFECTIVO', '12_CXC', '20_MERCADERIAS', '33_PPE', '39_DEP_ACUM']);
    $liabilities = -$sum(['40_IGV', '40_RENTA', '42_CXP', '45_PRESTAMO', '45_INTERES']);
    $capital = -$balance['50_CAPITAL'];
    $revenue = -$balance['70_VENTAS'];
    $cogs = $balance['69_COSTO_VENTAS'];
    $grossProfit = $revenue - $cogs;
    $operatingExpenses = $sum(['63_ALQUILER', '68_DEPRECIACION']);
    $operatingProfit = $grossProfit - $operatingExpenses - $sum(['60_COMPRAS','61_VARIACION']);
    $financeCost = $balance['67_INTERESES'];
    $pretaxProfit = $operatingProfit - $financeCost;
    $taxExpense = $balance['88_IMP_RENTA'];
    $profit = $pretaxProfit - $taxExpense;
    $equity = $capital + $profit;
    $cashEnd = $flows['OPENING'] + $flows['OPERATING'] + $flows['INVESTING'] + $flows['FINANCING'];
    $weightedAverage = intdiv($inventoryCostStart + $inventoryPurchaseCost, $inventoryStartUnits + $purchaseUnits);
    $actual = [
        'trial_debit' => $debits,
        'trial_credit' => $credits,
        'cash_opening' => $flows['OPENING'],
        'cash_operating' => $flows['OPERATING'],
        'cash_investing' => $flows['INVESTING'],
        'cash_financing' => $flows['FINANCING'],
        'cash_closing' => $cashEnd,
        'receivables' => $balance['12_CXC'],
        'inventory' => $balance['20_MERCADERIAS'],
        'gross_ppe' => $balance['33_PPE'],
        'accumulated_depreciation' => -$balance['39_DEP_ACUM'],
        'net_ppe' => $sum(['33_PPE', '39_DEP_ACUM']),
        'assets' => $assets,
        'bank_debt' => -$balance['45_PRESTAMO'],
        'suppliers' => -$balance['42_CXP'],
        'vat_payable' => -$balance['40_IGV'],
        'income_tax_payable' => -$balance['40_RENTA'],
        'liabilities' => $liabilities,
        'capital' => $capital,
        'revenue' => $revenue,
        'cost_of_sales' => $cogs,
        'gross_profit' => $grossProfit,
        'operating_expenses' => $operatingExpenses,
        'operating_profit' => $operatingProfit,
        'finance_cost' => $financeCost,
        'pretax_profit' => $pretaxProfit,
        'income_tax_expense' => $taxExpense,
        'net_profit' => $profit,
        'other_comprehensive_income' => 0,
        'equity_closing' => $equity,
        'equity_movement' => $profit,
        'statement_difference' => $assets - $liabilities - $equity,
        'inventory_units_end' => $inventoryUnits,
        'inventory_unit_cost' => $weightedAverage,
        'journal_count' => count($f['entries']),
        'line_count' => $lineCount,
    ];
    if ($debits !== $credits || $cashEnd !== $balance['10_EFECTIVO']) {
        throw new RuntimeException('Descuadre del balance de comprobacion o del efectivo en libros.');
    }
    if ($sum(['60_COMPRAS', '61_VARIACION']) !== 0) {
        throw new RuntimeException('Compra y destino a existencias no se neutralizan.');
    }
    $qtyOut = $inventoryStartUnits + $purchaseUnits - $inventoryUnits;
    if ($balance['69_COSTO_VENTAS'] !== $weightedAverage * $qtyOut) {
        throw new RuntimeException('Costo de ventas no coincide con promedio ponderado.');
    }
    foreach ($f['expected'] as $key => $expected) {
        if (! array_key_exists($key, $actual)) {
            throw new RuntimeException('Indicador esperado desconocido: '.$key);
        }
        $target = is_int($expected) ? $expected : unit((string) $expected);
        if ($actual[$key] !== $target) {
            $reported = is_int($expected) ? (string) $actual[$key] : formatted($actual[$key]);
            throw new RuntimeException("$key: esperado $expected, calculado $reported");
        }
    }
    echo "[PASS] ".count($f['entries'])." asientos, $lineCount partidas y ".count($actual)." verificaciones.\n";
    echo '[PASS] Activo '.formatted($assets).' = Pasivo '.formatted($liabilities).' + Patrimonio '.formatted($equity)."\n";
    echo '[PASS] Resultado neto '.formatted($profit).', costo de ventas '.formatted($cogs)."\n";
    echo '[PASS] Flujo: inicial '.formatted($flows['OPENING']).' + operacion '.formatted($flows['OPERATING']).' + inversion '.formatted($flows['INVESTING']).' + financiamiento '.formatted($flows['FINANCING']).' = final '.formatted($cashEnd)."\n";
    echo "[AVISO] No se han registrado asientos en el ERP ni probado sus endpoints.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] '.$exception->getMessage()."\n");
    exit(1);
}
