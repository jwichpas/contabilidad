import test from 'node:test';
import assert from 'node:assert/strict';
import { costCenterPayload } from '../src/modules/company/utils/costCenterDraft.ts';

const valid = { code: 'CC-01', name: 'Ventas', destination_debit_account_id: 12, destination_credit_account_id: 13 };
test('cost center payload normalizes code and excludes client-controlled tenant and state', () => {
  const draft = { ...valid, code: ' cc-01 ', name: ' Ventas ', company_id: 99, is_active: false, id: 1 };
  assert.deepEqual(costCenterPayload(draft), valid);
  assert.equal(draft.code, ' cc-01 ');
});
test('cost center requires distinct positive safe account identifiers', () => {
  for (const id of [0, -1, 1.5, NaN, Infinity, Number.MAX_SAFE_INTEGER + 1, 13]) {
    assert.throws(() => costCenterPayload({ ...valid, destination_debit_account_id: id }));
  }
});
test('cost center validates code and name boundaries before sending a request', () => {
  for (const code of ['', ' ', 'A B', '<script>', 'A'.repeat(21)]) assert.throws(() => costCenterPayload({ ...valid, code }));
  for (const name of ['', '  ', 'A'.repeat(151)]) assert.throws(() => costCenterPayload({ ...valid, name }));
  assert.equal(costCenterPayload({ ...valid, code: 'A'.repeat(20), name: 'N'.repeat(150) }).name.length, 150);
});
