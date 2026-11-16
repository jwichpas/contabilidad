import assert from 'node:assert/strict';
import test from 'node:test';
import { buildAnnualCashFlowMatrix } from '../src/modules/accounting/utils/annualCashFlowMatrix.ts';
import { buildAnnualCashFlowPrintHtml } from '../src/modules/accounting/utils/printAnnualCashFlow.ts';

const zero = '0.0000';
function month(i) {
  return {
    month: i, cash_delta_local: zero, opening_entries_local: zero,
    opening_local: '0.0000', closing_local: '0.0000',
    operating_local: zero, investing_local: zero, financing_local: zero,
    net_flow_local: zero, exchange_effect_local: zero,
    other_adjustments_local: zero, unclassified_local: zero,
    expected_closing_local: zero, difference_local: zero,
    unclassified_count: 0, other_adjustments_count: 0,
    is_reconciled: true, groups: [],
  };
}
function report() {
  const months = Array.from({ length: 12 }, (_, i) => month(i + 1));
  months[0].groups = [
    { section: 'OPERATING', area_id: 1, code: 'F318-1102', name: 'Cobro de bienes',
      inflow_local: '250.1250', outflow_local: zero, net_local: '250.1250', count: 1 },
    { section: 'OPERATING', area_id: 2, code: 'OPE-IN', name: 'Cobros sin desglose',
      inflow_local: '100.0001', outflow_local: zero, net_local: '100.0001', count: 1 },
    { section: 'OPERATING', area_id: 3, code: 'OPE-OUT', name: 'Pagos sin desglose',
      inflow_local: zero, outflow_local: '40.0100', net_local: '-40.0100', count: 1 },
  ];
  months[0].operating_local = '310.1151';
  months[0].net_flow_local = '310.1151';
  months[0].opening_local = '400.0000';
  months[0].closing_local = '710.1151';
  for (let i=1;i<12;i++) {
    months[i].opening_local='710.1151';
    months[i].closing_local='710.1151';
  }
  return {
    year: 2026, through_month: 3, monthly: months,
    totals: { opening_local: '400.0000', closing_local: '710.1151',
      operating_local: '310.1151', investing_local: zero, financing_local: zero,
      net_flow_local: '310.1151', difference_local: zero,
      exchange_effect_local: zero, other_adjustments_local: zero, unclassified_local: zero },
  };
}

test('matriz mensual conserva importes exactos, distingue cuentas detalladas y residuos', () => {
  const rows = buildAnnualCashFlowMatrix(report());
  const line = (code) => rows.find((row) => row.code === code);
  assert.equal(rows.length > 40, true);
  assert.equal(line('1102').values[0], '250.1250');
  assert.equal(line('1198').values[0], '100.0001');
  assert.equal(line('1199').total, '350.1251');
  assert.equal(line('1298').values[0], '-40.0100');
  assert.equal(line('1299').total, '-40.0100');
  assert.equal(line('1301').total, '310.1151');
  assert.equal(line('2001F').total, '400.0000');
  assert.equal(line('2999').total, '710.1151');
  assert.equal(line('2999').values[11], '710.1151');
  assert.equal(line('1102').values[1], zero);
});

test('no acepta doce meses desordenados o parciales', () => {
  const invalid = report();
  invalid.monthly = invalid.monthly.slice(0, 11);
  assert.throws(() => buildAnnualCashFlowMatrix(invalid), /12 meses/);
});

test('imprimible A3 horizontal escapa datos sensibles y marca estado preliminar', () => {
  const html = buildAnnualCashFlowPrintHtml({
    year: 2026,
    throughMonth: 3,
    company: '<img src=x onerror=alert(1)>',
    currency: 'PEN',
    rows: buildAnnualCashFlowMatrix(report()),
    classified: false, reconciled: true,
  });
  assert.match(html, /@page \{ size: A3 landscape/);
  assert.match(html, /&lt;img src=x onerror=alert\(1\)&gt;/);
  assert.doesNotMatch(html, /<img src=x/);
  assert.match(html, /Diciembre/);
  assert.match(html, /Hasta Marzo/);
  assert.match(html, /PRELIMINAR/);
});


test('cobranza comercial contra cuenta 12 se presenta en 1102 aunque el área sea OPE-IN', () => {
  const data = report();
  data.monthly[0].groups.push({
    section: 'OPERATING', area_id: 2, code: 'OPE-IN',
    name: 'Cobranza clientes', concept_code: '1102', concept_source: 'COUNTERPART',
    inflow_local: '10000.0000', outflow_local: zero, net_local: '10000.0000', count: 1,
  });
  const rows = buildAnnualCashFlowMatrix(data);
  const row = (code) => rows.find((item) => item.code === code);
  assert.equal(row('1102').values[0], '10250.1250');
  assert.equal(row('1198').values[0], '100.0001');
  assert.equal(row('1199').values[0], '10350.1251');
});

test('pago a proveedor contra 42 se presenta en 1201 y no como pago sin desglose', () => {
  const data = report();
  data.monthly[0].groups.push({
    section: 'OPERATING', area_id: 3, code: 'OPE-OUT',
    name: 'Pago proveedores', concept_code: '1201', concept_source: 'COUNTERPART',
    inflow_local: zero, outflow_local: '6000.0000', net_local: '-6000.0000', count: 1,
  });
  const rows = buildAnnualCashFlowMatrix(data);
  assert.equal(rows.find((row) => row.code === '1201').values[0], '-6000.0000');
  assert.equal(rows.find((row) => row.code === '1298').values[0], '-40.0100');
});


test('códigos globales se conservan y cada empresa aplica su propia configuración sin alterar los totales', () => {
  const first = report();
  first.presentation = { revision: 3, settings: {
    hide_zero: true, labels: { '1102': 'Cobranza de clientes HISA' },
    hidden_codes: ['1105'],
    custom_lines: [{ code: 'C0001', label: 'Otros ingresos internos', section: 'OPERATING', direction: 'INFLOW' }],
    account_rules: [], area_rules: [],
  }};
  first.monthly[0].groups.push({
    section: 'OPERATING', area_id: 6, code: 'OPE-IN', name: 'Cobros clasificados',
    concept_code: 'C0001', concept_source: 'COMPANY_AREA',
    inflow_local: '12.3400', outflow_local: zero, net_local: '12.3400', count: 1,
  });
  const customized = buildAnnualCashFlowMatrix(first);
  const standard = buildAnnualCashFlowMatrix(report());
  assert.equal(customized.find((row) => row.code === '1102').label, 'Cobranza de clientes HISA');
  assert.equal(standard.find((row) => row.code === '1102').label, 'Venta de bienes y prestación de servicios');
  assert.equal(customized.find((row) => row.code === 'C0001').values[0], '12.3400');
  assert.equal(standard.find((row) => row.code === 'C0001'), undefined);
  assert.equal(customized.find((row) => row.code === '1105'), undefined);
  assert.equal(customized.find((row) => row.code === '1301').total, '310.1151');
  assert.equal(customized.find((row) => row.code === '2999').total, '710.1151');
  assert.equal(customized.find((row) => row.code === '1199').total, '362.4651');
});

test('ocultar cero no oculta movimientos mensuales compensados en el total del año', () => {
  const data = report();
  data.presentation = { revision: 1, settings: {
    hide_zero: true, hidden_codes: ['1102'], labels: {}, custom_lines: [], account_rules: [], area_rules: [],
  }};
  data.monthly[1].groups = [{
    section: 'OPERATING', area_id: 1, code: 'F318-1102', name: 'Extorno cobro',
    inflow_local: '-250.1250', outflow_local: zero, net_local: '-250.1250', count: 1,
  }];
  const rows = buildAnnualCashFlowMatrix(data);
  assert.equal(rows.find((row) => row.code === '1102').values[0], '250.1250');
  assert.equal(rows.find((row) => row.code === '1102').values[1], '-250.1250');
  assert.equal(rows.find((row) => row.code === '1102').total, '0.0000');
});
