import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { formatAccountingDate, journalEntryStatusLabel, journalEntryTypeLabel, peruAccountingDate } from '../src/modules/accounting/utils/journalPresentation.ts';
import { selectRecordedUsdPenRate } from '../src/modules/accounting/utils/headerExchangeRate.ts';

const view = readFileSync(new URL('../src/modules/accounting/views/JournalEntryView.vue', import.meta.url), 'utf8');
const css = readFileSync(new URL('../src/assets/main.css', import.meta.url), 'utf8');

test('el libro diario no presenta certificaciones ni estado fiscal no consultado', () => {
  assert.doesNotMatch(view, /ISO\s*27001|SGSI Activo|Periodo Abierto|Período Abierto/);
  assert.match(view, /Período sujeto a validación al registrar/);
});

test('cabecera del asiento contiene etiquetas asociadas a sus controles', () => {
  for (const id of [
    'journal-entry-date', 'journal-entry-number', 'journal-subdiary',
    'journal-entry-type', 'journal-entry-currency', 'journal-exchange-rate',
    'journal-fx-criterion', 'journal-description',
  ]) {
    assert.match(view, new RegExp(`<label[^>]*for="${id}"`), `Etiqueta ${id}`);
    assert.match(view, new RegExp(`<(?:input|select)[^>]*id="${id}"`), `Control ${id}`);
  }
});

test('acciones visuales reutilizan el botón sin cambiar sus eventos', () => {
  assert.match(view, /import UiButton from/);
  assert.equal((view.match(/<UiButton\b/g) ?? []).length, 6);
  assert.equal((view.match(/<\/UiButton>/g) ?? []).length, 6);
  for (const action of ['autoBalance', 'addLine', 'clearForm', 'handleSubmit', 'loadHistory']) {
    assert.ok(view.includes(`@click="${action}"`), `Acción conservada: ${action}`);
  }
  assert.match(view, /:loading="isSubmitting"/);
  assert.match(view, /:disabled="!isReadyToPost \|\| isLoading"/);
});

test('partidas y valores monetarios conservan la entrada exacta de dominio', () => {
  assert.equal((view.match(/<JournalAccountCombobox\b/g) ?? []).length, 1);
  assert.equal((view.match(/<JournalEntityPicker\b/g) ?? []).length, 1);
  assert.equal((view.match(/@input="onAmountChange/g) ?? []).length, 2);
  assert.match(view, /totalDebitLocal\.toDisplay\(4\)/);
  assert.match(view, /totalCreditLocal\.toDisplay\(4\)/);
  assert.match(view, /totalDebitForeign\.toDisplay\(4\)/);
  assert.match(view, /totalCreditForeign\.toDisplay\(4\)/);
  assert.match(view, /isReadyToPost/);
});

test('navegación accesible en pestañas, partidas e historial', () => {
  assert.match(view, /:aria-pressed="activeTab === 'form'"/);
  assert.match(view, /:aria-pressed="activeTab === 'history'"/);
  assert.match(view, /:aria-pressed="selectedLineId === line.id"/);
  assert.match(view, /:aria-expanded="expandedEntryIds.has\(entry.id\)"/);
  assert.match(view, /@click\.stop="toggleExpandEntry\(entry.id\)"/);
  assert.match(view, /min-w-\[900px\]/);
  assert.match(view, /min-w-\[1120px\]/);
  assert.match(css, /\.journal-total dd/);
  assert.match(css, /\.journal-lines-table/);
});

test('resumen de cuadre visible desde la cabecera del editor sin alterar cálculos', () => {
  assert.match(view, /journal-quick-status/);
  assert.match(view, /isReadyToPost \? 'Listo para registrar'/);
  assert.match(view, /isBalanced \? 'Cuadrado · faltan datos'/);
  assert.match(view, /totalDebitLocal\.isPositive\(\) \|\| totalCreditLocal\.isPositive\(\)/);
  assert.match(view, /Diferencia: \{\{ baseCurrencyInfo\.symbol \}\} \{\{ diffLocal\.toDisplay\(4\) \}\}/);
  assert.match(css, /\.journal-quick-status/);
  assert.match(view, /2xl:grid-cols-5/);
  assert.match(css, /\.journal-header-fields label/);
});

test('los tipos de cambio editables no tienen aspecto de campos deshabilitados', () => {
  assert.equal((view.match(/w-\[104px\] rounded-md border border-border-strong bg-surf/g) ?? []).length, 2);
  assert.match(css, /\.journal-header-fields label/);
});


const app = readFileSync(new URL('../src/App.vue', import.meta.url), 'utf8');
const header = readFileSync(new URL('../src/shared/layout/AppHeader.vue', import.meta.url), 'utf8');
const ratesView = readFileSync(new URL('../src/modules/accounting/views/ExchangeRatesView.vue', import.meta.url), 'utf8');
const apiClient = readFileSync(new URL('../src/modules/accounting/api/httpClient.ts', import.meta.url), 'utf8');

test('fecha histórica ISO se presenta sin corrimiento de día UTC', () => {
  assert.equal(formatAccountingDate('2026-11-06T00:00:00.000000Z'), '06/11/2026');
  assert.equal(formatAccountingDate('2026-11-06'), '06/11/2026');
  assert.equal(formatAccountingDate('2026-02-30T00:00:00Z'), '2026-02-30T00:00:00Z');
  assert.equal(formatAccountingDate('sin-fecha'), 'sin-fecha');
  assert.equal(peruAccountingDate(new Date('2026-11-07T04:59:59Z')), '2026-11-06');
  assert.equal(peruAccountingDate(new Date('2026-11-07T05:00:00Z')), '2026-11-07');
});

test('estados y tipos contables legibles preservan valores originales de la API', () => {
  assert.equal(journalEntryTypeLabel('STANDARD'), 'Estándar');
  assert.equal(journalEntryTypeLabel('OPENING'), 'Apertura');
  assert.equal(journalEntryTypeLabel('ADJUSTMENT'), 'Ajuste');
  assert.equal(journalEntryTypeLabel('REVERSAL'), 'Reversión');
  assert.equal(journalEntryTypeLabel('CLOSING'), 'Cierre');
  assert.equal(journalEntryStatusLabel('POSTED'), 'Registrado');
  assert.equal(journalEntryStatusLabel('REVERSED'), 'Revertido');
  assert.match(view, /formatAccountingDate\(entry\.entry_date\)/);
  assert.match(view, /journalEntryStatusLabel\(entry\.status\)/);
  assert.match(view, /journalEntryTypeLabel\(entry\.entry_type\)/);
  assert.doesNotMatch(view, /\{\{ entry\.entry_date \}\}/);
});

test('el indicador de cabecera rechaza tasas sugeridas, otras fechas y otra moneda base', () => {
  const response = {
    rate_date: '2026-11-06',
    base_currency: { code: 'PEN', name: 'Soles', symbol: 'S/' },
    required_currencies: [{
      currency_id: 2, code: 'USD', name: 'Dólar', symbol: '$',
      buy_rate: '3.741000', sell_rate: '3.755000', is_recorded_for_date: true, source: 'LOCAL',
    }],
  };
  assert.equal(selectRecordedUsdPenRate(response, '2026-11-06')?.buy_rate, '3.741000');
  assert.equal(selectRecordedUsdPenRate(response, '2026-11-05'), null);
  assert.equal(selectRecordedUsdPenRate({ ...response, base_currency: { ...response.base_currency, code: 'USD' } }, '2026-11-06'), null);
  assert.equal(selectRecordedUsdPenRate({ ...response, required_currencies: [{ ...response.required_currencies[0], is_recorded_for_date: false }] }, '2026-11-06'), null);
  assert.equal(selectRecordedUsdPenRate(undefined, '2026-11-06'), null);
});

test('la cabecera consulta el tipo de cambio y no muestra valores de demostración', () => {
  assert.match(app, /useQuery\(/);
  assert.match(app, /'header-exchange-rates', activeCompanyId\.value, headerFxDate\.value/);
  assert.match(app, /selectRecordedUsdPenRate\(/);
  assert.match(app, /fetchCompanyExchangeRates\(headerFxDate\.value, signal\)/);
  assert.match(app, /queryClient\.clear\(\)/);
  assert.doesNotMatch(app, /const sunatBuyRate = SystemDecimal\.fromExchangeRate/);
  assert.match(header, /headerRateStatus === 'recorded'/);
  assert.match(header, /Sin registro diario/);
  assert.match(header, /no es una cotización SUNAT en vivo/);
  assert.match(apiClient, /fetchCompanyExchangeRates\(date: string, signal\?: AbortSignal\)/);
  assert.match(ratesView, /invalidateQueries\(\{ queryKey: \['header-exchange-rates', props\.activeCompanyId\]/);
});
