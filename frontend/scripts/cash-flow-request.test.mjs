import assert from 'node:assert/strict';
import { after, before, test } from 'node:test';
import { createServer } from 'vite';
import { effectScope, shallowRef } from 'vue';

let server;
let useCashFlowReport;
const originalFetch = globalThis.fetch;
before(async () => {
  server = await createServer({ server: { middlewareMode: true }, appType: 'custom', optimizeDeps: { noDiscovery: true, include: [] } });
  ({ useCashFlowReport } = await server.ssrLoadModule('/src/modules/accounting/composables/useCashFlowReport.ts'));
});
after(async () => { globalThis.fetch = originalFetch; await server?.close(); });
const response = (data, status = 200) => new Response(JSON.stringify({ data }), { status, headers: { 'content-type': 'application/json' } });

test('cash-flow requests use Bearer/tenant headers and ignore a late previous-company response', async () => {
  let resolveFirst;
  const requests = [];
  globalThis.fetch = async (url, options) => {
    requests.push({ url, options });
    if (requests.length === 1) return new Promise((resolve) => { resolveFirst = resolve; });
    return response({ currency: 'USD', totals: { closing_local: '25.0001' } });
  };
  const company = shallowRef(1);
  const scope = effectScope();
  const state = scope.run(() => useCashFlowReport(() => company.value, () => 'fixture-only-token'));
  const first = state.load({ from: '2026-01-01', to: '2026-02-28', section: 'OPERATING', area_id: 10, page: 2 });
  assert.equal(state.pending.value, true);
  assert.equal(requests[0].options.headers.get('X-Company-Id'), '1');
  assert.equal(requests[0].options.headers.get('Authorization'), 'Bearer fixture-only-token');
  const query = new URL(requests[0].url, 'http://localhost').searchParams;
  assert.equal(query.get('area_id'), '10');
  assert.equal(query.get('page'), '2');
  company.value = 2;
  assert.equal(state.report.value, null);
  assert.equal(state.pending.value, false);
  await state.load({ from: '2026-01-01', to: '2026-02-28' });
  resolveFirst(response({ currency: 'PEN', totals: { closing_local: '999.0000' } }));
  await first;
  assert.equal(requests[1].options.headers.get('X-Company-Id'), '2');
  assert.equal(state.report.value.currency, 'USD');
  assert.equal(state.report.value.totals.closing_local, '25.0001');
  scope.stop();
});

test('disposing a cash-flow view invalidates its pending request', async () => {
  let resolveRequest;
  globalThis.fetch = () => new Promise((resolve) => { resolveRequest = resolve; });
  const scope = effectScope();
  const state = scope.run(() => useCashFlowReport(() => 1, () => null));
  const request = state.load({ from: '2026-01-01', to: '2026-02-28' });
  scope.stop();
  resolveRequest(response({ currency: 'PEN' }));
  await request;
  assert.equal(state.report.value, null);
  assert.equal(state.pending.value, false);
});

test('cash-flow API failures are visible and a retry clears the error', async () => {
  let fail = true;
  globalThis.fetch = async () => fail
    ? new Response(JSON.stringify({ message: 'Configure la moneda funcional.' }), { status: 409 })
    : response({ currency: 'USD' });
  const scope = effectScope();
  const state = scope.run(() => useCashFlowReport(() => 1, () => null));
  const query = { from: '2026-01-01', to: '2026-02-28' };
  await state.load(query);
  assert.match(state.error.value, /moneda funcional/);
  assert.equal(state.report.value, null);
  fail = false;
  await state.load(query);
  assert.equal(state.error.value, null);
  assert.equal(state.report.value.currency, 'USD');
  scope.stop();
});
