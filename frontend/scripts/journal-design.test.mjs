import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';

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
});

test('los tipos de cambio editables no tienen aspecto de campos deshabilitados', () => {
  assert.equal((view.match(/w-\[104px\] rounded-md border border-border-strong bg-surf/g) ?? []).length, 2);
  assert.match(css, /\.journal-header-fields label/);
});

