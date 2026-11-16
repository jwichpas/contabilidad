import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { buildStatementTableHtml } from '../src/modules/accounting/utils/printStatementTable.ts';

test('Impresión de EEFF escapa las notas redactadas por la empresa y los datos contables',()=>{
  const html=buildStatementTableHtml({
    company:'Empresa A <script>alert(1)</script>',
    taxId:'20999990101', title:'Estado de Cambios en el Patrimonio',
    period:'01/01/2026–31/01/2026', currency:'PEN',
    columns:['Concepto','Valor'],
    rows:[['Utilidad <2026>','3,849.30'],['Notas NIIF','A & B']],
    notices:['Pendiente de aprobación; no es archivo SUNAT.'],
    landscape:true,
  });
  assert.match(html,/@page\{size:A4 landscape/);
  assert.match(html,/&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
  assert.doesNotMatch(html,/<script>alert/);
  assert.match(html,/A &amp; B/);
  assert.match(html,/3,849\.30/);
  assert.match(html,/NO VALIDADO PARA PLE\/SUNAT/);
});

test('Rutas y vistas financieras separan ECP, balance, NIIF e indirecto',()=>{
  const view=readFileSync(new URL('../src/modules/accounting/views/FinancialStatementsView.vue',import.meta.url),'utf8');
  const cf=readFileSync(new URL('../src/modules/accounting/views/CashFlowStatementView.vue',import.meta.url),'utf8');
  const client=readFileSync(new URL('../src/modules/accounting/api/httpClient.ts',import.meta.url),'utf8');
  assert.match(view,/selectReport\('equity'\)/);
  assert.match(view,/selectReport\('trial'\)/);
  assert.match(view,/selectReport\('notes'\)/);
  assert.match(view,/<FinancialContinuationView/);
  assert.match(view,/<NiifNotesView/);
  assert.match(cf,/<IndirectCashFlowView/);
  for(const endpoint of ['/accounting/reports/trial-balance',
    '/accounting/reports/equity-changes','/accounting/reports/cash-flow/indirect',
    '/company/niif-notes'])assert.ok(client.includes(endpoint),endpoint);
});

test('Notas NIIF solo aceptan borradores y disponen de edición por empresa',()=>{
  const code=readFileSync(new URL('../src/modules/accounting/views/NiifNotesView.vue',import.meta.url),'utf8');
  assert.match(code,/company=props.activeCompanyId/);
  assert.match(code,/canEdit/);
  assert.match(code,/saveNiifNote/);
  assert.match(code,/expectedRevision/);
  assert.match(code,/no está aprobado/);
});
