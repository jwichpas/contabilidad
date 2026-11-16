import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import {
  buildFinancialPositionPresentation, formatPositionAmount,
  formatPositionDate, sumPositionMoney,
} from '../src/modules/accounting/utils/financialPositionPresentation.ts';
import { buildFinancialPositionPrintHtml } from '../src/modules/accounting/utils/printFinancialPosition.ts';

function fixture() {
  const makeRubric = (code, name, group_name, section, amount, i) => ({
    code, name, group_name, section, presentation_order: i * 10,
    amount_local: amount, period_amount_local: '0.0000',
    account_ids: [i],
  });
  const rubrics = [
    makeRubric('ESF_ACT_CORR_EFE', 'Efectivo y Equivalentes de Efectivo', 'ACTIVO_CORRIENTE', 'assets', '62560.0000', 1),
    makeRubric('ESF_ACT_CORR_CXC_COM', 'Cuentas por Cobrar Comerciales a Terceros (Neto)', 'ACTIVO_CORRIENTE', 'assets', '8880.0000', 2),
    makeRubric('ESF_ACT_CORR_INV', 'Inventarios', 'ACTIVO_CORRIENTE', 'assets', '13200.0000', 3),
    makeRubric('ESF_ACT_NCORR_IME', 'Propiedades, Planta y Equipo (Neto)', 'ACTIVO_NO_CORRIENTE', 'assets', '9700.0000', 4),
    makeRubric('ESF_PAS_CORR_CXP_COM', 'Cuentas por Pagar Comerciales', 'PASIVO_CORRIENTE', 'liabilities', '10490.7000', 5),
    makeRubric('ESF_PAS_NCORR_OBLIG_FIN', 'Obligaciones financieras', 'PASIVO_NO_CORRIENTE', 'liabilities', '20000.0000', 6),
    makeRubric('ESF_PAT_CAP', 'Capital Social', 'PATRIMONIO', 'equity', '60000.0000', 7),
  ];
  return {
    as_of: '2026-01-31', currency: 'PEN', is_preliminary: true,
    analytic_routing_status: 'ACTIVE', accounts: [], rubrics,
    esf_catalog: [...rubrics.map(({code,name,group_name,presentation_order}) => ({
      code, name, group_name, presentation_order,
    })), {
      code: 'ESF_ACT_CORR_GAS_ANT', name: 'Gastos pagados por anticipado',
      group_name: 'ACTIVO_CORRIENTE', presentation_order: 80,
    }],
    unmapped_account_ids: [],
    income_statement: { from: '2026-01-01', to: '2026-01-31',
      revenue: '16000.0000', expenses: '12150.7000', net: '3849.3000',
    },
    totals: {
      assets: '94340.0000', liabilities: '30490.7000',
      equity: '60000.0000', result: '3849.3000',
      liabilities_equity_result: '94340.0000',
      difference: '0.0000',
      debit_local: '0.0000', credit_local: '0.0000',
      unclassified: '0.0000', excluded: '0.0000',
      trial_debit_balance: '0.0000', trial_credit_balance: '0.0000',
      trial_balance_difference: '0.0000', trial_difference: '0.0000',
    },
  };
}

test('estado formal separa rubros corrientes, no corrientes y patrimonio sin alterar saldos', () => {
  const esf = buildFinancialPositionPresentation(fixture());
  assert.equal(esf.assetGroups.find((g) => g.key === 'ACTIVO_CORRIENTE').subtotal, '84640.0000');
  assert.equal(esf.assetGroups.find((g) => g.key === 'ACTIVO_NO_CORRIENTE').subtotal, '9700.0000');
  assert.equal(esf.liabilityGroups.find((g) => g.key === 'PASIVO_NO_CORRIENTE').subtotal, '20000.0000');
  assert.equal(esf.liabilityTotal, '30490.7000');
  assert.equal(esf.equityTotal, '63849.3000');
  assert.equal(esf.assetTotal, esf.liabilityAndEquityTotal);
  assert.equal(esf.difference, '0.0000');
  assert.equal(esf.equityGroup.rows.at(-1).value, '3849.3000');
  assert.equal(esf.equityGroup.rows.at(-1).note, null);
  assert.equal(esf.assetGroups[0].rows.find((row) => row.code === 'ESF_ACT_CORR_GAS_ANT').value, '0.0000');
});

test('saldo de períodos anteriores sin cerrar se revela por separado y la clasificación desconocida no se inventa', () => {
  const data = fixture();
  data.totals.result = '5000.0000';
  data.totals.liabilities_equity_result = '95490.7000';
  data.totals.difference = '-1150.7000';
  data.rubrics[0] = {
    ...data.rubrics[0], code: 'NONE', group_name: 'SIN_CLASIFICAR',
  };
  const esf = buildFinancialPositionPresentation(data);
  assert.equal(esf.equityGroup.rows.find((row) => row.code === 'UN-CLOSED-PRIOR').value, '1150.7000');
  assert.ok(esf.assetGroups.find((g) => g.key === 'ACTIVO_SIN_CLASIFICAR').rows.some((r) => r.requiresReview));
  assert.ok(esf.warnings.some((warning) => warning.includes('revisión')));
});

test('formato monetario redondea solo la presentación a 2 decimales sin floats', () => {
  assert.equal(formatPositionAmount('1059764.0550'), '1,059,764.06');
  assert.equal(formatPositionAmount('-101.0050'), '−101.01');
  assert.equal(formatPositionAmount('0.0000'), '—');
  assert.equal(formatPositionDate('2026-01-31'), 'Al 31 de Enero de 2026');
  assert.equal(sumPositionMoney(['100.0001', '-0.0001']), '100.0000');
});

test('PDF ESF A4 apaisado muestra dos columnas, empresa real, notas no asignadas y escapa HTML', () => {
  const html = buildFinancialPositionPrintHtml({
    snapshot: fixture(),
    companyName: '<img src=x onerror=alert(1)>',
    companyTaxId: '20999990101',
    companyAddress: 'Calle de prueba & 123',
  });
  assert.match(html, /@page \{ size: A4 landscape/);
  assert.match(html, /grid-template-columns: 1fr 1fr/);
  assert.match(html, /Estado de Situación Financiera/);
  assert.match(html, /RUC 20999990101/);
  assert.match(html, /&lt;img src=x onerror=alert\(1\)&gt;/);
  assert.match(html, /Calle de prueba &amp; 123/);
  assert.doesNotMatch(html, /<img src=x/);
  assert.match(html, /TOTAL ACTIVO/);
  assert.match(html, /TOTAL PASIVO Y PATRIMONIO NETO/);
  assert.match(html, /94,340\.00/);
  assert.match(html, /Notas/);
  assert.match(html, /PRELIMINAR/);
});

test('vista ESF formal reutiliza mismo modelo y mantiene acceso al Libro Mayor', () => {
  const vue = readFileSync(new URL('../src/modules/accounting/views/FinancialStatementsView.vue', import.meta.url), 'utf8');
  assert.match(vue, /<FinancialPositionSheet/);
  assert.match(vue, /@open-ledger="openLedger"/);
  assert.match(vue, /PDF ESF formal/);
  assert.match(vue, /PDF resumen y resultados/);
});
