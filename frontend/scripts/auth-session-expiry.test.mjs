import assert from 'node:assert/strict';
import test from 'node:test';
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
