/**
 * Monetary display only. Keeps all four decimal places as strings, avoiding
 * IEEE-754 precision loss on large accounting balances.
 *
 * The server controls both the ISO 4217 currency code and the numeric amount.
 * Formatting does not convert currencies or round the original amount.
 */
export function formatExactLocalAmount(value: string, currencyCode: string): string {
  const match = /^(-?)(\d+)(?:\.(\d{1,4}))?$/.exec(value);
  const code = currencyCode.trim().toUpperCase();
  if (!match) return `${code} ${value}`;

  const sign = match[1] === '-' && !/^0+$/.test(match[2] + (match[3] ?? '')) ? '−' : '';
  const whole = match[2].replace(/^0+(?=\d)/, '');
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  const decimals = (match[3] ?? '').padEnd(4, '0');

  return `${sign}${code} ${grouped},${decimals}`;
}
