import assert from 'node:assert/strict';
import test from 'node:test';
import { formatExactLocalAmount } from '../src/modules/accounting/utils/formatExactLocalAmount.ts';

test('displays functional PEN, USD and CLP without changing monetary values', () => {
  assert.equal(formatExactLocalAmount('19838.5000', 'PEN'), 'PEN 19.838,5000');
  assert.equal(formatExactLocalAmount('-1332.3900', 'USD'), '−USD 1.332,3900');
  assert.equal(formatExactLocalAmount('0.0001', 'CLP'), 'CLP 0,0001');
});

test('never converts huge exact decimal strings into floating point', () => {
  assert.equal(
    formatExactLocalAmount('9007199254740993.0001', 'PEN'),
    'PEN 9.007.199.254.740.993,0001',
  );
});

test('handles negative zero and uses database amount unchanged for invalid input', () => {
  assert.equal(formatExactLocalAmount('-0.0000', 'PEN'), 'PEN 0,0000');
  assert.equal(formatExactLocalAmount('10.12345', 'PEN'), 'PEN 10.12345');
});
