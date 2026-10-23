import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
const read = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const app = read('../src/App.vue');
const nav = read('../src/shared/layout/navigation.types.ts');
const sidebar = read('../src/shared/layout/AppSidebar.vue');
const client = read('../src/modules/accounting/api/httpClient.ts');
const view = read('../src/modules/company/views/CompanyFiscalHistoryView.vue');
const types = read('../src/modules/company/types/companyFiscalProfile.types.ts');

test('fiscal history is routed under global company selection, without a local company picker', () => {
  assert.match(nav, /'fiscal-history'/);
  assert.match(app, /currentModule === 'fiscal-history'/);
  assert.match(app, /:can-write="canDeactivateEntities"/);
  assert.match(app, /window.location.hash = '#historial-fiscal'/);
  assert.match(sidebar, /emit\('navigate', 'fiscal-history'\)/);
  assert.doesNotMatch(view, /tenant-selector/);
});
test('client uses existing authenticated tenant-scoped transport and immutable DTO', () => {
  assert.match(client, /async fetchCompanyFiscalProfiles\(/);
  assert.match(client, /async createCompanyFiscalProfile\(/);
  assert.match(client, /this.buildHeaders\(requireTenantHeader, requestToken\)/);
  for (const field of ['company_id', 'revision', 'created_by', 'id']) {
    assert.doesNotMatch(types.slice(types.indexOf('export interface CreateCompanyFiscalProfilePayload')), new RegExp('readonly ' + field + ':'));
  }
});
test('view requires explicit confirmation and shows no update/delete history action', () => {
  assert.match(view, /confirmed.value/);
  assert.match(view, /change_reason.trim\(\).length < 10/);
  assert.match(view, /form.tax_regime === 'NRUS'/);
  assert.match(view, /:disabled="saving \|\| !confirmed"/);
  assert.doesNotMatch(view, /updateCompanyFiscalProfile|deleteCompanyFiscalProfile/);
});
