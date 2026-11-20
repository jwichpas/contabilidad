import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { applyFinancialReportLayout } from '../src/modules/accounting/utils/financialLayout.ts';
import { buildComprehensiveIncomePresentation } from '../src/modules/accounting/utils/comprehensiveIncomePresentation.ts';
import { buildAnnualCashFlowMatrix } from '../src/modules/accounting/utils/annualCashFlowMatrix.ts';

const layout = {
  labels: {sale:'Ventas de la empresa A'},
  hidden_codes: ['rent'],
  line_order: ['rent','sale'],
  hide_zero: false,
};
test('estructura por empresa: renombra, reordena dentro del bloque y no oculta saldo distinto de cero', () => {
  const rows=[
    {code:'sale',label:'Ventas',kind:'detail',value:'50.0000'},
    {code:'rent',label:'Alquiler',kind:'detail',value:'20.0000'},
    {code:'total',label:'TOTAL',kind:'subtotal',value:'70.0000'},
  ];
  const group=(code)=>code==='sale'||code==='rent'?'OPERATING':null;
  const permitted=(code)=>group(code)!==null;
  const updated=applyFinancialReportLayout(rows,layout,group,permitted,
    (row)=>row.value==='0.0000');
  assert.deepEqual(updated.map((x)=>x.code),['rent','sale','total']);
  assert.equal(updated[1].label,'Ventas de la empresa A');
  assert.equal(updated[0].value,'20.0000');
  assert.equal(updated[2].value,'70.0000');
  assert.equal(rows[0].label,'Ventas');
  assert.equal(applyFinancialReportLayout(rows,null,group,permitted,
    (row)=>row.value==='0.0000')[0].label,'Ventas');
});
test('sin movimientos puede ocultar detalles, pero nunca totales',()=>{
  const base=[
    {code:'sale',label:'Ventas',value:'0.0000'},
    {code:'total',label:'TOTAL',value:'0.0000'},
  ];
  const hidden=applyFinancialReportLayout(base,{...layout,hide_zero:true},
    ()=>'REVENUE', (code)=>code!=='total',(line)=>line.value==='0.0000');
  assert.deepEqual(hidden.map((row)=>row.code),['total']);
});
test('reordenación no cruza grupos ni mueve resultados obligatorios',()=>{
  const base=[
    {code:'a',label:'A',value:'1.0000'},
    {code:'b',label:'B',value:'2.0000'},
    {code:'subtotal',label:'S',value:'3.0000'},
    {code:'c',label:'C',value:'4.0000'},
  ];
  const shuffled=applyFinancialReportLayout(base,{...layout,line_order:['c','b','a']},
    (code)=>['a','b'].includes(code)?'G1':code==='c'?'G2':null,
    (code)=>code!=='subtotal',()=>false);
  assert.deepEqual(shuffled.map((x)=>x.code),['b','a','subtotal','c']);
  assert.deepEqual(shuffled.map((x)=>x.value),['2.0000','1.0000','3.0000','4.0000']);
});
test('editor, impresión, consultor y flujo utilizan las mismas estructuras',()=>{
  const views=[
    '../src/modules/accounting/views/FinancialStatementsView.vue',
    '../src/modules/accounting/views/AnnualCashFlowStatementView.vue',
    '../src/modules/accounting/views/FinancialLayoutSettingsPanel.vue',
  ].map((file)=>readFileSync(new URL(file,import.meta.url),'utf8'));
  assert.match(views[0], /<FinancialLayoutSettingsPanel/);
  assert.match(views[1], /<FinancialLayoutSettingsPanel/);
  assert.match(views[0], /:layout="esfLayout"/);
  assert.match(views[0], /:layout="eriLayout"/);
  assert.match(views[1], /buildAnnualCashFlowMatrix\(report.value,layout.value\)/);
  assert.match(views[2], /Guardar estructura de esta empresa/);
});
