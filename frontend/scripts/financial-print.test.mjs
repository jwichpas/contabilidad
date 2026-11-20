import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { buildFinancialReportPrintHtml } from '../src/modules/accounting/utils/printFinancialReport.ts';

test('la hoja PDF preliminar escapa datos externos y no incluye scripts inyectados', () => {
  const html = buildFinancialReportPrintHtml({
    title: 'Estado de prueba',
    company: '<img src=x onerror=alert(1)>',
    period: '01/2026', currency: 'PEN',
    sections: [{ heading: 'Resultado', rows: [{ label: '<script>alert(1)</script>', value: '3.849,3000' }] }],
    notices: ['No auditado'],
  });
  assert.match(html, /&lt;img src=x onerror=alert\(1\)&gt;/);
  assert.match(html, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
  assert.doesNotMatch(html, /<script>/);
  assert.match(html, /@page \{ size: A4/);
  assert.match(html, /PRELIMINAR/);
});

test('ambos accesos al flujo de efectivo comparten vista con enlaces accesibles', () => {
  const read = (file) => readFileSync(new URL(file, import.meta.url), 'utf8');
  const app = read('../src/App.vue');
  const sidebar = read('../src/shared/layout/AppSidebar.vue');
  const report = read('../src/modules/accounting/views/FinancialStatementsView.vue');
  assert.match(sidebar, /@click="emit\('navigate', 'cash-flow'\)"/);
  assert.match(app, /hash\.includes\('flujo-efectivo'\)/);
  assert.match(app, /currentModule === 'financial-statements' \|\| currentModule === 'cash-flow'/);
  assert.match(report, /selectReport\('cash-flow'\)/);
  assert.match(report, /PDF ESF formal/);
  assert.match(report, /PDF resumen y resultados/);
  assert.match(report, /companyName/);
});
