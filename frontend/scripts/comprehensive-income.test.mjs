import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import {
  buildComprehensiveIncomePresentation, formatIncomeAmount,
  formatIncomePercent, formatIncomePeriod,
} from '../src/modules/accounting/utils/comprehensiveIncomePresentation.ts';
import { buildComprehensiveIncomePrintHtml } from '../src/modules/accounting/utils/printComprehensiveIncome.ts';

const account = (id, code, type, period, rubric = 'NONE') => ({
  id, code, name: 'Cuenta real ' + code, account_type: type,
  rubric, section: 'result', period_amount_local: period,
});
function fixture() {
  const accounts = [
    account(1,'701200','REVENUE','16000.0000','ER_ING_VENTAS_NETAS'),
    account(2,'691210','COST','8800.0000','ERF_COSTO_VENTAS'),
    account(3,'601100','EXPENSE','12000.0000','ER_GASTOS_NATURALEZA'),
    account(4,'611100','EXPENSE','-12000.0000','ER_GASTOS_NATURALEZA'),
    account(5,'635200','EXPENSE','1200.0000','ER_GASTOS_NATURALEZA'),
    account(6,'684150','EXPENSE','300.0000','ER_GASTOS_NATURALEZA'),
    account(7,'673110','EXPENSE','240.0000','ER_GASTOS_FINANCIEROS'),
    account(8,'881000','EQUITY','1610.7000','ER_IMPUESTO_RENTA'),
  ];
  return {
    accounts,
    currency:'PEN',as_of:'2026-01-31',
    income_statement: {
      from:'2026-01-01',to:'2026-01-31',
      revenue:'16000.0000',expenses:'12150.7000',net:'3849.3000',
    },
    analytic_routing_status:'NOT_CONFIGURED',
  };
}
function get(report,key) {
  const line=report.lines.find((x)=>x.key===key);
  assert.ok(line,key+' exists');
  return line;
}
test('ERI de los 14 asientos: ventas, costo, resultado, impuestos y utilidad neta', () => {
  const data=buildComprehensiveIncomePresentation(fixture());
  assert.equal(get(data,'sales').amount,'16000.0000');
  assert.equal(get(data,'cost_of_sales').amount,'-8800.0000');
  assert.equal(get(data,'gross_profit').amount,'7200.0000');
  assert.equal(get(data,'unallocated_operating').amount,'-1500.0000');
  assert.equal(get(data,'selling').amount,'0.0000');
  assert.equal(get(data,'administration').amount,'0.0000');
  assert.equal(get(data,'operating_profit').amount,'5700.0000');
  assert.equal(get(data,'financial_expense').amount,'-240.0000');
  assert.equal(get(data,'profit_before_tax').amount,'5460.0000');
  assert.equal(get(data,'income_tax').amount,'-1610.7000');
  assert.equal(data.netProfit,'3849.3000');
  assert.equal(data.discrepancy,'0.0000');
  assert.equal(data.comprehensiveTotal,null);
  assert.equal(get(data,'oci').amount,null);
  assert.equal(get(data,'total_comprehensive').amount,null);
  assert.equal(get(data,'income_tax').accountIds[0],8);
});
test('no atribuye gastos por naturaleza a administración/ventas sin mapeo funcional', () => {
  const data=fixture();
  const report=buildComprehensiveIncomePresentation(data);
  assert.equal(get(report,'unallocated_operating').amount,'-1500.0000');
  data.accounts[4].rubric='ERF_GASTOS_ADMIN_94';
  const mapped=buildComprehensiveIncomePresentation(data);
  assert.equal(get(mapped,'administration').amount,'-1200.0000');
  assert.equal(get(mapped,'unallocated_operating').amount,'-300.0000');
  assert.equal(mapped.netProfit,report.netProfit);
});
test('descuento y tipo de cambio se separan sin afectar neto', () => {
  const data=fixture();
  data.accounts.push(account(9,'709100','REVENUE','-50.0000'));
  data.accounts.push(account(10,'676110','EXPENSE','30.0000'));
  data.income_statement.net='3769.3000';
  const report=buildComprehensiveIncomePresentation(data);
  assert.equal(get(report,'discounts').amount,'-50.0000');
  assert.equal(get(report,'net_sales').amount,'15950.0000');
  assert.equal(get(report,'exchange_difference').amount,'-30.0000');
  assert.equal(report.netProfit,'3769.3000');
});
test('formato de gastos, ratios e importe cero sin floats', () => {
  assert.equal(formatIncomeAmount('-8303748.8610'),'(8,303,748.86)');
  assert.equal(formatIncomeAmount('3849.3000'),'3,849.30');
  assert.equal(formatIncomeAmount(null),'Pendiente');
  assert.equal(formatIncomePercent('7200.0000','16000.0000'),'45.00%');
  assert.equal(formatIncomePercent('-8800.0000','16000.0000'),'(55.00%)');
  assert.equal(formatIncomePercent('12.0000','0.0000'),'—');
  assert.equal(formatIncomePeriod('2026-01-01','2026-01-31'),'Por el período terminado el 31 de Enero de 2026');
  assert.equal(formatIncomePeriod('2025-01-01','2025-12-31'),'Por el año terminado el 31 de Diciembre de 2025');
});
test('PDF integrado presenta cifras reales, notas pendientes y HTML escapado', () => {
  const html=buildComprehensiveIncomePrintHtml({
    snapshot: fixture(),
    companyName:'<script>alert(1)</script>',
    companyTaxId:'20999990101',
    companyAddress:'Calle Arias & Araguez',
  });
  assert.match(html, /@page \{ size: A4 portrait/);
  assert.match(html, /Estado de Resultados Integrales/);
  assert.match(html, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
  assert.doesNotMatch(html, /<script>alert/);
  assert.match(html, /RUC 20999990101/);
  assert.match(html, /Calle Arias &amp; Araguez/);
  assert.match(html, /\(8,800\.00\)/);
  assert.match(html, /3,849\.30/);
  assert.match(html, /TOTAL RESULTADOS INTEGRALES/);
  assert.match(html, /Pendiente/);
  assert.match(html, /PRELIMINAR/);
});
test('pestaña ERI coexiste con ESF y flujo de efectivo y conserva navegación al Mayor', () => {
  const text=readFileSync(new URL('../src/modules/accounting/views/FinancialStatementsView.vue',import.meta.url),'utf8');
  assert.match(text,/selectReport\('income'\)/);
  assert.match(text,/<ComprehensiveIncomeSheet/);
  assert.match(text,/@open-ledger="openLedger"/);
  assert.match(text,/PDF Resultados Integrales/);
});
