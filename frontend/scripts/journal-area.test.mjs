import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cashFlowMatches } from '../src/modules/accounting/utils/journalArea.ts';
import { resetAccountDimensions } from '../src/modules/accounting/utils/journalLine.ts';
import { readFileSync } from 'node:fs';

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

test('switching journal account clears all four dimensions without changing amounts or description', () => {
  const line = {
    accountId: 11, costCenterId: 22, entity: { id: 33 },
    companyAreaId: 44, cashFlowAreaId: 55,
    description: 'Ficticio', debitLocal: '150.0000', creditLocal: '0.0000',
  };
  resetAccountDimensions(line);
  assert.equal(line.costCenterId, null);
  assert.equal(line.entity, null);
  assert.equal(line.companyAreaId, null);
  assert.equal(line.cashFlowAreaId, null);
  assert.equal(line.accountId, 11);
  assert.equal(line.description, 'Ficticio');
  assert.equal(line.debitLocal, '150.0000');
});

test('journal view wires account reset, conditional dimensions and compact exchange rates', () => {
  const view = readFileSync(new URL('../src/modules/accounting/views/JournalEntryView.vue', import.meta.url), 'utf8');
  assert.match(view, /@change="resetAccountDimensions\(line\)"/);
  assert.match(view, /:disabled="isSubmitting \|\| !line\.accountId"/);
  assert.match(view, /requires_cost_center \? 'Seleccionar centro obligatorio'/);
  assert.match(view, /JournalEntityPicker :key=/);
  assert.match(view, /T\.C\. diario ·/);
  assert.match(view, /updateRateForCurrency\(rateItem\.code, 'buy_rate'/);
  assert.match(view, /updateRateForCurrency\(rateItem\.code, 'sell_rate'/);
});
