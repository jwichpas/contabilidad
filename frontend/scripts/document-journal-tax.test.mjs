import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { TAX_CATEGORY_LABELS } from '../src/modules/accounting/types/documentJournal.types.ts';
import { buildDocumentJournalEntityQuery } from '../src/modules/accounting/api/documentJournalEntityQuery.ts';
import { createDocumentJournalDraftSchema } from '../src/modules/accounting/schemas/documentJournalDraft.schema.ts';
import { isDocumentFxReady } from '../src/modules/accounting/api/documentJournalFx.ts';

const read = (relative) => readFileSync(new URL(relative, import.meta.url), 'utf8');

test('purchase and sales classifications cover taxable, mixed, exempt, unaffected and exports', () => {
  const codes = Object.keys(TAX_CATEGORY_LABELS);
  assert.deepEqual(codes.filter(code => code.startsWith('P_')), [
    'P_TAXED_EXCLUSIVE', 'P_TAXED_MIXED', 'P_TAXED_NON_TAXED',
    'P_TAXED_NO_CREDIT', 'P_NON_TAXED',
  ]);
  assert.deepEqual(codes.filter(code => code.startsWith('S_')), [
    'S_TAXED', 'S_EXONERATED', 'S_UNAFFECTED', 'S_EXPORT',
  ]);
});

test('the interface exposes the internal tax book in addition to vouchers', () => {
  const view = read('../src/modules/accounting/views/DocumentJournalView.vue');
  assert.match(view, /'register', 'tax', 'history', 'settings'/);
  assert.match(view, /fetchTaxRegisterSummary/);
  assert.match(view, /fetchTaxRegister/);
  assert.match(view, /tax_allocations: taxAllocations\.value\.map/);
  assert.match(view, /draft\.tax_period\.replace/);
  assert.match(view, /category_account_map: Object\.fromEntries/);
  assert.match(view, /Pendiente de comparación con la propuesta SUNAT\/SIRE/);
  assert.match(view, /Registrar en Diario y Registro Tributario/);
});

test('backend posts both records in a transaction without pretending SUNAT transmission', () => {
  const backend = read('../../backend/app/Http/Controllers/Api/V1/Accounting/DocumentJournalController.php');
  const tax = read('../../backend/app/Services/Accounting/DocumentTaxAllocationService.php');
  const route = read('../../backend/routes/api.php');
  const summary = read('../../backend/app/Http/Controllers/Api/V1/Accounting/TaxRegisterController.php');
  assert.match(backend, /DocumentTaxAllocationService::normalize/);
  assert.match(backend, /AccountingTaxRegisterEntry::query\(\)->create/);
  assert.match(backend, /AccountingTaxRegisterAllocation::query\(\)->create/);
  assert.match(backend, /\$this->journal->post\(/);
  assert.match(route, /accounting\/document-journals\/\{module\}\/tax-register/);
  assert.match(summary, /PENDING_SIRE_RECONCILIATION/);
  assert.match(summary, /journal\.status', 'POSTED'/);
  assert.match(tax, /P_TAXED_MIXED/);
  assert.match(tax, /S_EXPORT/);
});

test('initial purchase and sales directories do not send search= when blank', () => {
  const purchases = new URLSearchParams(buildDocumentJournalEntityQuery('purchases'));
  assert.equal(purchases.get('per_page'), '100');
  assert.equal(purchases.get('is_supplier'), '1');
  assert.equal(purchases.has('is_client'), false);
  assert.equal(purchases.has('search'), false);

  const sales = new URLSearchParams(buildDocumentJournalEntityQuery('sales', '   '));
  assert.equal(sales.get('is_client'), '1');
  assert.equal(sales.has('is_supplier'), false);
  assert.equal(sales.has('search'), false);
});

test('directory search sends trimmed nonempty terms and preserves supplier/client filters', () => {
  const purchases = new URLSearchParams(buildDocumentJournalEntityQuery('purchases', '  20601234567  '));
  assert.equal(purchases.get('search'), '20601234567');
  assert.equal(purchases.get('is_supplier'), '1');

  const sales = new URLSearchParams(buildDocumentJournalEntityQuery('sales', '  Comercial Andina  '));
  assert.equal(sales.get('search'), 'Comercial Andina');
  assert.equal(sales.get('is_client'), '1');
});


const validPurchase = () => ({
  document_type: '01',
  series: 'F600',
  number: '3500',
  entity_id: '1',
  issue_date: '2026-11-12',
  entry_date: '2026-11-12',
  tax_period: '2026-11',
  due_date: '',
  currency: 'PEN',
  description: 'Compra de mercadería',
  cost_center_id: '',
  company_area_id: '',
  cash_flow_area_id: '',
  tax_allocations: [{
    category: 'P_TAXED_EXCLUSIVE',
    base_amount: '100',
    tax_amount: '18',
    creditable_tax_amount: '0',
  }],
});

test('Zod validates invoice before asking for posting confirmation', () => {
  const schema = createDocumentJournalDraftSchema('purchases');
  assert.equal(schema.safeParse(validPurchase()).success, true);
  assert.equal(schema.safeParse({ ...validPurchase(), entity_id: '' }).success, false);
  assert.equal(schema.safeParse({ ...validPurchase(), entry_date: '2026-11-01' }).success, false);
  assert.equal(schema.safeParse({ ...validPurchase(), tax_allocations: [] }).success, false);
  assert.equal(schema.safeParse({
    ...validPurchase(),
    tax_allocations: [{ category: 'P_NON_TAXED', base_amount: '100', tax_amount: '18', creditable_tax_amount: '0' }],
  }).success, false);
});

test('VueSonner, VeeValidate and live registered FX rate are used before POST', () => {
  const view = read('../src/modules/accounting/views/DocumentJournalView.vue');
  const dialog = read('../src/modules/accounting/components/DocumentPostingConfirmDialog.vue');
  const app = read('../src/App.vue');
  const client = read('../src/modules/accounting/api/httpClient.ts');

  assert.match(view, /useForm<DocumentJournalDraftValues>/);
  assert.match(view, /toTypedSchema\(createDocumentJournalDraftSchema/);
  assert.match(view, /const result = await validate\(\)/);
  assert.match(view, /await refreshFx\(\)/);
  assert.match(view, /rate\.is_recorded_for_date/);
  assert.match(view, /toast\.warning\('Falta registrar el tipo de cambio diario/);
  assert.match(view, /:disabled="!canPost \|\| !configured \|\| posting \|\| fxLoading \|\| fxImporting"/);
  assert.doesNotMatch(view, /window\.confirm/);
  assert.match(dialog, /<Dialog/);
  assert.match(app, /<Toaster/);
  assert.match(client, /fetchCompanyExchangeRates\(date: string, signal\?: AbortSignal\)/);
});


test('FX gate rejects stale, suggested and historical rates even when the invoice currency is PEN', () => {
  const exact = {
    rate_date: '2026-11-12',
    base_currency: { code: 'PEN', name: 'Soles', symbol: 'S/' },
    required_currencies: [{
      currency_id: 1, code: 'USD', name: 'Dólares', symbol: '$',
      buy_rate: '3.742000', sell_rate: '3.758000',
      is_recorded_for_date: true, source: 'DATABASE_EXACT',
    }],
  };
  assert.equal(isDocumentFxReady(exact, '2026-11-12'), true);
  assert.equal(isDocumentFxReady(exact, '2026-11-13'), false);
  assert.equal(isDocumentFxReady(null, '2026-11-12'), false);
  assert.equal(isDocumentFxReady({
    ...exact, required_currencies: [{ ...exact.required_currencies[0], is_recorded_for_date: false, source: 'LATEST_HISTORICAL' }],
  }, '2026-11-12'), false);
  assert.equal(isDocumentFxReady({
    ...exact, required_currencies: [{ ...exact.required_currencies[0], is_recorded_for_date: false, source: 'DEFAULT_PRESET' }],
  }, '2026-11-12'), false);
  assert.equal(isDocumentFxReady({
    ...exact, required_currencies: [{ ...exact.required_currencies[0], sell_rate: '0' }],
  }, '2026-11-12'), false);
  assert.equal(isDocumentFxReady({ ...exact, required_currencies: [] }, '2026-11-12'), true);
});


test('SUNAT fallback integrates through explicit POST and exact company/date checks', () => {
  const view = read('../src/modules/accounting/views/DocumentJournalView.vue');
  const api = read('../src/modules/accounting/api/httpClient.ts');
  const backend = read('../../backend/app/Http/Controllers/Api/V1/Accounting/DocumentJournalController.php');
  const routes = read('../../backend/routes/api.php');
  const schedule = read('../../backend/app/Scheduling/AccountingTaskSchedule.php');
  const bootstrap = read('../../backend/bootstrap/app.php');

  assert.match(view, /api\.importSunatExchangeRate\(targetDate\)/);
  assert.match(view, /Importar tasa SUNAT/);
  assert.match(view, /await importSunatFx\(false\)/);
  assert.match(api, /exchange-rates\/import-sunat/);
  assert.match(backend, /sunatFx->sync\(/);
  assert.match(routes, /exchange-rates\/import-sunat/);
  assert.match(routes, /ADMIN,CHIEF_ACCOUNTANT,ACCOUNTANT/);
  assert.match(schedule, /twiceDailyAt\(10, 16, 30\)/);
  assert.match(schedule, /withoutOverlapping\(10\)/);
  assert.match(bootstrap, /withSchedule/);
});
