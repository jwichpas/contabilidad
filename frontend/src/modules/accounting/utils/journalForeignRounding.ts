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
  const result = reconcileConvertedRounding(lines, 'Local', 'Foreign');
  return { values: result.values.map(line => ({ debitForeign: line.debitForeign, creditForeign: line.creditForeign })), adjustment: result.adjustment };
}

/** When USD is entered, preserve its amounts and balance the converted PEN residual. */
export function reconcileLocalRounding<T extends ConvertedJournalLine>(
  lines: readonly T[],
): { values: Array<{ debitLocal: string; creditLocal: string }>; adjustment: ForeignRoundingAdjustment | null } {
  const result = reconcileConvertedRounding(lines, 'Foreign', 'Local');
  return { values: result.values.map(line => ({ debitLocal: line.debitLocal, creditLocal: line.creditLocal })), adjustment: result.adjustment };
}

function reconcileConvertedRounding<T extends ConvertedJournalLine>(
  lines: readonly T[], source: 'Local' | 'Foreign', target: 'Local' | 'Foreign',
): { values: ConvertedJournalLine[]; adjustment: ForeignRoundingAdjustment | null } {
  const values: ConvertedJournalLine[] = lines.map(line => ({
    debitLocal: line.debitLocal, creditLocal: line.creditLocal,
    debitForeign: line.debitForeign, creditForeign: line.creditForeign,
  }));
  const zero = SystemDecimal.zero();
  const parse = (raw: string): SystemDecimal => SystemDecimal.parseMonetary(raw.trim() || '0');
  try {
    const debitSource = lines.reduce((sum, line) => sum.add(parse(line[`debit${source}`])), zero);
    const creditSource = lines.reduce((sum, line) => sum.add(parse(line[`credit${source}`])), zero);
    if (!debitSource.isPositive() || !debitSource.equals(creditSource)) return { values, adjustment: null };

    const debitTarget = values.reduce((sum, line) => sum.add(parse(line[`debit${target}`])), zero);
    const creditTarget = values.reduce((sum, line) => sum.add(parse(line[`credit${target}`])), zero);
    const difference = debitTarget.subtract(creditTarget);
    if (difference.isZero()) return { values, adjustment: null };

    const side = difference.isPositive() ? 'credit' : 'debit';
    const sourceKey = `${side}${source}` as keyof ConvertedJournalLine;
    const targetKey = `${side}${target}` as keyof ConvertedJournalLine;
    let targetIndex = -1;
    let largest = zero;
    lines.forEach((line, index) => {
      const amount = parse(values[index]![targetKey]);
      if (parse(line[sourceKey]).isPositive() && amount.isPositive() && amount.subtract(largest).isPositive()) {
        largest = amount;
        targetIndex = index;
      }
    });
    // A zero converted amount needs an explicit correction, not a hidden adjustment.
    if (targetIndex < 0) return { values, adjustment: null };
    const amount = difference.abs();
    values[targetIndex]![targetKey] = largest.add(amount).toString();
    return { values, adjustment: { lineIndex: targetIndex, side, amount: amount.toString() } };
  } catch {
    // Partial input while typing is validated before POST; leave its amounts untouched.
    return { values, adjustment: null };
  }
}
