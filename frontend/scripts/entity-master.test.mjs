import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const app = read('../src/App.vue');
const nav = read('../src/shared/layout/navigation.types.ts');
const sidebar = read('../src/shared/layout/AppSidebar.vue');
const client = read('../src/modules/accounting/api/httpClient.ts');
const view = read('../src/modules/company/views/EntityMasterView.vue');
const types = read('../src/modules/company/types/entityMaster.types.ts');

test('entities module is mounted and scoped to global tenant selector', () => {
  assert.match(nav, /'entities'/);
  assert.match(app, /currentModule === 'entities'/);
  assert.match(app, /:key="`entities-\$\{activeCompanyId\}`"/);
  assert.match(app, /:can-write="canManageEntities"/);
  assert.match(app, /:can-deactivate="canDeactivateEntities"/);
  assert.match(sidebar, /emit\('navigate', 'entities'\)/);
  assert.match(app, /window\.location\.hash = '#anexos'/);
});

test('tenant-safe API uses existing Bearer/active-company transport', () => {
  for (const method of ['fetchEntities','createEntity','updateEntity','deactivateEntity']) {
    assert.match(client, new RegExp('async ' + method + '\\('));
  }
  assert.match(client, /this\.buildHeaders\(requireTenantHeader, requestToken\)/);
  assert.match(client, /headers\.set\('X-Company-Id', String\(activeCompanyId\)\)/);
  assert.doesNotMatch(types, /readonly bank_account_number/);
  assert.doesNotMatch(types, /readonly bank_cci_number/);
  assert.match(view, /doc\.description/);
});

test('frontend handles pagination, 422 and role-based deactivation without tenant picker duplication', () => {
  assert.match(view, /changePage\(page \+ 1\)/);
  assert.match(view, /ApiHttpError && error\.status === 422/);
  assert.match(view, /v-if="canDeactivate"/);
  assert.match(view, /watch\(\(\) => props\.activeCompanyId/);
  assert.doesNotMatch(view, /tenant-selector/);
  assert.doesNotMatch(view, /bank_cci_number/);
});
