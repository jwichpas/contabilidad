import { SystemDecimal } from '../../../shared/types/numeric.types.ts';

export interface ConvertedJournalLine {
  debitLocal: string;
  creditLocal: string;
  debitForeign: string;
  creditForeign: string;
}

export interface ForeignRoundingAdjustment {
  lineIndex: number;
  side: 'debit' | 'credit';
  amount: string;
}

/** Place the residual of per-line FX rounding on the largest converted line of the opposite side. */
export function reconcileForeignRounding<T extends ConvertedJournalLine>(
  lines: readonly T[],
): { values: Array<{ debitForeign: string; creditForeign: string }>; adjustment: ForeignRoundingAdjustment | null } {
  const values = lines.map(line => ({ debitForeign: line.debitForeign, creditForeign: line.creditForeign }));
  const zero = SystemDecimal.zero();
  const parse = (raw: string): SystemDecimal => SystemDecimal.parseMonetary(raw.trim() || '0');
  try {
    const debitLocal = lines.reduce((sum, line) => sum.add(parse(line.debitLocal)), zero);
    const creditLocal = lines.reduce((sum, line) => sum.add(parse(line.creditLocal)), zero);
    if (!debitLocal.isPositive() || !debitLocal.equals(creditLocal)) return { values, adjustment: null };

    const debitForeign = values.reduce((sum, line) => sum.add(parse(line.debitForeign)), zero);
    const creditForeign = values.reduce((sum, line) => sum.add(parse(line.creditForeign)), zero);
    const difference = debitForeign.subtract(creditForeign);
    if (difference.isZero()) return { values, adjustment: null };

    const side = difference.isPositive() ? 'credit' : 'debit';
    const localKey = side === 'credit' ? 'creditLocal' : 'debitLocal';
    const foreignKey = side === 'credit' ? 'creditForeign' : 'debitForeign';
    let targetIndex = -1;
    let largest = zero;
    lines.forEach((line, index) => {
      const amount = parse(values[index]![foreignKey]);
      if (parse(line[localKey]).isPositive() && amount.isPositive() && amount.subtract(largest).isPositive()) {
        largest = amount;
        targetIndex = index;
      }
    });
    // A zero converted amount needs an explicit correction, not a hidden adjustment.
    if (targetIndex < 0) return { values, adjustment: null };
    const amount = difference.abs();
    values[targetIndex]![foreignKey] = largest.add(amount).toString();
    return { values, adjustment: { lineIndex: targetIndex, side, amount: amount.toString() } };
  } catch {
    // Partial input while typing is validated before POST; leave its amounts untouched.
    return { values, adjustment: null };
  }
}
