import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { TAX_CATEGORY_LABELS } from '../src/modules/accounting/types/documentJournal.types.ts';

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
