import assert from 'node:assert/strict';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';

const tokenKey = 'erp_auth_token_iso27001';
const userKey = 'erp_auth_user_iso27001';
const companiesKey = 'erp_auth_companies_iso27001';
const companies = [{ id: 101, business_name: 'Empresa de prueba' }];
const ok = (data) => ({ ok: true, status: 200, json: async () => ({ data }) });
const rejected = (status) => ({ ok: false, status });
const loginData = { token: 'replacement-token', user: { id: 2, current_company_id: 101 }, companies };

async function setup(t) {
  const originalStorage = globalThis.sessionStorage;
  const originalFetch = globalThis.fetch;
  const storage = new Map([
    [tokenKey, 'restored-token'],
    [userKey, JSON.stringify({ id: 1, current_company_id: 101 })],
    [companiesKey, JSON.stringify(companies)],
  ]);
  globalThis.sessionStorage = {
    getItem: (key) => storage.get(key) ?? null,
    setItem: (key, value) => storage.set(key, value),
    removeItem: (key) => storage.delete(key),
  };
  const server = await createServer({
    root: fileURLToPath(new URL('..', import.meta.url)), configFile: false,
    optimizeDeps: { noDiscovery: true, include: [] },
    resolve: { alias: { '@': fileURLToPath(new URL('../src', import.meta.url)) } },
    server: { middlewareMode: true, watch: null, hmr: false }, appType: 'custom',
  });
  t.after(async () => {
    await server.close();
    globalThis.sessionStorage = originalStorage;
    globalThis.fetch = originalFetch;
  });
  const { useAuthSession } = await server.ssrLoadModule('/src/shared/auth/useAuthSession.ts');
  return { session: useAuthSession(), storage };
}

test('a restored bearer grants no authenticated state until server verification succeeds', async (t) => {
  const { session } = await setup(t);
  assert.equal(session.isAuthenticated.value, false);
  assert.equal(session.sessionVerification.value, 'unverified');
  let complete;
  globalThis.fetch = async (url, options) => {
    assert.equal(url, '/api/v1/tenant/companies');
    assert.equal(options.headers.Authorization, 'Bearer restored-token');
    assert.ok(options.signal instanceof AbortSignal);
    return new Promise((resolve) => { complete = resolve; });
  };
  const verifying = session.ensureAuthenticated();
  assert.equal(session.sessionVerification.value, 'verifying');
  assert.equal(session.isAuthenticated.value, false);
  complete(ok(companies));
  assert.equal(await verifying, true);
  assert.equal(session.sessionVerification.value, 'verified');
});

for (const failure of ['network', 403, 500, 'invalid-json']) {
  test(`restore failure (${failure}) hides private state, retains bearer and permits retry`, async (t) => {
    const { session, storage } = await setup(t);
    globalThis.fetch = async () => {
      if (failure === 'network') throw new TypeError('Offline');
      if (failure === 'invalid-json') return { ok: true, json: async () => { throw new SyntaxError(); } };
      return rejected(failure);
    };
    assert.equal(await session.ensureAuthenticated(), false);
    assert.equal(session.isAuthenticated.value, false);
    assert.equal(session.sessionVerification.value, 'unavailable');
    assert.equal(storage.get(tokenKey), 'restored-token');
    globalThis.fetch = async () => ok(companies);
    assert.equal(await session.ensureAuthenticated(), true);
    assert.equal(session.sessionVerification.value, 'verified');
  });
}

test('401 on restore clears the credential and returns to login', async (t) => {
  const { session, storage } = await setup(t);
  globalThis.fetch = async () => rejected(401);
  assert.equal(await session.ensureAuthenticated(), false);
  assert.equal(session.sessionVerification.value, 'anonymous');
  assert.equal(session.accessToken.value, null);
  assert.equal(storage.has(tokenKey), false);
  assert.match(session.sessionNotice.value, /venció o fue revocada/);
});

for (const status of [200, 401]) {
  test(`logout clears local data immediately and confirms an invalid remote bearer (${status})`, async (t) => {
    const { session, storage } = await setup(t);
    let complete;
    globalThis.fetch = async (url, options) => {
      assert.equal(url, '/api/v1/auth/logout');
      assert.equal(options.headers.Authorization, 'Bearer restored-token');
      assert.ok(options.signal instanceof AbortSignal);
      return new Promise((resolve) => { complete = resolve; });
    };
    const loggingOut = session.logout();
    assert.equal(session.accessToken.value, null);
    assert.equal(session.currentUser.value, null);
    assert.deepEqual(session.userCompanies.value, []);
    for (const key of [tokenKey, userKey, companiesKey]) assert.equal(storage.has(key), false);
    assert.equal(session.logoutStatus.value, 'pending');
    complete(status === 200 ? ok() : rejected(status));
    await loggingOut;
    assert.equal(session.logoutStatus.value, 'revoked');
    assert.match(session.sessionNotice.value, /ya no permite acceder/);
  });
}

for (const failure of ['network', 403, 500, 'timeout']) {
  test(`logout failure (${failure}) reports unconfirmed revocation without restoring local data`, async (t) => {
    const { session, storage } = await setup(t);
    globalThis.fetch = async () => {
      if (failure === 'network') throw new TypeError('Offline');
      if (failure === 'timeout') throw new DOMException('Timed out', 'TimeoutError');
      return rejected(failure);
    };
    await session.logout();
    assert.equal(session.logoutStatus.value, 'unconfirmed');
    assert.match(session.sessionNotice.value, /podría seguir activa hasta su vencimiento/);
    assert.equal(session.accessToken.value, null);
    assert.equal(storage.has(tokenKey), false);
  });
}

test('a late failed logout cannot overwrite a replacement login', async (t) => {
  const { session, storage } = await setup(t);
  let finishLogout;
  globalThis.fetch = async (url) => url.endsWith('/logout')
    ? new Promise((resolve) => { finishLogout = resolve; }) : ok(loginData);
  const loggingOut = session.logout();
  assert.equal(await session.login('user@example.test', 'synthetic-password'), true);
  finishLogout(rejected(500));
  await loggingOut;
  assert.equal(session.accessToken.value, loginData.token);
  assert.equal(storage.get(tokenKey), loginData.token);
  assert.equal(session.isAuthenticated.value, true);
  assert.equal(session.logoutStatus.value, 'idle');
  assert.equal(session.sessionNotice.value, null);
});

test('late login success after logout cannot resurrect the session', async (t) => {
  const { session, storage } = await setup(t);
  let finishLogin;
  globalThis.fetch = async (url) => url.endsWith('/login')
    ? new Promise((resolve) => { finishLogin = resolve; }) : ok();
  const loggingIn = session.login('user@example.test', 'synthetic-password');
  await session.logout();
  finishLogin(ok(loginData));
  assert.equal(await loggingIn, false);
  assert.equal(session.isAuthenticated.value, false);
  assert.equal(storage.has(tokenKey), false);
});

test('an older verification failure cannot replace a newer verification success', async (t) => {
  const { session } = await setup(t);
  let failEarlier;
  globalThis.fetch = async () => new Promise((_, reject) => { failEarlier = reject; });
  const earlier = session.ensureAuthenticated();
  globalThis.fetch = async () => ok(companies);
  assert.equal(await session.ensureAuthenticated(), true);
  failEarlier(new TypeError('Old request failed'));
  await earlier;
  assert.equal(session.sessionVerification.value, 'verified');
  assert.equal(session.isLiveConnected.value, true);
});
