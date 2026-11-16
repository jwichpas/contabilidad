import type { FinancialReportLayoutSettings } from '../types/financialLayout.types';

export const EMPTY_FINANCIAL_LAYOUT: FinancialReportLayoutSettings = Object.freeze({
  labels: {}, hidden_codes: [], line_order: [], hide_zero: false,
});

interface DisplayItem {readonly code: string; readonly label: string}

/**
 * A display-only permutation. No aggregations, classifications, amounts,
 * statements or account balances are modified. The slots of mandatory
 * subtotals and warning rows never move. Even a configured hide cannot
 * suppress a detail row with nonzero movements.
 */
export function applyFinancialReportLayout<T extends DisplayItem>(
  lines: readonly T[],
  settings: FinancialReportLayoutSettings | null | undefined,
  groupFor: (code: string) => string | null,
  editable: (code: string) => boolean,
  isZero: (line: T) => boolean,
): T[] {
  if (!settings) return [...lines];
  const order = new Map(settings.line_order.map((code,index) => [code,index]));
  const groups = new Map<string, number[]>();
  lines.forEach((line, index) => {
    const group = editable(line.code) ? groupFor(line.code) : null;
    if (group) groups.set(group, [...(groups.get(group) ?? []), index]);
  });
  const reordered = [...lines];
  for (const indices of groups.values()) {
    const items = indices.map((index) => lines[index]!);
    items.sort((a,b) => (order.get(a.code) ?? Number.MAX_SAFE_INTEGER)
      - (order.get(b.code) ?? Number.MAX_SAFE_INTEGER));
    indices.forEach((index,i) => { reordered[index] = items[i]!; });
  }
  return reordered.map((line) =>
    editable(line.code) && settings.labels[line.code]
      ? {...line, label: settings.labels[line.code]!} : line)
    .filter((line) => !(
      editable(line.code)
      && isZero(line)
      && (settings.hide_zero || settings.hidden_codes.includes(line.code))
    ));
}
