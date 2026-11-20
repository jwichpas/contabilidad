<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;

/**
 * Authoritative allowlist. Only display attributes can be overridden.
 * Activity/section, formula, rubric and line codes cannot be reassigned.
 */
final class FinancialReportLayoutCatalog
{
    public const STATEMENTS = ['ESF', 'ERI', 'EFE', 'ECP'];

    /** @return array<string, array{label:string,group:string,kind:string}> */
    public static function forStatement(string $statement): array
    {
        if ($statement === 'ESF') {
            $rows = DB::table('financial_statement_rubrics')
                ->where('is_active', true)->where('statement_type', 'ESF')
                ->orderBy('presentation_order')->get(['code', 'name', 'group_name']);
            $result = [];
            foreach ($rows as $row) {
                $result[(string) $row->code] = [
                    'label' => (string) $row->name,
                    'group' => (string) $row->group_name,
                    'kind' => 'detail',
                ];
            }
            $result['CURRENT-RESULT'] = [
                'label' => 'Utilidad / pérdida del ejercicio (sin cierre)',
                'group' => 'PATRIMONIO',
                'kind' => 'protected',
            ];
            $result['UN-CLOSED-PRIOR'] = [
                'label' => 'Resultados anteriores sin cierre (revisar)',
                'group' => 'PATRIMONIO',
                'kind' => 'protected',
            ];
            return $result;
        }
        if ($statement === 'ERI') {
            // Same stable identifiers as frontend comprehensiveIncomePresentation.
            $groups = [
                'sales' => 'SALES', 'discounts' => 'SALES',
                'net_sales' => 'SUBTOTAL', 'cost_of_sales' => 'COST',
                'gross_profit' => 'SUBTOTAL',
                'other_operating_income' => 'OPERATING', 'selling' => 'OPERATING',
                'administration' => 'OPERATING', 'unallocated_operating' => 'OPERATING',
                'operating_profit' => 'SUBTOTAL',
                'financial_income' => 'FINANCIAL', 'financial_expense' => 'FINANCIAL',
                'exchange_difference' => 'FINANCIAL', 'other_nonoperating' => 'FINANCIAL',
                'profit_before_tax' => 'SUBTOTAL',
                'income_tax' => 'TAX', 'unclassified' => 'TAX',
                'net_profit' => 'TOTAL', 'oci' => 'OCI', 'total_comprehensive' => 'TOTAL',
            ];
            $result = [];
            foreach ($groups as $code => $group) {
                $protected = in_array($group, ['SUBTOTAL', 'TOTAL'], true)
                    || in_array($code, ['unclassified', 'unallocated_operating', 'oci'], true);
                $result[$code] = [
                    'label' => self::eriLabel($code),
                    'group' => $group,
                    'kind' => $protected ? 'protected' : 'detail',
                ];
            }
            return $result;
        }
        if ($statement === 'EFE') {
            $result = [];
            foreach (CashFlowPresentationCatalog::LINES as $code => $line) {
                $isDetail = $line['kind'] === 'detail'
                    && ! in_array($code, ['1198', '1298', '1498', '1598', '1798', '1898'], true);
                $result[$code] = [
                    'label' => $line['label'],
                    'group' => $line['section'] !== null
                        ? $line['section'].'|'.$line['direction'] : 'PROTECTED',
                    'kind' => $isDetail ? 'detail' : 'protected',
                ];
            }
            return $result;
        }

        if ($statement === 'ECP') {
            // Same immutable component keys as EquityChangesCalculator.
            // Income, opening bridge and closing subtotals remain protected.
            return [
                'CAPITAL' => ['label' => 'Capital social', 'group' => 'PATRIMONIO', 'kind' => 'detail'],
                'ADDITIONAL_CAPITAL' => ['label' => 'Capital adicional', 'group' => 'PATRIMONIO', 'kind' => 'detail'],
                'UNREALIZED' => ['label' => 'Resultados no realizados (revisar ORI)', 'group' => 'PATRIMONIO', 'kind' => 'detail'],
                'SURPLUS' => ['label' => 'Excedente de revaluación', 'group' => 'PATRIMONIO', 'kind' => 'detail'],
                'RESERVES' => ['label' => 'Reservas', 'group' => 'PATRIMONIO', 'kind' => 'detail'],
                'RETAINED' => ['label' => 'Resultados acumulados', 'group' => 'PATRIMONIO', 'kind' => 'detail'],
                'OTHER_EQUITY' => ['label' => 'Otro patrimonio sin asignación específica', 'group' => 'PATRIMONIO', 'kind' => 'detail'],
                'PRIOR_UNCLOSED' => ['label' => 'Resultado anterior no cerrado', 'group' => 'RESULTADO', 'kind' => 'protected'],
                'PERIOD_RESULT' => ['label' => 'Resultado del ejercicio', 'group' => 'RESULTADO', 'kind' => 'protected'],
                'EQUITY_TOTAL' => ['label' => 'Patrimonio final incluyendo resultado', 'group' => 'TOTAL', 'kind' => 'protected'],
            ];
        }
        abort(404, 'Estado financiero desconocido.');
    }

    public static function defaults(): array
    {
        return ['labels' => [], 'hidden_codes' => [], 'line_order' => [], 'hide_zero' => false];
    }

    private static function eriLabel(string $code): string
    {
        return [
            'sales' => 'Ventas de bienes y prestación de servicios',
            'discounts' => 'Descuentos, rebajas y devoluciones sobre ventas',
            'net_sales' => 'VENTAS NETAS', 'cost_of_sales' => 'Costo de ventas de bienes y servicios',
            'gross_profit' => 'UTILIDAD BRUTA', 'other_operating_income' => 'Otros ingresos operativos',
            'selling' => 'Gastos de venta y distribución', 'administration' => 'Gastos de administración',
            'unallocated_operating' => 'Gastos operativos sin distribución por función',
            'operating_profit' => 'UTILIDAD OPERATIVA', 'financial_income' => 'Ingresos financieros',
            'financial_expense' => 'Gastos financieros', 'exchange_difference' => 'Diferencia de cambio, neta',
            'other_nonoperating' => 'Otros ingresos y gastos no operativos',
            'profit_before_tax' => 'UTILIDAD ANTES DEL IMPUESTO A LAS GANANCIAS',
            'income_tax' => 'Impuesto a las ganancias',
            'unclassified' => 'Partidas del resultado pendientes de clasificación',
            'net_profit' => 'UTILIDAD NETA DEL EJERCICIO',
            'oci' => 'Otros resultados integrales (pendiente de evaluar)',
            'total_comprehensive' => 'TOTAL RESULTADOS INTEGRALES (pendiente de OCI)',
        ][$code];
    }
}
