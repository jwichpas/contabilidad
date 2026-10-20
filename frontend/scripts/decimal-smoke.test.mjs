import assert from 'node:assert/strict';
import test from 'node:test';
import { SystemDecimal } from '../src/shared/types/numeric.types.ts';

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
