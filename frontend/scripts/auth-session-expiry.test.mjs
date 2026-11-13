import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import {
  isCurrentBearer,
  reportUnauthorizedBearer,
  setUnauthorizedHandler,
} from '../src/shared/auth/sessionInvalidation.ts';

test('only invalidates for a response belonging to the current bearer', () => {
  assert.equal(isCurrentBearer('current-token', 'current-token'), true);
  assert.equal(isCurrentBearer('new-token', 'old-token'), false);
  assert.equal(isCurrentBearer(null, 'old-token'), false);
  assert.equal(isCurrentBearer('current-token', ''), false);
});

test('401 notifications ignore missing tokens and never print credentials', () => {
  const seen = [];
  setUnauthorizedHandler((token) => seen.push(token));
  try {
    reportUnauthorizedBearer(null);
    reportUnauthorizedBearer('');
    reportUnauthorizedBearer('   ');
    assert.deepEqual(seen, []);

    reportUnauthorizedBearer('synthetic-token-for-test');
    assert.deepEqual(seen, ['synthetic-token-for-test']);
  } finally {
    setUnauthorizedHandler(null);
  }
});

test('unregistered listener safely ignores late unauthorized responses', () => {
  setUnauthorizedHandler(null);
  assert.doesNotThrow(() => reportUnauthorizedBearer('old-session-token'));
});

test('login form starts without an embedded password or demo credential autofill', () => {
  const login = readFileSync(new URL('../src/shared/auth/LoginView.vue', import.meta.url), 'utf8');
  assert.match(login, /const password = ref\(''\)/);
  assert.match(login, /const email = ref\(''\)/);
  assert.doesNotMatch(login, /fillDemoCredentials/);
});

test('the accounting API reports 401 and App verifies restored Bearers before showing data', () => {
  const client = readFileSync(new URL('../src/modules/accounting/api/httpClient.ts', import.meta.url), 'utf8');
  const app = readFileSync(new URL('../src/App.vue', import.meta.url), 'utf8');
  assert.match(client, /response\.status === 401/);
  assert.match(client, /reportUnauthorizedBearer\(requestToken\)/);
  assert.match(app, /v-if="!isSessionVerifiedOnLoad \|\| sessionVerification === 'verifying'"/);
});
