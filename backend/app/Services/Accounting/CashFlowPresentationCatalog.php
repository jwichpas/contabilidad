<?php

declare(strict_types=1);

namespace App\Services\Accounting;

/**
 * Read-only standard presentation layout. Company-specific overlays live in
 * immutable revisions and cannot redefine a standard code's activity or sign.
 * Same display definitions as frontend annualCashFlowMatrix.ts (test guarded).
 */
final class CashFlowPresentationCatalog
{
    public const LINES = [
        '1100' => ['label' => 'ACTIVIDADES DE OPERACIÓN', 'kind' => 'section', 'section' => null, 'direction' => null],
        '1101' => ['label' => 'Cobros (entradas) por', 'kind' => 'subheading', 'section' => null, 'direction' => null],
        '1102' => ['label' => 'Venta de bienes y prestación de servicios', 'kind' => 'detail', 'section' => 'OPERATING', 'direction' => 'INFLOW'],
        '1103' => ['label' => 'Honorarios y comisiones', 'kind' => 'detail', 'section' => 'OPERATING', 'direction' => 'INFLOW'],
        '1104' => ['label' => 'Intereses y rendimientos cobrados', 'kind' => 'detail', 'section' => 'OPERATING', 'direction' => 'INFLOW'],
        '1105' => ['label' => 'Dividendos y distribuciones cobrados', 'kind' => 'detail', 'section' => 'OPERATING', 'direction' => 'INFLOW'],
        '1107' => ['label' => 'Otros cobros identificados de operación', 'kind' => 'detail', 'section' => 'OPERATING', 'direction' => 'INFLOW'],
        '1198' => ['label' => 'Cobros operativos pendientes de desagregación', 'kind' => 'detail', 'section' => 'OPERATING', 'direction' => 'INFLOW'],
        '1199' => ['label' => 'TOTAL COBROS DE OPERACIÓN', 'kind' => 'subtotal', 'section' => 'OPERATING', 'direction' => 'INFLOW'],
        '1200' => ['label' => 'Menos pagos (salidas) por', 'kind' => 'subheading', 'section' => null, 'direction' => null],
        '1201' => ['label' => 'Proveedores de bienes y servicios', 'kind' => 'detail', 'section' => 'OPERATING', 'direction' => 'OUTFLOW'],
        '1202' => ['label' => 'Remuneraciones y beneficios sociales', 'kind' => 'detail', 'section' => 'OPERATING', 'direction' => 'OUTFLOW'],
        '1203' => ['label' => 'Tributos pagados', 'kind' => 'detail', 'section' => 'OPERATING', 'direction' => 'OUTFLOW'],
        '1205' => ['label' => 'Regalías', 'kind' => 'detail', 'section' => 'OPERATING', 'direction' => 'OUTFLOW'],
        '1206' => ['label' => 'Otros pagos identificados de operación', 'kind' => 'detail', 'section' => 'OPERATING', 'direction' => 'OUTFLOW'],
        '1298' => ['label' => 'Pagos operativos pendientes de desagregación', 'kind' => 'detail', 'section' => 'OPERATING', 'direction' => 'OUTFLOW'],
        '1299' => ['label' => 'TOTAL PAGOS DE OPERACIÓN', 'kind' => 'subtotal', 'section' => 'OPERATING', 'direction' => 'OUTFLOW'],
        '1301' => ['label' => 'FLUJO NETO DE OPERACIÓN', 'kind' => 'summary', 'section' => null, 'direction' => null],
        '1400' => ['label' => 'ACTIVIDADES DE INVERSIÓN', 'kind' => 'section', 'section' => null, 'direction' => null],
        '1402' => ['label' => 'Cobros (entradas) por', 'kind' => 'subheading', 'section' => null, 'direction' => null],
        '1403' => ['label' => 'Cobro de préstamos otorgados a partes relacionadas', 'kind' => 'detail', 'section' => 'INVESTING', 'direction' => 'INFLOW'],
        '1409' => ['label' => 'Intereses y rendimientos cobrados de inversiones', 'kind' => 'detail', 'section' => 'INVESTING', 'direction' => 'INFLOW'],
        '1410' => ['label' => 'Dividendos cobrados', 'kind' => 'detail', 'section' => 'INVESTING', 'direction' => 'INFLOW'],
        '1411' => ['label' => 'Otros cobros identificados de inversión', 'kind' => 'detail', 'section' => 'INVESTING', 'direction' => 'INFLOW'],
        '1498' => ['label' => 'Cobros de inversión pendientes de desagregación', 'kind' => 'detail', 'section' => 'INVESTING', 'direction' => 'INFLOW'],
        '1499' => ['label' => 'TOTAL COBROS DE INVERSIÓN', 'kind' => 'subtotal', 'section' => 'INVESTING', 'direction' => 'INFLOW'],
        '1500' => ['label' => 'Menos pagos (salidas) por', 'kind' => 'subheading', 'section' => null, 'direction' => null],
        '1501' => ['label' => 'Préstamos otorgados a partes relacionadas', 'kind' => 'detail', 'section' => 'INVESTING', 'direction' => 'OUTFLOW'],
        '1505' => ['label' => 'Compra de propiedad, planta y equipo', 'kind' => 'detail', 'section' => 'INVESTING', 'direction' => 'OUTFLOW'],
        '1508' => ['label' => 'Otros pagos identificados de inversión', 'kind' => 'detail', 'section' => 'INVESTING', 'direction' => 'OUTFLOW'],
        '1598' => ['label' => 'Pagos de inversión pendientes de desagregación', 'kind' => 'detail', 'section' => 'INVESTING', 'direction' => 'OUTFLOW'],
        '1599' => ['label' => 'TOTAL PAGOS DE INVERSIÓN', 'kind' => 'subtotal', 'section' => 'INVESTING', 'direction' => 'OUTFLOW'],
        '1601' => ['label' => 'FLUJO NETO DE INVERSIÓN', 'kind' => 'summary', 'section' => null, 'direction' => null],
        '1700' => ['label' => 'ACTIVIDADES DE FINANCIACIÓN', 'kind' => 'section', 'section' => null, 'direction' => null],
        '1701' => ['label' => 'Cobros (entradas) por', 'kind' => 'subheading', 'section' => null, 'direction' => null],
        '1702' => ['label' => 'Aumento de sobregiros bancarios', 'kind' => 'detail', 'section' => 'FINANCING', 'direction' => 'INFLOW'],
        '1703' => ['label' => 'Préstamos y obligaciones financieras recibidos', 'kind' => 'detail', 'section' => 'FINANCING', 'direction' => 'INFLOW'],
        '1704' => ['label' => 'Emisión de acciones y nuevos aportes de capital', 'kind' => 'detail', 'section' => 'FINANCING', 'direction' => 'INFLOW'],
        '1798' => ['label' => 'Cobros de financiación pendientes de desagregación', 'kind' => 'detail', 'section' => 'FINANCING', 'direction' => 'INFLOW'],
        '1799' => ['label' => 'TOTAL COBROS DE FINANCIACIÓN', 'kind' => 'subtotal', 'section' => 'FINANCING', 'direction' => 'INFLOW'],
        '1800' => ['label' => 'Menos pagos (salidas) por', 'kind' => 'subheading', 'section' => null, 'direction' => null],
        '1801' => ['label' => 'Amortización de sobregiros bancarios', 'kind' => 'detail', 'section' => 'FINANCING', 'direction' => 'OUTFLOW'],
        '1802' => ['label' => 'Amortización de préstamos y obligaciones financieras', 'kind' => 'detail', 'section' => 'FINANCING', 'direction' => 'OUTFLOW'],
        '1805' => ['label' => 'Intereses pagados clasificados como financiación', 'kind' => 'detail', 'section' => 'FINANCING', 'direction' => 'OUTFLOW'],
        '1807' => ['label' => 'Dividendos pagados', 'kind' => 'detail', 'section' => 'FINANCING', 'direction' => 'OUTFLOW'],
        '1808' => ['label' => 'Otros pagos identificados de financiación', 'kind' => 'detail', 'section' => 'FINANCING', 'direction' => 'OUTFLOW'],
        '1898' => ['label' => 'Pagos de financiación pendientes de desagregación', 'kind' => 'detail', 'section' => 'FINANCING', 'direction' => 'OUTFLOW'],
        '1899' => ['label' => 'TOTAL PAGOS DE FINANCIACIÓN', 'kind' => 'subtotal', 'section' => 'FINANCING', 'direction' => 'OUTFLOW'],
        '1901' => ['label' => 'FLUJO NETO DE FINANCIACIÓN', 'kind' => 'summary', 'section' => null, 'direction' => null],
        '2001' => ['label' => 'AUMENTO / DISMINUCIÓN NETA DE EFECTIVO', 'kind' => 'summary', 'section' => null, 'direction' => null],
        '2001F' => ['label' => 'Saldo de efectivo y equivalentes al inicio', 'kind' => 'balance', 'section' => null, 'direction' => null],
        '2201G' => ['label' => 'Efecto de diferencias de cambio', 'kind' => 'summary', 'section' => null, 'direction' => null],
        '2201A' => ['label' => 'Otros ajustes al efectivo', 'kind' => 'warning', 'section' => null, 'direction' => null],
        '2999' => ['label' => 'SALDO DE EFECTIVO Y EQUIVALENTES AL FINAL', 'kind' => 'balance', 'section' => null, 'direction' => null],
        '2997' => ['label' => 'Efectivo sin clasificar (requiere revisión)', 'kind' => 'warning', 'section' => null, 'direction' => null],
        '2998' => ['label' => 'Diferencia de conciliación', 'kind' => 'warning', 'section' => null, 'direction' => null],
    ];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'hide_zero' => false,
            'labels' => [],
            'hidden_codes' => [],
            'custom_lines' => [],
            'account_rules' => [],
            'area_rules' => [],
        ];
    }

    /** @param array<string, mixed> $settings */
    public static function line(string $code, array $settings): ?array
    {
        if (isset(self::LINES[$code])) {
            return self::LINES[$code];
        }
        foreach ($settings['custom_lines'] ?? [] as $row) {
            if ($row['code'] === $code) {
                return [
                    'label' => $row['label'], 'kind' => 'detail',
                    'section' => $row['section'], 'direction' => $row['direction'],
                ];
            }
        }
        return null;
    }

    /** @param array<string, mixed> $settings */
    public static function validTarget(string $code, array $settings, string $section, string $direction): bool
    {
        $line = self::line($code, $settings);
        return $line !== null && $line['kind'] === 'detail'
            && $line['section'] === $section && $line['direction'] === $direction;
    }

    /** @return array<string, mixed> */
    public static function forApi(): array
    {
        return self::LINES;
    }
}
