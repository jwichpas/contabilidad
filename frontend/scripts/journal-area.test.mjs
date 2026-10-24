import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cashFlowMatches } from '../src/modules/accounting/utils/journalArea.ts';

test('cash flow requirement rejects missing and inactive areas', () => {
  const rule = { requires_cash_flow_area: true, cash_flow_category: 'OPERATING' };
  assert.equal(cashFlowMatches(rule, null), false);
  assert.equal(cashFlowMatches(rule, { is_active: false, activity_category: 'OPERATING' }), false);
  assert.equal(cashFlowMatches({ ...rule, requires_cash_flow_area: false }, null), true);
});
test('specific activity requires its matching area and unknown categories fail closed', () => {
  for (const category of ['OPERATING', 'INVESTING', 'FINANCING']) {
    const rule = { requires_cash_flow_area: true, cash_flow_category: category };
    for (const other of ['OPERATING', 'INVESTING', 'FINANCING', 'CASH_EQUIVALENT']) {
      assert.equal(cashFlowMatches(rule, { is_active: true, activity_category: other }), category === other);
    }
  }
  assert.equal(cashFlowMatches({ requires_cash_flow_area: false, cash_flow_category: 'UNKNOWN' }, null), false);
});
test('cash equivalent accounts can classify each activity and optional NONE still validates areas', () => {
  for (const category of ['NONE', 'CASH_EQUIVALENT']) {
    for (const activity of ['OPERATING', 'INVESTING', 'FINANCING', 'CASH_EQUIVALENT']) {
      assert.equal(cashFlowMatches({ requires_cash_flow_area: false, cash_flow_category: category },
        { is_active: true, activity_category: activity }), true);
    }
  }
  assert.equal(cashFlowMatches({ requires_cash_flow_area: false, cash_flow_category: 'NONE' },
    { is_active: true, activity_category: 'UNKNOWN' }), false);
});
