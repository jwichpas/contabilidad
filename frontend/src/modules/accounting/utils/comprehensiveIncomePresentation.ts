import type { FinancialSnapshot, FinancialSnapshotAccount } from '../types/financialSnapshot.types';
import { formatPositionDate } from './financialPositionPresentation.ts';

/**
 * Presentation by function, only when the account has evidence of that
 * function. Ledger balances are the source of truth, NOT account descriptions
 * or a guessed 94/95 allocation. OCI currently has no recognized, validated
 * posting flow: "pending" is safer than displaying fictional zero.
 */
export type IncomeLineKey =
  | 'sales' | 'discounts' | 'net_sales' | 'cost_of_sales' | 'gross_profit'
  | 'other_operating_income' | 'selling' | 'administration' | 'unallocated_operating'
  | 'operating_profit' | 'financial_income' | 'financial_expense' | 'exchange_difference'
  | 'other_nonoperating' | 'profit_before_tax' | 'income_tax' | 'unclassified'
  | 'net_profit' | 'oci' | 'total_comprehensive';
export type IncomeLineKind = 'detail' | 'subtotal' | 'total' | 'warning';
export interface ComprehensiveIncomeLine {
  readonly key: IncomeLineKey;
  readonly label: string;
  readonly kind: IncomeLineKind;
  /** Positive income and negative expenses. Null = cannot determine. */
  readonly amount: string | null;
  readonly note: null;
  readonly accountIds: readonly number[];
}
export interface ComprehensiveIncomePresentation {
  readonly from: string;
  readonly to: string;
  readonly currency: string;
  readonly lines: readonly ComprehensiveIncomeLine[];
  readonly netProfit: string;
  readonly preTaxProfit: string;
  readonly grossProfit: string;
  readonly operatingProfit: string;
  readonly comprehensiveTotal: null;
  readonly discrepancy: string;
  readonly warnings: readonly string[];
}
const ZERO = '0.0000';
const KEYS: readonly IncomeLineKey[] = [
  'sales','discounts','net_sales','cost_of_sales','gross_profit',
  'other_operating_income','selling','administration','unallocated_operating',
  'operating_profit','financial_income','financial_expense','exchange_difference',
  'other_nonoperating','profit_before_tax','income_tax','unclassified',
  'net_profit','oci','total_comprehensive',
];
const LABELS: Record<IncomeLineKey, string> = {
  sales: 'Ventas de bienes y prestación de servicios',
  discounts: 'Descuentos, rebajas y devoluciones sobre ventas',
  net_sales: 'VENTAS NETAS',
  cost_of_sales: 'Costo de ventas de bienes y servicios',
  gross_profit: 'UTILIDAD BRUTA',
  other_operating_income: 'Otros ingresos operativos',
  selling: 'Gastos de venta y distribución',
  administration: 'Gastos de administración',
  unallocated_operating: 'Gastos operativos sin distribución por función',
  operating_profit: 'UTILIDAD OPERATIVA',
  financial_income: 'Ingresos financieros',
  financial_expense: 'Gastos financieros',
  exchange_difference: 'Diferencia de cambio, neta',
  other_nonoperating: 'Otros ingresos y gastos no operativos',
  profit_before_tax: 'UTILIDAD ANTES DEL IMPUESTO A LAS GANANCIAS',
  income_tax: 'Impuesto a las ganancias',
  unclassified: 'Partidas del resultado pendientes de clasificación',
  net_profit: 'UTILIDAD NETA DEL EJERCICIO',
  oci: 'Otros resultados integrales (pendiente de evaluar)',
  total_comprehensive: 'TOTAL RESULTADOS INTEGRALES (pendiente de OCI)',
};
const KINDS = new Set<IncomeLineKey>(['net_sales','gross_profit','operating_profit','profit_before_tax']);
const TOTALS = new Set<IncomeLineKey>(['net_profit','total_comprehensive']);

function scaled(value: string): bigint {
  const m = /^(-?)(\d+)(?:\.(\d{1,4}))?$/.exec(value);
  if (!m) throw new Error('Importe del estado de resultados inválido: ' + value);
  const magnitude = BigInt(m[2] ?? '0') * 10000n + BigInt((m[3] ?? '').padEnd(4,'0'));
  return m[1] === '-' ? -magnitude : magnitude;
}
function unscaled(value: bigint): string {
  const magnitude = value < 0n ? -value : value;
  return (value < 0n ? '-' : '') + (magnitude / 10000n).toString() + '.' +
    (magnitude % 10000n).toString().padStart(4,'0');
}
export function sumIncome(values: readonly string[]): string {
  return unscaled(values.reduce((sum, value) => sum + scaled(value),0n));
}
const neg = (value: string): string => unscaled(-scaled(value));

/** Use a two-decimal display without losing four-place accounting precision. */
export function formatIncomeAmount(value: string | null): string {
  if (value === null) return 'Pendiente';
  const amount = scaled(value);
  if (amount === 0n) return '—';
  const positive = amount < 0n ? -amount : amount;
  const hundredths = (positive + 50n) / 100n;
  const number = (hundredths / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g,',') +
    '.' + (hundredths % 100n).toString().padStart(2,'0');
  return amount < 0n ? '(' + number + ')' : number;
}
export function formatIncomePercent(value: string | null, netSales: string): string {
  if (value === null || scaled(netSales) === 0n) return '—';
  const base = scaled(netSales);
  const amount = scaled(value);
  const absAmount = amount < 0n ? -amount : amount;
  const absBase = base < 0n ? -base : base;
  const points = (absAmount * 10000n + absBase / 2n) / absBase;
  const percent = (points / 100n).toString() + '.' +
    (points % 100n).toString().padStart(2,'0') + '%';
  return amount < 0n ? '(' + percent + ')' : percent;
}

function classify(account: FinancialSnapshotAccount): IncomeLineKey {
  const code = account.code;
  if (code.startsWith('709')) return 'discounts';
  if (code.startsWith('70')) return 'sales';
  if (code.startsWith('69')) return 'cost_of_sales';
  if (code.startsWith('776') || code.startsWith('676')) return 'exchange_difference';
  if (code.startsWith('77')) return 'financial_income';
  if (code.startsWith('67')) return 'financial_expense';
  if (code.startsWith('75')) return 'other_operating_income';
  if (code.startsWith('88')) return 'income_tax';
  // Functional expenses are classified only by an explicitly registered
  // functional rubric: don't silently assign rent or depreciation to 94/95.
  if (account.rubric === 'ERF_GASTOS_VENTAS_95') return 'selling';
  if (account.rubric === 'ERF_GASTOS_ADMIN_94') return 'administration';
  if (code.startsWith('6')) return 'unallocated_operating';
  if (code.startsWith('7')) return 'other_nonoperating';
  return 'unclassified';
}

export function formatIncomePeriod(from: string, to: string): string {
  const fullYear = from.endsWith('-01-01') && to.endsWith('-12-31');
  return (fullYear ? 'Por el año terminado el ' : 'Por el período terminado el ') +
    formatPositionDate(to).replace(/^Al /, '');
}

export function buildComprehensiveIncomePresentation(data: FinancialSnapshot): ComprehensiveIncomePresentation {
  const totals = Object.fromEntries(KEYS.map((key) => [key, ZERO])) as Record<IncomeLineKey, string>;
  const sources = Object.fromEntries(KEYS.map((key) => [key, []])) as Record<IncomeLineKey, number[]>;
  const warnings: string[] = [];
  const recorded = data.accounts.filter((account) => account.section === 'result');
  for (const account of recorded) {
    const key = classify(account);
    // In the existing snapshot API, revenue period amount is credit-positive;
    // all other accounts' period amount is debit-positive, even PCGE 88.
    const period = account.period_amount_local ?? ZERO;
    const signed = account.account_type === 'REVENUE' ? period : neg(period);
    totals[key] = sumIncome([totals[key], signed]);
    sources[key].push(account.id);
  }

  totals.net_sales = sumIncome([totals.sales, totals.discounts]);
  totals.gross_profit = sumIncome([totals.net_sales, totals.cost_of_sales]);
  totals.operating_profit = sumIncome([
    totals.gross_profit, totals.other_operating_income, totals.selling,
    totals.administration, totals.unallocated_operating,
  ]);
  totals.profit_before_tax = sumIncome([
    totals.operating_profit, totals.financial_income, totals.financial_expense,
    totals.exchange_difference, totals.other_nonoperating,
  ]);
  const derivedNet = sumIncome([totals.profit_before_tax, totals.income_tax, totals.unclassified]);
  // Never hide a discrepancy due to an unexpected account type or mapping.
  const discrepancy = sumIncome([data.income_statement.net, neg(derivedNet)]);
  if (scaled(discrepancy) !== 0n) {
    totals.unclassified = sumIncome([totals.unclassified, discrepancy]);
    warnings.push('Existe una diferencia de clasificación de ' + discrepancy +
      ' entre los saldos auxiliares y el resultado neto. Revisar el Libro Mayor.');
  }
  totals.net_profit = data.income_statement.net;
  if (scaled(totals.unclassified) !== 0n) {
    warnings.push('Hay partidas de resultado no identificadas que requieren asignación contable.');
  }
  if (scaled(totals.unallocated_operating) !== 0n) {
    warnings.push('Gastos operativos aún sin distribución por función: no se atribuyen automáticamente a ventas o administración.');
  }
  if (data.analytic_routing_status !== 'ACTIVE') {
    warnings.push('El destino analítico 94/95 todavía no está activo para esta empresa.');
  }
  warnings.push(
    'Los otros resultados integrales (ORI) no se calculan en el módulo actual. Hasta validar partidas ORI, el total de resultados integrales permanece pendiente.',
    'Estado preliminar sin comparativos ni notas aprobadas. Impuesto de enero 2026 en DEMO: supuesto contable, no liquidación tributaria.',
  );

  const lines: ComprehensiveIncomeLine[] = KEYS.map((key) => ({
    key, label: LABELS[key],
    kind: key === 'oci' || key === 'unclassified' ? 'warning' :
      TOTALS.has(key) ? 'total' : KINDS.has(key) ? 'subtotal' : 'detail',
    amount: key === 'oci' || key === 'total_comprehensive' ? null : totals[key],
    note: null, accountIds: sources[key],
  }));
  return {
    from: data.income_statement.from,
    to: data.income_statement.to,
    currency: data.currency,
    lines,
    netProfit: totals.net_profit,
    preTaxProfit: totals.profit_before_tax,
    grossProfit: totals.gross_profit,
    operatingProfit: totals.operating_profit,
    comprehensiveTotal: null,
    discrepancy,
    warnings,
  };
}
