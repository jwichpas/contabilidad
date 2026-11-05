import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (name) => readFileSync(new URL(name, import.meta.url), 'utf8');
const accounts = read('../src/modules/accounting/composables/useAccountTree.ts');
const catalogs = read('../src/modules/accounting/composables/useAccountingCatalogs.ts');
const costs = read('../src/modules/company/views/CostCenterMasterView.vue');
const subdiaries = read('../src/modules/accounting/views/CompanySubdiariesView.vue');
const api = read('../src/modules/accounting/api/httpClient.ts');
const app = read('../src/App.vue');
const journal = read('../src/modules/accounting/views/JournalEntryView.vue');
const rates = read('../src/modules/accounting/views/ExchangeRatesView.vue');
const periods = read('../src/modules/accounting/views/FiscalPeriodsView.vue');

test('centros de costo: consulta por empresa, filtros y página con invalidación al guardar', () => {
  assert.match(costs, /queryKey: \[\.\.\.cachePrefix, params\.search, params\.status, params\.page\]/);
  assert.match(costs, /\['cost-center-master', companyId\]/);
  assert.match(costs, /getAccessToken: \(\) => token/);
  assert.match(costs, /getActiveCompanyId: \(\) => companyId/);
  assert.match(costs, /requestApi\.fetchCostCenterMaster\(\{ \.\.\.params, signal \}\)/);
  assert.match(costs, /token !== props\.getAccessToken\(\)/);
  assert.match(costs, /queryClient\.invalidateQueries/);
  assert.match(costs, /\['company-catalog', companyId, 'cost-centers'\]/);
  assert.match(costs, /\['operational-accounts', companyId\]/);
  assert.match(costs, /requestApi\.fetchOperationalAccounts\(signal\)/);
  assert.equal((costs.match(/await refresh\(\)/g) ?? []).length, 2);
  assert.match(costs, /@click="refresh"/);
});

test('subdiarios: filtra localmente y cachea por empresa, invalidando tras mutaciones', () => {
  assert.match(subdiaries, /records\.value\.filter\(/);
  assert.match(subdiaries, /queryKey: \['company-subdiaries', tenant, 'all'\]/);
  assert.match(subdiaries, /requestApi\.fetchCompanySubdiaries\(false, signal\)/);
  assert.match(subdiaries, /token === props\.getAccessToken\(\)/);
  assert.equal((subdiaries.match(/await refresh\(\)/g) ?? []).length, 2);
  assert.match(subdiaries, /@click="refresh"/);
});

test('edición PCGE: cachea referencias generales, pero separa catálogos por empresa', () => {
  assert.match(catalogs, /\['reference-catalog', 'currencies'\]/);
  assert.match(catalogs, /\['reference-catalog', 'financial-statement-rubrics'\]/);
  assert.match(catalogs, /\['reference-catalog', 'sunat-banks-03'\]/);
  assert.match(catalogs, /\['company-catalog', requestedCompany, 'cost-centers'\]/);
  assert.match(catalogs, /\['company-catalog', requestedCompany, 'cash-flow-areas'\]/);
  assert.match(catalogs, /\['company-catalog', requestedCompany, 'settings'\]/);
  assert.match(catalogs, /staleTime: 0, \/\/ Always refresh/);
  assert.match(catalogs, /token !== options\.getAccessToken\(\)/);
  assert.equal((catalogs.match(/queryClient\.fetchQuery\(/g) ?? []).length, 6);
});

test('árbol de cuentas PCGE: cacheada lectura y reconsulta tras crear, editar y desactivar', () => {
  assert.match(accounts, /queryKey: \['account-tree', companyId, onlyActive\]/);
  assert.match(accounts, /queryFn: \(\{ signal \}\) => readApi\.fetchAccountTree\(\{ onlyActive, signal \}\)/);
  assert.match(accounts, /staleTime: 30_000/);
  assert.match(accounts, /token !== options\.getAccessToken\(\)/);
  assert.match(accounts, /\['account-tree', companyId\]/);
  assert.match(accounts, /\['operational-accounts', companyId\]/);
  assert.equal((accounts.match(/await refreshTree\(\)/g) ?? []).length, 3);
  const view = read('../src/modules/accounting/views/AccountingCatalogView.vue');
  assert.match(view, /@click="refreshTree"/);
  assert.match(accounts, /async function refreshTree\(\)/);
});

test('API permite abortar lecturas; caché se borra al cambiar sesión o empresa', () => {
  assert.match(api, /fetchCostCenterMaster\(params: \{[^\n]*signal\?: AbortSignal/);
  assert.match(api, /fetchCompanySubdiaries\(activeOnly = false, signal\?: AbortSignal\)/);
  assert.match(api, /fetchOperationalAccounts\(signal\?: AbortSignal\)/);
  assert.match(api, /fetchAccountTree\(params\?: \{/);
  assert.match(api, /readonly signal\?: AbortSignal/);
  assert.match(api, /method: 'GET', signal: params\?\.signal/);
  assert.match(app, /watch\(\[accessToken, activeCompanyId\]/);
  assert.match(app, /queryClient\.clear\(\)/);
});

test('datos transaccionales siguen sin caché de frescura larga', () => {
  for (const source of [journal, rates, periods]) {
    assert.doesNotMatch(source, /queryClient\.fetchQuery\(/);
  }
  assert.match(journal, /isReadyToPost/);
});
