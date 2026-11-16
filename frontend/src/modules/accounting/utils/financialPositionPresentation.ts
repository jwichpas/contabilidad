import type {
  FinancialSnapshot, FinancialSnapshotRubric,
} from '../types/financialSnapshot.types';
import type { FinancialReportLayoutSettings } from '../types/financialLayout.types';
import { applyFinancialReportLayout } from './financialLayout.ts';

export type PositionGroupKey = 'ACTIVO_CORRIENTE' | 'ACTIVO_NO_CORRIENTE' | 'ACTIVO_SIN_CLASIFICAR'
  | 'PASIVO_CORRIENTE' | 'PASIVO_NO_CORRIENTE' | 'PASIVO_SIN_CLASIFICAR' | 'PATRIMONIO';

export interface PositionLine {
  readonly code: string;
  readonly label: string;
  readonly value: string;
  readonly accountIds: readonly number[];
  /** La API todavía no tiene referencias de notas aprobadas y vinculadas. */
  readonly note: null;
  readonly requiresReview?: boolean;
}
export interface PositionGroup {
  readonly key: PositionGroupKey;
  readonly label: string;
  readonly rows: readonly PositionLine[];
  readonly subtotal: string;
}
export interface FinancialPositionPresentation {
  readonly assetGroups: readonly PositionGroup[];
  readonly liabilityGroups: readonly PositionGroup[];
  readonly equityGroup: PositionGroup;
  readonly assetTotal: string;
  readonly liabilityTotal: string;
  readonly equityTotal: string;
  readonly liabilityAndEquityTotal: string;
  readonly difference: string;
  readonly warnings: readonly string[];
}

const ZERO = '0.0000';
const CURRENT_GROUPS: Record<PositionGroupKey, string> = {
  ACTIVO_CORRIENTE: 'Activos corrientes',
  ACTIVO_NO_CORRIENTE: 'Activos no corrientes',
  ACTIVO_SIN_CLASIFICAR: 'Activos pendientes de clasificación',
  PASIVO_CORRIENTE: 'Pasivos corrientes',
  PASIVO_NO_CORRIENTE: 'Pasivos no corrientes',
  PASIVO_SIN_CLASIFICAR: 'Pasivos pendientes de clasificación',
  PATRIMONIO: 'Patrimonio',
};

function minorUnits(value: string): bigint {
  const m = /^(-?)(\d+)(?:\.(\d{1,4}))?$/.exec(value);
  if (!m) throw new Error('Importe contable no válido para ESF: ' + value);
  const cents = BigInt(m[2] ?? '0') * 10000n + BigInt((m[3] ?? '').padEnd(4, '0'));
  return m[1] === '-' ? -cents : cents;
}
function fromMinor(value: bigint): string {
  const abs = value < 0n ? -value : value;
  return (value < 0n ? '-' : '') + (abs / 10000n).toString() + '.' +
    (abs % 10000n).toString().padStart(4, '0');
}
export function sumPositionMoney(values: readonly string[]): string {
  return fromMinor(values.reduce((sum, value) => sum + minorUnits(value), 0n));
}
function minus(a: string, b: string): string {
  return fromMinor(minorUnits(a) - minorUnits(b));
}

/** Redondeo half-up solo de la visualización, nunca de los saldos NIIF. */
export function formatPositionAmount(value: string): string {
  const raw = minorUnits(value);
  if (raw === 0n) return '—';
  const abs = raw < 0n ? -raw : raw;
  const hundredths = (abs + 50n) / 100n;
  if (hundredths === 0n) return '—';
  const whole = (hundredths / 100n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const decimal = (hundredths % 100n).toString().padStart(2, '0');
  return (raw < 0n ? '−' : '') + whole + '.' + decimal;
}
export function isPositionZero(value: string): boolean {
  return minorUnits(value) === 0n;
}
export function currencyPositionLabel(currency: string): string {
  switch (currency) {
    case 'PEN': return 'Expresado en soles (S/)';
    case 'USD': return 'Expresado en dólares estadounidenses (US$)';
    case 'EUR': return 'Expresado en euros (€)';
    default: return 'Expresado en ' + currency;
  }
}
export function formatPositionDate(asOf: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(asOf);
  if (!m) return asOf;
  const months = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Setiembre','Octubre','Noviembre','Diciembre'];
  const name = months[Number(m[2]) - 1];
  if (!name) return asOf;
  return 'Al ' + Number(m[3]) + ' de ' + name + ' de ' + m[1];
}

const EMPTY_KEYS: Record<'assets' | 'liabilities' | 'equity', PositionGroupKey> = {
  assets: 'ACTIVO_SIN_CLASIFICAR',
  liabilities: 'PASIVO_SIN_CLASIFICAR',
  equity: 'PATRIMONIO',
};
const GROUPS_BY_SECTION: Record<'assets' | 'liabilities' | 'equity', readonly PositionGroupKey[]> = {
  assets: ['ACTIVO_CORRIENTE','ACTIVO_NO_CORRIENTE','ACTIVO_SIN_CLASIFICAR'],
  liabilities: ['PASIVO_CORRIENTE','PASIVO_NO_CORRIENTE','PASIVO_SIN_CLASIFICAR'],
  equity: ['PATRIMONIO'],
};
function normalizedGroup(
  section: 'assets' | 'liabilities' | 'equity', group: string,
): PositionGroupKey {
  if (GROUPS_BY_SECTION[section].includes(group as PositionGroupKey)) {
    return group as PositionGroupKey;
  }
  return EMPTY_KEYS[section];
}

/**
 * The classification and values are supplied by the backend's official,
 * versioned ESF rubric catalog and posted journal lines.
 * No account-prefix guesses, fictitious notes or invented amounts.
 */
export function buildFinancialPositionPresentation(data: FinancialSnapshot, layout?: FinancialReportLayoutSettings | null): FinancialPositionPresentation {
  const accountGroups: Record<string, FinancialSnapshotRubric> = {};
  const actualRubrics = data.rubrics.filter((rubric) =>
    ['assets','liabilities','equity'].includes(rubric.section));
  for (const rubric of actualRubrics) accountGroups[rubric.section + '|' + rubric.code] = rubric;

  const catalog = data.esf_catalog?.length
    ? data.esf_catalog
    : data.rubrics.filter((r) => ['assets','liabilities','equity'].includes(r.section));

  const build = (section: 'assets' | 'liabilities' | 'equity', key: PositionGroupKey): PositionGroup => {
    const used = new Set<string>();
    const standard = catalog
      .filter((item) => normalizedGroup(section, item.group_name) === key
        && item.code.startsWith('ESF_')
        && (section === 'assets' ? item.code.startsWith('ESF_ACT_')
          : section === 'liabilities' ? item.code.startsWith('ESF_PAS_') : item.code.startsWith('ESF_PAT_')))
      .sort((a,b) => a.presentation_order - b.presentation_order || a.code.localeCompare(b.code))
      .map((item): PositionLine => {
        const current = accountGroups[section + '|' + item.code];
        used.add(item.code);
        return {
          code: item.code, label: item.name, value: current?.amount_local ?? ZERO,
          accountIds: current?.account_ids ?? [], note: null,
        };
      });

    const unmatched = actualRubrics.filter((rubric) => rubric.section === section &&
      normalizedGroup(section, rubric.group_name) === key && !used.has(rubric.code))
      .map((rubric): PositionLine => ({
        code: rubric.code, label: rubric.code === 'NONE' ? 'Sin rubro ESF asignado (revisar)' : rubric.name,
        value: rubric.amount_local, accountIds: rubric.account_ids, note: null, requiresReview: true,
      }));
    const all = [...standard, ...unmatched];
    const eligible = new Set(standard.map((line) => line.code));
    return {
      key, label: CURRENT_GROUPS[key],
      rows: applyFinancialReportLayout(
        all, layout,
        (code) => eligible.has(code) ? key : null,
        (code) => eligible.has(code),
        (line) => isPositionZero(line.value),
      ),
      subtotal: sumPositionMoney(all.map((row) => row.value)),
    };
  };

  const groupSide = (section: 'assets' | 'liabilities' | 'equity'): PositionGroup[] =>
    GROUPS_BY_SECTION[section].map((key) => build(section, key));

  const warningGroups: PositionGroup[] = [];
  function reconcile(section: 'assets' | 'liabilities', groups: PositionGroup[], serverTotal: string): PositionGroup[] {
    const sum = sumPositionMoney(groups.map((group) => group.subtotal));
    const missing = minus(serverTotal, sum);
    if (isPositionZero(missing)) return groups;
    const groupKey = EMPTY_KEYS[section];
    const target = groups.find((group) => group.key === groupKey);
    if (target) {
      const corrected = {
        ...target,
        rows: [...target.rows, {
          code: 'REVISION_ESF', label: 'Saldo no agrupado por rubros (revisar)',
          value: missing, accountIds: [], note: null as null, requiresReview: true,
        }],
        subtotal: sumPositionMoney([target.subtotal, missing]),
      };
      warningGroups.push(corrected);
      return groups.map((group) => group.key === groupKey ? corrected : group);
    }
    return groups;
  }

  const assets = reconcile('assets', groupSide('assets'), data.totals.assets);
  const liabilities = reconcile('liabilities', groupSide('liabilities'), data.totals.liabilities);
  const originalEquity = build('equity','PATRIMONIO');

  // Period profit is not necessarily the entire unclosed result when earlier
  // exercises were not closed. Preserve both instead of silently relabeling.
  const resultPrior = minus(data.totals.result, data.income_statement.net);
  const rows: PositionLine[] = [...originalEquity.rows];
  if (!isPositionZero(resultPrior)) {
    rows.push({
      code: 'UN-CLOSED-PRIOR', label: 'Resultados anteriores sin cierre (revisar)',
      value: resultPrior, accountIds: [], note: null, requiresReview: true,
    });
  }
  rows.push({
    code: 'CURRENT-RESULT', label: 'Utilidad / pérdida del ejercicio (sin cierre)',
    value: data.income_statement.net, accountIds: [], note: null,
  });
  const equityGroup: PositionGroup = {
    ...originalEquity, rows,
    subtotal: sumPositionMoney(rows.map((row) => row.value)),
  };

  const warnings = [
    'Estado preliminar: faltan notas explicativas NIIF, cifras comparativas y validación del cierre contable.',
    'Los encabezados corriente/no corriente siguen el rubro ESF de cada cuenta. Comprobar vencimientos y reclasificaciones antes de emitir estados definitivos.',
    ...(data.unmapped_account_ids.length ? [
      data.unmapped_account_ids.length + ' cuenta(s) sin rubro financiero válido requieren revisión.',
    ] : []),
    ...(warningGroups.length || [...assets, ...liabilities, equityGroup].some((g) => g.rows.some((row) => row.requiresReview))
      ? ['Existen importes o rubros pendientes de revisión de presentación.'] : []),
    ...(!isPositionZero(data.totals.difference)
      ? ['Activo y pasivo más patrimonio no coinciden; revisar conciliación.'] : []),
  ];
  return {
    assetGroups: assets, liabilityGroups: liabilities, equityGroup,
    assetTotal: data.totals.assets,
    liabilityTotal: data.totals.liabilities,
    equityTotal: sumPositionMoney([data.totals.equity, data.totals.result]),
    liabilityAndEquityTotal: data.totals.liabilities_equity_result,
    difference: data.totals.difference,
    warnings,
  };
}
