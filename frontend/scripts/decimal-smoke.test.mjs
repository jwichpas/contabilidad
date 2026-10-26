import assert from 'node:assert/strict';
import test from 'node:test';
import { SystemDecimal } from '../src/shared/types/numeric.types.ts';
import { reconcileForeignRounding, reconcileLocalRounding } from '../src/modules/accounting/utils/journalForeignRounding.ts';

test('0.1 + 0.2 equals 0.3 exactly', () => {
  assert.equal(
    SystemDecimal.parseMonetary('0.1').add(SystemDecimal.parseMonetary('0.2')).toString(),
    '0.3000',
  );
});

test('converts PEN into foreign currency without IEEE-754 floats', () => {
  assert.equal(
    SystemDecimal.parseMonetary('100.0000')
      .divideBy(SystemDecimal.parseExchangeRate('4.000000')).toString(),
    '25.0000',
  );
  assert.equal(
    SystemDecimal.parseMonetary('1.0000')
      .divideBy(SystemDecimal.parseExchangeRate('3.000000')).toString(),
    '0.3333',
  );
});

test('half-up rounds positive and negative exchange conversions', () => {
  assert.equal(
    SystemDecimal.parseMonetary('1.0000')
      .divideBy(SystemDecimal.parseExchangeRate('32.000000')).toString(),
    '0.0313',
  );
  assert.equal(
    SystemDecimal.parseMonetary('-1.0000')
      .divideBy(SystemDecimal.parseExchangeRate('32.000000')).toString(),
    '-0.0313',
  );
});

test('converts entered USD into PEN exactly with the selected exchange rate', () => {
  const rate = SystemDecimal.parseExchangeRate('3.758000');
  assert.equal(SystemDecimal.parseMonetary('100.0000').multiplyBy(rate).toString(), '375.8000');
  assert.equal(SystemDecimal.parseMonetary('0.0001').multiplyBy(rate).toString(), '0.0004');
  assert.equal(SystemDecimal.parseMonetary('-0.0001').multiplyBy(rate).toString(), '-0.0004');
});

test('reconciles the PEN residual without changing entered USD', () => {
  const lines = [
    { debitLocal: '0.0003', creditLocal: '', debitForeign: '0.0001', creditForeign: '' },
    { debitLocal: '0.0003', creditLocal: '', debitForeign: '0.0001', creditForeign: '' },
    { debitLocal: '', creditLocal: '0.0007', debitForeign: '', creditForeign: '0.0002' },
  ];
  const result = reconcileLocalRounding(lines);
  assert.deepEqual(result.adjustment, { lineIndex: 0, side: 'debit', amount: '0.0001' });
  assert.equal(result.values[0].debitLocal, '0.0004');
  assert.equal(lines[0].debitLocal, '0.0003');
});

test('rejects zero exchange rates and excessive decimal places', () => {
  assert.throws(
    () => SystemDecimal.parseMonetary('10.0000').divideBy(SystemDecimal.parseExchangeRate('0')),
    RangeError,
  );
  assert.throws(() => SystemDecimal.parseMonetary('1.12345'), RangeError);
  assert.throws(() => SystemDecimal.parseExchangeRate('3.1234567'), RangeError);
});

test('checks that balances agree exactly to all four monetary decimals', () => {
  const debit = SystemDecimal.parseMonetary('1.0000');
  const credit = SystemDecimal.parseMonetary('0.9999');
  assert.equal(debit.subtract(credit).toString(), '0.0001');
  assert.equal(debit.subtract(credit).isZero(), false);
});

test('assigns a 0.0001 FX rounding residual to a positive line without changing local amounts', () => {
  const lines = [
    { debitLocal: '0.0100', creditLocal: '', debitForeign: '0.0027', creditForeign: '' },
    { debitLocal: '0.0100', creditLocal: '', debitForeign: '0.0027', creditForeign: '' },
    { debitLocal: '', creditLocal: '0.0200', debitForeign: '', creditForeign: '0.0053' },
  ];
  const result = reconcileForeignRounding(lines);
  assert.deepEqual(result.adjustment, { lineIndex: 2, side: 'credit', amount: '0.0001' });
  assert.equal(result.values[2].creditForeign, '0.0054');
  assert.equal(lines[2].creditForeign, '0.0053');
});

test('does not reconcile an unbalanced local entry or invent a positive foreign amount', () => {
  const unbalanced = [
    { debitLocal: '0.0100', creditLocal: '', debitForeign: '0.0027', creditForeign: '' },
    { debitLocal: '', creditLocal: '0.0200', debitForeign: '', creditForeign: '0.0053' },
  ];
  assert.equal(reconcileForeignRounding(unbalanced).adjustment, null);
  const underflow = [
    { debitLocal: '0.0001', creditLocal: '', debitForeign: '0.0000', creditForeign: '' },
    { debitLocal: '', creditLocal: '0.0001', debitForeign: '', creditForeign: '0.0000' },
  ];
  assert.equal(reconcileForeignRounding(underflow).adjustment, null);
});

test('reconciles a credit-heavy rounding residual on a debit line', () => {
  const result = reconcileForeignRounding([
    { debitLocal: '0.0200', creditLocal: '', debitForeign: '0.0053', creditForeign: '' },
    { debitLocal: '', creditLocal: '0.0100', debitForeign: '', creditForeign: '0.0027' },
    { debitLocal: '', creditLocal: '0.0100', debitForeign: '', creditForeign: '0.0027' },
  ]);
  assert.deepEqual(result.adjustment, { lineIndex: 0, side: 'debit', amount: '0.0001' });
  assert.equal(result.values[0].debitForeign, '0.0054');
});
