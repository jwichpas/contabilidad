import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { filterEntitiesLocally, isCompleteEntityDirectory } from '../src/modules/company/utils/entityLocalSearch.ts';

const entity = (id, legalName, opts = {}) => ({
  id, legal_name: legalName, commercial_name: null, tax_id: String(id),
  identity_document_type_code: '6', is_client: false, is_supplier: false, is_employee: false,
  ...opts,
});
const all = [
  entity(1, 'Banco de Crédito del Perú S.A.', { is_supplier: true, commercial_name: 'BCP' }),
  entity(2, 'Crédito Comercial Internacional', { is_client: true }),
  entity(3, 'Almacén Andino', { is_employee: true }),
];

test('busca inmediatamente en memoria con acentos, mayúsculas, RUC y nombre comercial', () => {
  assert.deepEqual(filterEntitiesLocally(all, 'CREDI', 'all').map((e) => e.id), [1, 2]);
  assert.deepEqual(filterEntitiesLocally(all, 'credito', 'is_supplier').map((e) => e.id), [1]);
  assert.deepEqual(filterEntitiesLocally(all, 'BCP', 'all').map((e) => e.id), [1]);
  assert.deepEqual(filterEntitiesLocally(all, '3', 'all').map((e) => e.id), [3]);
  assert.deepEqual(filterEntitiesLocally(all, '', 'is_employee').map((e) => e.id), [3]);
  assert.equal(all.length, 3, 'el filtro no debe mutar los resultados de la consulta');
});

test('usa filtrado local únicamente cuando la página SIN FILTROS es completa', () => {
  const base = { data: all, current_page: 1, last_page: 1, per_page: 20, total: 3 };
  assert.equal(isCompleteEntityDirectory(base), true);
  assert.equal(isCompleteEntityDirectory({ ...base, total: 4 }), false);
  assert.equal(isCompleteEntityDirectory({ ...base, last_page: 2 }), false);
  assert.equal(isCompleteEntityDirectory({ ...base, current_page: 2 }), false);
  assert.equal(isCompleteEntityDirectory(null), false);
});

test('TanStack reutiliza búsquedas paginadas sin mezclar tenants y se invalida al mutar', () => {
  const view = readFileSync(new URL('../src/modules/company/views/EntityMasterView.vue', import.meta.url), 'utf8');
  const app = readFileSync(new URL('../src/App.vue', import.meta.url), 'utf8');
  const client = readFileSync(new URL('../src/modules/accounting/api/httpClient.ts', import.meta.url), 'utf8');
  assert.match(view, /queryClient\.fetchQuery\(/);
  assert.match(view, /entityCachePrefix\(requestedCompany\)/);
  assert.match(view, /getActiveCompanyId: \(\) => requestedCompany/);
  assert.match(view, /requestToken !== props\.getAccessToken\(\)/);
  assert.match(view, /if \(isLocalDirectory\.value\) return/);
  assert.match(view, /filterEntitiesLocally\(baseDirectory\.value\?\.data/);
  assert.match(view, /queryClient\.invalidateQueries\(/);
  assert.match(view, /await refreshEntities\(\)/);
  assert.match(view, /@click="refreshEntities"/);
  assert.match(app, /watch\(\[accessToken, activeCompanyId\]/);
  assert.match(app, /queryClient\.clear\(\)/);
  assert.match(client, /readonly signal\?: AbortSignal/);
  assert.match(client, /method: 'GET', signal: params\.signal/);
});
