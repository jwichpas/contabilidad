import assert from 'node:assert/strict';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

test('company changes visibly before the server responds and rejects stale or failed switches', async (t) => {
  const originalStorage = globalThis.sessionStorage;
  const originalFetch = globalThis.fetch;
  const data = new Map([
    ['erp_auth_token_iso27001', 'test-bearer'],
    ['erp_auth_user_iso27001', JSON.stringify({ id: 1, current_company_id: 101 })],
    ['erp_auth_companies_iso27001', JSON.stringify([
      { id: 101, business_name: 'DEMO A' }, { id: 102, business_name: 'DEMO B' },
    ])],
  ]);
  globalThis.sessionStorage = {
    getItem: (key) => data.get(key) ?? null,
    setItem: (key, value) => data.set(key, value),
    removeItem: (key) => data.delete(key),
  };
  const root = fileURLToPath(new URL('..', import.meta.url));
  const server = await createServer({
    root,
    configFile: false,
    optimizeDeps: { noDiscovery: true, include: [] },
    resolve: { alias: { '@': fileURLToPath(new URL('../src', import.meta.url)) } },
    server: { middlewareMode: true },
    appType: 'custom',
  });
  t.after(async () => {
    await server.close();
    globalThis.sessionStorage = originalStorage;
    globalThis.fetch = originalFetch;
  });

  const { useAuthSession } = await server.ssrLoadModule('/src/shared/auth/useAuthSession.ts');
  const { AccountingApiClient } = await server.ssrLoadModule('/src/modules/accounting/api/httpClient.ts');
  const session = useAuthSession();
  let resolveRequest;
  const requests = [];
  globalThis.fetch = async (url, options) => {
    requests.push({ url, options });
    return new Promise((resolve) => { resolveRequest = resolve; });
  };

  const client = new AccountingApiClient('', {
    getAccessToken: () => session.accessToken.value,
    getActiveCompanyId: () => session.activeCompanyId.value,
  });
  const priorRequest = client.fetchEntities();
  const resolvePriorRequest = resolveRequest;
  assert.equal(requests[0].options.headers.get('X-Company-Id'), '101');

  const first = session.switchCompany(102);
  assert.equal(session.activeCompanyId.value, 102);
  assert.equal(session.isSwitchingCompany.value, true);
  assert.equal(JSON.parse(data.get('erp_auth_user_iso27001')).current_company_id, 101);
  assert.equal(requests[1].url, '/api/v1/tenant/switch-company');
  assert.equal(requests[1].options.headers['X-Company-Id'], '102');
  assert.equal(requests[0].options.headers.get('X-Company-Id'), '101', 'in-flight requests keep their original tenant');
  assert.equal(await session.switchCompany(101), false, 'a second switch cannot race the first');
  assert.equal(requests.length, 2);
  resolvePriorRequest({ ok: true, status: 200, json: async () => ({ data: [] }) });
  await priorRequest;
  resolveRequest({ ok: true, status: 200 });
  assert.equal(await first, true);
  assert.equal(session.isSwitchingCompany.value, false);
  assert.equal(JSON.parse(data.get('erp_auth_user_iso27001')).current_company_id, 102);

  const rejected = session.switchCompany(101);
  assert.equal(session.activeCompanyId.value, 101);
  assert.equal(session.isSwitchingCompany.value, true);
  resolveRequest({ ok: false, status: 403 });
  assert.equal(await rejected, false);
  assert.equal(session.activeCompanyId.value, 102);
  assert.equal(session.isSwitchingCompany.value, false);
  assert.match(session.companySwitchError.value, /No fue posible conmutar/);
  assert.equal(JSON.parse(data.get('erp_auth_user_iso27001')).current_company_id, 102);

  const late = session.switchCompany(101);
  const resolveLate = resolveRequest;
  const signedOut = session.logout();
  assert.equal(session.accessToken.value, null);
  assert.equal(session.isSwitchingCompany.value, false);
  const resolveLogout = resolveRequest;
  resolveLate({ ok: true, status: 200 });
  assert.equal(await late, false, 'a response after logout cannot restore a tenant');
  assert.equal(session.activeCompanyId.value, 1);
  resolveLogout({ ok: true, status: 200 });
  await signedOut;
});
