import { readFileSync } from 'node:fs';
import assert from 'node:assert/strict';
import { test } from 'node:test';

const read = (relative) => readFileSync(new URL(relative, import.meta.url), 'utf8');
const app = read('../src/App.vue');
const journal = read('../src/modules/accounting/views/JournalEntryView.vue');
const catalog = read('../src/modules/accounting/views/AccountingCatalogView.vue');
const costCenter = read('../src/modules/company/views/CostCenterMasterView.vue');
const entity = read('../src/modules/company/views/EntityMasterView.vue');
const css = read('../src/assets/main.css');
const costCenterEditor = read('../src/modules/company/components/CostCenterEditor.vue');

test('pantallas de compras y ventas mantienen la carga diferida del resto del sistema', () => {
  const views = [
    'AccountingCatalogView', 'SeeReferencesView', 'SunatReferencesView', 'JournalEntryView',
    'DocumentJournalView', 'CompanySubdiariesView', 'ExchangeRatesView', 'FiscalPeriodsView', 'FinancialStatementsView',
    'CompanySettingsView', 'EntityMasterView', 'CompanyFiscalHistoryView',
    'CostCenterMasterView', 'CompanyOnboardingWizard',
  ];
  for (const view of views) {
    assert.match(app, new RegExp(`const ${view} = defineAsyncComponent\\(`));
    assert.doesNotMatch(app, new RegExp(`import ${view} from `));
  }
  assert.equal((app.match(/loader: \(\) => import\('/g) ?? []).length, views.length);
  assert.match(app, /loadingComponent: UiViewLoading/);
  assert.match(app, /errorComponent: UiViewLoadError/);
});

test('carga diferida conserva los límites por empresa, la sesión y las acciones', () => {
  assert.match(app, /:key="`journal-\$\{activeCompanyId\}`"/);
  assert.match(app, /:key="`entities-\$\{activeCompanyId\}`"/);
  assert.match(app, /:key="'cost-centers-' \+ activeCompanyId"/);
  assert.match(app, /:can-write="canManageEntities"/);
  assert.match(app, /@posting-change="isJournalSubmitting = \$event"/);
  assert.match(app, /import LoginView from/);
  assert.match(app, /import AppSidebar from/);
  assert.match(app, /import AppHeader from/);
});

test('panel de cambio agrupa controles y conserva los importes', () => {
  assert.match(journal, /class="journal-fx-panel/);
  assert.match(journal, /aria-labelledby="journal-fx-heading"/);
  for (const model of ['exchangeRate', 'exchangeRateType']) {
    assert.match(journal, new RegExp(`v-model="${model}"`));
  }
  assert.equal((journal.match(/updateRateForCurrency\(rateItem\.code, 'buy_rate'/g) ?? []).length, 1);
  assert.equal((journal.match(/updateRateForCurrency\(rateItem\.code, 'sell_rate'/g) ?? []).length, 1);
  assert.match(journal, /T\.C\. diario ·/);
  assert.match(journal, /isReadyToPost/);
  assert.match(journal, /toDisplay\(4\)/);
});

test('los maestros comparten títulos, filtros y tablas sin alterar los eventos de gestión', () => {
  assert.match(catalog, /workspace-heading/);
  assert.match(catalog, /role="treegrid"/);
  assert.match(catalog, /<AccountTreeNodeRow/);
  assert.match(catalog, /@click="openCreateRootModal"/);
  assert.match(costCenter, /workspace-heading/);
  assert.match(costCenter, /workspace-toolbar/);
  assert.match(costCenter, /workspace-table/);
  assert.match(costCenter, /@submit\.prevent="filter"/);
  assert.match(costCenter, /@save="save"/);
  assert.match(costCenter, /@click="deactivate"/);
  assert.match(entity, /workspace-heading/);
  assert.match(entity, /workspace-toolbar/);
  assert.match(entity, /workspace-table/);
  assert.match(entity, /@click="openCreate"/);
  assert.match(entity, /@submit\.prevent="saveEntity"/);
  assert.match(entity, /v-if="canDeactivate"/);
  for (const selector of ['.workspace-heading', '.workspace-toolbar', '.workspace-table', '.workspace-field']) {
    assert.ok(css.includes(selector), selector);
  }
});

test('formularios maestros conservan validación, autorizaciones y accesibilidad', () => {
  assert.match(entity, /workspace-form/);
  assert.match(entity, /:loading="isSaving"/);
  assert.match(entity, /v-if="showForm && canWrite"/);
  assert.match(entity, /v-if="pendingDeactivate && canDeactivate"/);
  assert.match(costCenterEditor, /workspace-field/);
  assert.match(costCenterEditor, /costCenterPayload\(draft\)/);
  assert.match(costCenterEditor, /@submit\.prevent="submit"/);
  assert.match(costCenterEditor, /:loading="saving"/);
  assert.match(css, /\.workspace-form/);
});

test('las pantallas distinguen búsquedas pendientes y totales del árbol sin mostrar ID interno', () => {
  assert.match(entity, /id="entity-search-help"/);
  assert.match(entity, /aria-describedby="entity-search-help"/);
  assert.match(entity, /searchInput\.trim\(\) !== committedSearch/);
  assert.match(entity, /presiona Enter o pulsa Buscar/);
  assert.match(entity, /anexos en la consulta/);
  assert.doesNotMatch(entity, /Directorio compartido[^\n]*Empresa \{\{ activeCompanyId \}\}/);
  assert.doesNotMatch(costCenter, /<p>Empresa \{\{ activeCompanyId \}\}/);
  assert.match(catalog, /filas visibles/);
  assert.match(catalog, /cuentas en el plan/);
});


test('editor PCGE conserva el padre al editar 401110 y permite nivel 6 sin perder jerarquía', () => {
  const form = read('../src/modules/accounting/components/AccountFormModal.vue');
  const row = read('../src/modules/accounting/components/AccountTreeNodeRow.vue');
  const createRequest = read('../../backend/app/Http/Requests/Api/V1/Accounting/CreateAccountingAccountRequest.php');
  const updateRequest = read('../../backend/app/Http/Requests/Api/V1/Accounting/UpdateAccountingAccountRequest.php');

  assert.match(form, /parent_id: props\.mode === 'edit'/);
  assert.match(form, /editingAccount\.value\?\.parent_id \?\? null/);
  assert.match(form, /form\.is_operational = lvl === 6/);
  assert.match(form, /targetLevel < 3 \|\| \(mode === 'edit' && editingRow\?\.hasChildren === true\)/);
  assert.match(form, /Las cuentas operativas \(niveles 3 a 6\)/);
  assert.doesNotMatch(form, /Solo las cuentas de nivel 4 reciben movimientos/);

  assert.match(catalog, /parentRow\.node\.level >= 6/);
  assert.match(catalog, /focusedRow\.value\.node\.level < 6/);
  assert.match(catalog, /Solo operativas/);
  assert.doesNotMatch(catalog, /Solo Operativas \(N4\)/);
  assert.match(row, /row\.node\.level < 6 && row\.node\.is_active/);
  assert.match(row, /case 6:\s+return 'N6';/);
  assert.match(createRequest, /'level' => \['required', 'integer', 'between:1,6'\]/);
  assert.match(createRequest, /if \(\$isOperational && \$level < 3\)/);
  assert.match(updateRequest, /if \(\$isOperational && \$account->level < 3\)/);
});
