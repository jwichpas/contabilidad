import type {
  AnnualCashFlowMonth, AnnualCashFlowReport, CashFlowGroup,
} from '../types/cashFlow.types';

export type AnnualRowKind = 'section' | 'subheading' | 'detail' | 'subtotal' | 'summary' | 'balance' | 'warning';
export type AnnualFlowSection = 'OPERATING' | 'INVESTING' | 'FINANCING';
export type AnnualDirection = 'INFLOW' | 'OUTFLOW';
export type AnnualTotalKey = 'opening_local' | 'closing_local' | 'operating_local' | 'investing_local'
  | 'financing_local' | 'net_flow_local' | 'exchange_effect_local'
  | 'other_adjustments_local' | 'difference_local' | 'unclassified_local';

interface LineDefinition {
  readonly code: string;
  readonly label: string;
  readonly kind: AnnualRowKind;
  readonly section?: AnnualFlowSection;
  readonly direction?: AnnualDirection;
  readonly field?: AnnualTotalKey;
}
export interface AnnualCashFlowMatrixRow {
  readonly code: string;
  readonly label: string;
  readonly kind: AnnualRowKind;
  readonly values: readonly string[];
  readonly total: string;
}

/** Display layout inspired by the user's Formato 3.18 reference.
 * These display codes are NOT an exportable SUNAT PLE file specification.
 * Detail is only assigned when the accountant coded the cash-flow AREA
 * with the exact line code or F318-xxxx. Otherwise it goes to the separate
 * "pendiente de desagregación" line, never silently to sales or suppliers.
 */
export const ANNUAL_FLOW_LINES: readonly LineDefinition[] = [
  { code: '1100', label: 'ACTIVIDADES DE OPERACIÓN', kind: 'section' },
  { code: '1101', label: 'Cobros (entradas) por', kind: 'subheading' },
  { code: '1102', label: 'Venta de bienes y prestación de servicios', kind: 'detail', section: 'OPERATING', direction: 'INFLOW' },
  { code: '1103', label: 'Honorarios y comisiones', kind: 'detail', section: 'OPERATING', direction: 'INFLOW' },
  { code: '1104', label: 'Intereses y rendimientos cobrados', kind: 'detail', section: 'OPERATING', direction: 'INFLOW' },
  { code: '1105', label: 'Dividendos y distribuciones cobrados', kind: 'detail', section: 'OPERATING', direction: 'INFLOW' },
  { code: '1107', label: 'Otros cobros identificados de operación', kind: 'detail', section: 'OPERATING', direction: 'INFLOW' },
  { code: '1198', label: 'Cobros operativos pendientes de desagregación', kind: 'detail', section: 'OPERATING', direction: 'INFLOW' },
  { code: '1199', label: 'TOTAL COBROS DE OPERACIÓN', kind: 'subtotal', section: 'OPERATING', direction: 'INFLOW' },
  { code: '1200', label: 'Menos pagos (salidas) por', kind: 'subheading' },
  { code: '1201', label: 'Proveedores de bienes y servicios', kind: 'detail', section: 'OPERATING', direction: 'OUTFLOW' },
  { code: '1202', label: 'Remuneraciones y beneficios sociales', kind: 'detail', section: 'OPERATING', direction: 'OUTFLOW' },
  { code: '1203', label: 'Tributos pagados', kind: 'detail', section: 'OPERATING', direction: 'OUTFLOW' },
  { code: '1205', label: 'Regalías', kind: 'detail', section: 'OPERATING', direction: 'OUTFLOW' },
  { code: '1206', label: 'Otros pagos identificados de operación', kind: 'detail', section: 'OPERATING', direction: 'OUTFLOW' },
  { code: '1298', label: 'Pagos operativos pendientes de desagregación', kind: 'detail', section: 'OPERATING', direction: 'OUTFLOW' },
  { code: '1299', label: 'TOTAL PAGOS DE OPERACIÓN', kind: 'subtotal', section: 'OPERATING', direction: 'OUTFLOW' },
  { code: '1301', label: 'FLUJO NETO DE OPERACIÓN', kind: 'summary', field: 'operating_local' },
  { code: '1400', label: 'ACTIVIDADES DE INVERSIÓN', kind: 'section' },
  { code: '1402', label: 'Cobros (entradas) por', kind: 'subheading' },
  { code: '1403', label: 'Cobro de préstamos otorgados a partes relacionadas', kind: 'detail', section: 'INVESTING', direction: 'INFLOW' },
  { code: '1409', label: 'Intereses y rendimientos cobrados de inversiones', kind: 'detail', section: 'INVESTING', direction: 'INFLOW' },
  { code: '1410', label: 'Dividendos cobrados', kind: 'detail', section: 'INVESTING', direction: 'INFLOW' },
  { code: '1411', label: 'Otros cobros identificados de inversión', kind: 'detail', section: 'INVESTING', direction: 'INFLOW' },
  { code: '1498', label: 'Cobros de inversión pendientes de desagregación', kind: 'detail', section: 'INVESTING', direction: 'INFLOW' },
  { code: '1499', label: 'TOTAL COBROS DE INVERSIÓN', kind: 'subtotal', section: 'INVESTING', direction: 'INFLOW' },
  { code: '1500', label: 'Menos pagos (salidas) por', kind: 'subheading' },
  { code: '1501', label: 'Préstamos otorgados a partes relacionadas', kind: 'detail', section: 'INVESTING', direction: 'OUTFLOW' },
  { code: '1505', label: 'Compra de propiedad, planta y equipo', kind: 'detail', section: 'INVESTING', direction: 'OUTFLOW' },
  { code: '1508', label: 'Otros pagos identificados de inversión', kind: 'detail', section: 'INVESTING', direction: 'OUTFLOW' },
  { code: '1598', label: 'Pagos de inversión pendientes de desagregación', kind: 'detail', section: 'INVESTING', direction: 'OUTFLOW' },
  { code: '1599', label: 'TOTAL PAGOS DE INVERSIÓN', kind: 'subtotal', section: 'INVESTING', direction: 'OUTFLOW' },
  { code: '1601', label: 'FLUJO NETO DE INVERSIÓN', kind: 'summary', field: 'investing_local' },
  { code: '1700', label: 'ACTIVIDADES DE FINANCIACIÓN', kind: 'section' },
  { code: '1701', label: 'Cobros (entradas) por', kind: 'subheading' },
  { code: '1702', label: 'Aumento de sobregiros bancarios', kind: 'detail', section: 'FINANCING', direction: 'INFLOW' },
  { code: '1703', label: 'Préstamos y obligaciones financieras recibidos', kind: 'detail', section: 'FINANCING', direction: 'INFLOW' },
  { code: '1704', label: 'Emisión de acciones y nuevos aportes de capital', kind: 'detail', section: 'FINANCING', direction: 'INFLOW' },
  { code: '1798', label: 'Cobros de financiación pendientes de desagregación', kind: 'detail', section: 'FINANCING', direction: 'INFLOW' },
  { code: '1799', label: 'TOTAL COBROS DE FINANCIACIÓN', kind: 'subtotal', section: 'FINANCING', direction: 'INFLOW' },
  { code: '1800', label: 'Menos pagos (salidas) por', kind: 'subheading' },
  { code: '1801', label: 'Amortización de sobregiros bancarios', kind: 'detail', section: 'FINANCING', direction: 'OUTFLOW' },
  { code: '1802', label: 'Amortización de préstamos y obligaciones financieras', kind: 'detail', section: 'FINANCING', direction: 'OUTFLOW' },
  { code: '1805', label: 'Intereses pagados clasificados como financiación', kind: 'detail', section: 'FINANCING', direction: 'OUTFLOW' },
  { code: '1807', label: 'Dividendos pagados', kind: 'detail', section: 'FINANCING', direction: 'OUTFLOW' },
  { code: '1808', label: 'Otros pagos identificados de financiación', kind: 'detail', section: 'FINANCING', direction: 'OUTFLOW' },
  { code: '1898', label: 'Pagos de financiación pendientes de desagregación', kind: 'detail', section: 'FINANCING', direction: 'OUTFLOW' },
  { code: '1899', label: 'TOTAL PAGOS DE FINANCIACIÓN', kind: 'subtotal', section: 'FINANCING', direction: 'OUTFLOW' },
  { code: '1901', label: 'FLUJO NETO DE FINANCIACIÓN', kind: 'summary', field: 'financing_local' },
  { code: '2001', label: 'AUMENTO / DISMINUCIÓN NETA DE EFECTIVO', kind: 'summary', field: 'net_flow_local' },
  { code: '2001F', label: 'Saldo de efectivo y equivalentes al inicio', kind: 'balance', field: 'opening_local' },
  { code: '2201G', label: 'Efecto de diferencias de cambio', kind: 'summary', field: 'exchange_effect_local' },
  { code: '2201A', label: 'Otros ajustes al efectivo', kind: 'warning', field: 'other_adjustments_local' },
  { code: '2999', label: 'SALDO DE EFECTIVO Y EQUIVALENTES AL FINAL', kind: 'balance', field: 'closing_local' },
  { code: '2997', label: 'Efectivo sin clasificar (requiere revisión)', kind: 'warning', field: 'unclassified_local' },
  { code: '2998', label: 'Diferencia de conciliación', kind: 'warning', field: 'difference_local' },
];

const ZERO = '0.0000';
function scaled(value: string): bigint {
  const m = /^(-?)(\d+)(?:\.(\d{1,4}))?$/.exec(value);
  if (!m) throw new Error('Importe contable inválido: ' + value);
  const units = BigInt(m[2] ?? '0') * 10000n + BigInt((m[3] ?? '').padEnd(4, '0'));
  return (m[1] === '-' ? -units : units);
}
function plain(value: bigint): string {
  const sign = value < 0n ? '-' : '';
  const abs = value < 0n ? -value : value;
  return sign + (abs / 10000n).toString() + '.' + (abs % 10000n).toString().padStart(4, '0');
}
export function addMoney(values: readonly string[]): string {
  return plain(values.reduce((sum, value) => sum + scaled(value), 0n));
}
export function negateMoney(value: string): string {
  return plain(-scaled(value));
}
function areaCode(code: string | null): string | null {
  if (code === null) return null;
  const upper = code.trim().toUpperCase();
  const match = /^(?:F318-)?(\d{4})$/.exec(upper);
  return match?.[1] ?? null;
}

function lineValue(line: LineDefinition, m: AnnualCashFlowMonth): string {
  if (line.kind === 'section' || line.kind === 'subheading') return ZERO;
  if (line.field) return m[line.field];
  const section = line.section;
  const direction = line.direction;
  if (!section || !direction) return ZERO;
  const residualCode = { OPERATING: direction === 'INFLOW' ? '1198' : '1298',
    INVESTING: direction === 'INFLOW' ? '1498' : '1598',
    FINANCING: direction === 'INFLOW' ? '1798' : '1898' }[section];
  const values = m.groups.filter((group: CashFlowGroup) => {
    if (group.section !== section) return false;
    if (line.kind === 'subtotal') return true;
    const mapped = areaCode(group.code);
    const recognized = ANNUAL_FLOW_LINES.some((candidate) =>
      candidate.kind === 'detail' && candidate.code === mapped
      && candidate.section === section && candidate.direction === direction);
    return line.code === (recognized ? mapped : residualCode);
  }).map((group) => direction === 'INFLOW' ? group.inflow_local : negateMoney(group.outflow_local));
  return addMoney(values);
}

export function buildAnnualCashFlowMatrix(report: AnnualCashFlowReport): readonly AnnualCashFlowMatrixRow[] {
  if (report.monthly.length !== 12 || report.monthly.some((month, index) => month.month !== index + 1)) {
    throw new Error('Matriz de 12 meses incompleta o desordenada.');
  }
  return ANNUAL_FLOW_LINES.map((line) => {
    const values = report.monthly.map((month) => lineValue(line, month));
    let total = addMoney(values);
    if (line.field === 'opening_local') total = report.totals.opening_local;
    if (line.field === 'closing_local') total = report.totals.closing_local;
    if (line.field === 'difference_local') total = report.totals.difference_local;
    return { code: line.code, label: line.label, kind: line.kind, values, total };
  });
}
export function formatMatrixAmount(value: string): string {
  if (scaled(value) === 0n) return '–';
  const [integer = '0', decimal = '0000'] = value.split('.');
  const sign = integer.startsWith('-') ? '−' : '';
  const digits = integer.replace(/^-/, '').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  return sign + digits + ',' + decimal;
}
