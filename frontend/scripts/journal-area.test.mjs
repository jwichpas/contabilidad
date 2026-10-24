import { test } from 'node:test';
import assert from 'node:assert/strict';
import { cashFlowMatches } from '../src/modules/accounting/utils/journalArea.ts';
import { resetAccountDimensions } from '../src/modules/accounting/utils/journalLine.ts';
import { existsSync, readFileSync } from 'node:fs';

test('cash flow requirement rejects missing and inactive areas', () => {
  const rule = { requires_cash_flow_area: true, cash_flow_category: 'OPERATING' };
  assert.equal(cashFlowMatches(rule, null), false);
  assert.equal(cashFlowMatches(rule, { is_active: false, activity_category: 'OPERATING' }), false);
  assert.equal(cashFlowMatches({ ...rule, requires_cash_flow_area: false }, null), true);
});
test('specific activity requires its matching area and unknown categories fail closed', () => {
  for (const category of ['OPERATING', 'INVESTING', 'FINANCING']) {
    const rule = { requires_cash_flow_area: true, cash_flow_category: category };
    for (const other of ['OPERATING', 'INVESTING', 'FINANCING', 'CASH_EQUIVALENT']) {
      assert.equal(cashFlowMatches(rule, { is_active: true, activity_category: other }), category === other);
    }
  }
  assert.equal(cashFlowMatches({ requires_cash_flow_area: false, cash_flow_category: 'UNKNOWN' }, null), false);
});
test('disabled flow dimensions reject even known categories', () => {
  for (const category of ['NONE', 'CASH_EQUIVALENT']) {
    for (const activity of ['OPERATING', 'INVESTING', 'FINANCING', 'CASH_EQUIVALENT']) {
      assert.equal(cashFlowMatches({ requires_cash_flow_area: false, cash_flow_category: category },
        { is_active: true, activity_category: activity }), false);
    }
  }
  assert.equal(cashFlowMatches({ requires_cash_flow_area: false, cash_flow_category: 'NONE' },
    { is_active: true, activity_category: 'UNKNOWN' }), false);
});

test('switching journal account clears all four dimensions without changing amounts or description', () => {
  const line = {
    accountId: 11, costCenterId: 22, entity: { id: 33 },
    companyAreaId: 44, cashFlowAreaId: 55,
    description: 'Ficticio', debitLocal: '150.0000', creditLocal: '0.0000',
  };
  resetAccountDimensions(line);
  assert.equal(line.costCenterId, null);
  assert.equal(line.entity, null);
  assert.equal(line.companyAreaId, null);
  assert.equal(line.cashFlowAreaId, null);
  assert.equal(line.accountId, 11);
  assert.equal(line.description, 'Ficticio');
  assert.equal(line.debitLocal, '150.0000');
});

test('journal view wires account reset, conditional dimensions and compact exchange rates', () => {
  const view = readFileSync(new URL('../src/modules/accounting/views/JournalEntryView.vue', import.meta.url), 'utf8');
  assert.match(view, /@update:model-value="resetAccountDimensions\(line\)"/);
  assert.match(view, /:disabled="isSubmitting"/);
  assert.match(view, /requires_cost_center/);
  assert.match(view, /entityRequired\(operationalAccounts\.find/);
  assert.match(view, /requires_company_area/);
  assert.doesNotMatch(view, /\+ Centro opcional|\+ Anexo opcional/);
  assert.match(view, /:key="line\.id/);
  assert.match(view, /T\.C\. diario ·/);
  assert.match(view, /updateRateForCurrency\(rateItem\.code, 'buy_rate'/);
  assert.match(view, /updateRateForCurrency\(rateItem\.code, 'sell_rate'/);
});
test('cash flow does not permit a dimension when the account flag is disabled', () => {
  assert.equal(cashFlowMatches({ requires_cash_flow_area: false, cash_flow_category: 'OPERATING' },
    { is_active: true, activity_category: 'OPERATING' }), false);
  assert.equal(cashFlowMatches({ requires_cash_flow_area: false, cash_flow_category: 'NONE' }, null), true);
});

test('account setup controls organization requirement and PUT sends complete required fields', () => {
  const modal = readFileSync(new URL('../src/modules/accounting/components/AccountFormModal.vue', import.meta.url), 'utf8');
  const catalog = readFileSync(new URL('../src/modules/accounting/views/AccountingCatalogView.vue', import.meta.url), 'utf8');
  const tree = readFileSync(new URL('../src/modules/accounting/composables/useAccountTree.ts', import.meta.url), 'utf8');
  assert.match(modal, /v-model="form\.requires_company_area"/);
  assert.match(modal, /requires_company_area: form\.requires_company_area/);
  assert.match(catalog, /requires_company_area: payload\.requires_company_area/);
  assert.match(catalog, /account_type: payload\.account_type/);
  assert.match(catalog, /balance_type: payload\.balance_type/);
  assert.match(catalog, /is_active: modalEditingRow\.value\.node\.is_active/);
  assert.match(tree, /requires_company_area: formValues\.requires_company_area \?\? false/);
  assert.match(tree, /requires_company_area: payload\.requires_company_area/);
});

test('account autocomplete filters without a native select with thousands of options', () => {
  const view = readFileSync(new URL('../src/modules/accounting/views/JournalEntryView.vue', import.meta.url), 'utf8');
  const picker = readFileSync(new URL('../src/modules/accounting/components/JournalAccountCombobox.vue', import.meta.url), 'utf8');
  assert.match(view, /<JournalAccountCombobox/);
  assert.match(picker, /role="combobox"/);
  assert.match(picker, /role="listbox"/);
  assert.match(picker, /role="option"/);
  assert.match(picker, /found\.length === 40/);
  assert.match(picker, /normalize\('NFD'\)/);
  assert.match(picker, /event\.key === 'ArrowDown'/);
  assert.match(picker, /event\.key === 'Enter'/);
});

test('journal keeps exact money strings, fetches one bootstrap and exposes persisted subdiary', () => {
  const view = readFileSync(new URL('../src/modules/accounting/views/JournalEntryView.vue', import.meta.url), 'utf8');
  const client = readFileSync(new URL('../src/modules/accounting/api/httpClient.ts', import.meta.url), 'utf8');
  assert.match(view, /:value="line\.debitLocal"/);
  assert.match(view, /:value="line\.creditLocal"/);
  assert.match(view, /inputmode="decimal"/);
  assert.doesNotMatch(view, /v-model="line\.(debitLocal|creditLocal)"/);
  assert.match(view, /onAmountChange\(line, 'debit', \(\$event\.target as HTMLInputElement\)\.value\)/);
  assert.match(view, /fetchJournalEditorBootstrap\(entryDate\.value\)/);
  assert.match(view, /watch\(activeTab/);
  assert.match(client, /\/editor-bootstrap\?date=/);
  assert.match(view, /v-model="subdiaryCode"/);
  assert.match(view, /subdiary_code: subdiaryCode\.value/);
});

test('floating account and entity pickers stay outside the scrollable journal grid', () => {
  const account = readFileSync(new URL('../src/modules/accounting/components/JournalAccountCombobox.vue', import.meta.url), 'utf8');
  const entity = readFileSync(new URL('../src/modules/accounting/components/JournalEntityPicker.vue', import.meta.url), 'utf8');
  const view = readFileSync(new URL('../src/modules/accounting/views/JournalEntryView.vue', import.meta.url), 'utf8');
  assert.match(account, /<Teleport to="body">/);
  assert.match(account, /getBoundingClientRect\(\)/);
  assert.match(account, /zIndex: '9999'/);
  assert.match(entity, /<Teleport to="body">/);
  assert.match(entity, /role="dialog"/);
  assert.match(view, /min-w-\[1040px\]/);
  assert.match(view, /overflow-x-auto/);
});

test('subdiary master is a separate company-scoped route with controlled CRUD', () => {
  const view = readFileSync(new URL('../src/modules/accounting/views/CompanySubdiariesView.vue', import.meta.url), 'utf8');
  const app = readFileSync(new URL('../src/App.vue', import.meta.url), 'utf8');
  const nav = readFileSync(new URL('../src/shared/layout/AppSidebar.vue', import.meta.url), 'utf8');
  const api = readFileSync(new URL('../src/modules/accounting/api/httpClient.ts', import.meta.url), 'utf8');
  assert.match(view, /createCompanySubdiary/);
  assert.match(view, /renameCompanySubdiary/);
  assert.match(view, /setCompanySubdiaryActive/);
  assert.match(view, /canWrite/);
  assert.match(app, /currentModule === 'subdiaries'/);
  assert.match(nav, /Maestro de subdiarios/);
  assert.match(api, /\/api\/v1\/company\/subdiaries/);
});

test('journal editor keeps bimonetary amount columns on the primary row with conditional dimensions beneath', () => {
  const view = readFileSync(new URL('../src/modules/accounting/views/JournalEntryView.vue', import.meta.url), 'utf8');
  assert.match(view, /min-w-\[1040px\]/);
  assert.match(view, /<td colspan="7"/);
  assert.match(view, /Centro de costo obligatorio/);
  assert.match(view, /Anexo \/ tercero obligatorio/);
  assert.match(view, /Área organizativa obligatoria/);
  assert.match(view, /Área de flujo de efectivo obligatoria/);
  assert.doesNotMatch(view, /min-w-\[1800px\]/);
});

test('journal bootstrap projects only accounts and flags needed by the voucher form', () => {
  const backendFile = new URL('../../backend/app/Http/Controllers/Api/V1/Accounting/GetJournalEditorBootstrapController.php', import.meta.url);
  assert.equal(existsSync(backendFile), true, 'El controlador debe encontrarse en contabilidad/backend, relativo a frontend/scripts');
  const backend = readFileSync(backendFile, 'utf8');
  const dto = readFileSync(new URL('../src/modules/accounting/types/journalEntry.types.ts', import.meta.url), 'utf8');
  const picker = readFileSync(new URL('../src/modules/accounting/components/JournalEntityPicker.vue', import.meta.url), 'utf8');
  assert.match(backend, /AccountingAccount::query\(\)/);
  assert.match(backend, /'requires_cash_flow_area'/);
  assert.match(backend, /'entity_requirement_type'/);
  assert.match(backend, /accounts;dur=/);
  assert.match(dto, /type JournalEditorAccount = Pick<OperationalAccount/);
  assert.match(picker, /items-center justify-center/);
  assert.match(picker, /max-h-\[min\(80vh,650px\)\]/);
});
