import type { FinancialSnapshot } from '../types/financialSnapshot.types';
import type { FinancialReportLayoutSettings } from '../types/financialLayout.types';
import type { PositionGroup, PositionLine } from './financialPositionPresentation.ts';
import {
  buildFinancialPositionPresentation, currencyPositionLabel,
  formatPositionAmount, formatPositionDate,
} from './financialPositionPresentation.ts';

export interface FinancialPositionPrintInput {
  readonly snapshot: FinancialSnapshot;
  readonly companyName: string;
  readonly companyTaxId: string;
  readonly companyAddress: string;
  readonly layout?: FinancialReportLayoutSettings | null;
}

function escapeHtml(value: string): string {
  return value.replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
function cell(value: string): string {
  return '<td class="money">' + escapeHtml(formatPositionAmount(value)) + '</td>';
}
function detail(line: PositionLine): string {
  return '<tr><td class="label' + (line.requiresReview ? ' caution' : '') + '">' +
    escapeHtml(line.label) + '</td><td class="note">—</td>' + cell(line.value) + '</tr>';
}
function group(group: PositionGroup): string {
  if (!group.rows.length) return '';
  return '<tr class="group"><th colspan="3" scope="rowgroup">' + escapeHtml(group.label) + '</th></tr>' +
    group.rows.map(detail).join('') +
    '<tr class="subtotal"><th colspan="2" scope="row">Total ' +
    escapeHtml(group.label.toLowerCase()) + '</th>' + cell(group.subtotal) + '</tr>';
}
function total(label: string, amount: string, kind = 'grand-total'): string {
  return '<tr class="' + kind + '"><th colspan="2" scope="row">' +
    escapeHtml(label) + '</th>' + cell(amount) + '</tr>';
}
function table(head: string, year: string, currency: string, body: string): string {
  return '<table><colgroup><col><col class="note-col"><col class="money-col"></colgroup>' +
    '<thead><tr><th scope="col">' + escapeHtml(head) +
    '</th><th scope="col" class="note">Notas</th><th scope="col" class="money">' +
    escapeHtml(year) + '<span class="unit">' + escapeHtml(currency) + '</span></th></tr></thead>' +
    '<tbody>' + body + '</tbody></table>';
}

/** A4 landscape two-column preliminary ESF; all numbers originate in API. */
export function buildFinancialPositionPrintHtml(input: FinancialPositionPrintInput): string {
  const e = escapeHtml;
  const data = input.snapshot;
  const model = buildFinancialPositionPresentation(data,input.layout);
  const assetBody = model.assetGroups.map(group).join('') +
    total('TOTAL ACTIVO', model.assetTotal);
  const liabilitiesBody = model.liabilityGroups.map(group).join('') +
    total('TOTAL PASIVO', model.liabilityTotal, 'subtotal-main') +
    group(model.equityGroup) +
    total('TOTAL PATRIMONIO NETO', model.equityTotal, 'subtotal-main') +
    total('TOTAL PASIVO Y PATRIMONIO NETO', model.liabilityAndEquityTotal);
  const warnings = model.warnings.map((notice) => '<li>' + e(notice) + '</li>').join('');
  return `<!doctype html><html lang="es"><head><meta charset="utf-8">
<title>ESF ${e(data.as_of)} — ${e(input.companyName)}</title>
<style>
@page { size: A4 landscape; margin: 10mm 12mm; }
* { box-sizing: border-box; }
body { font: 10px Arial, Helvetica, sans-serif; color: #111827; line-height: 1.26; margin: 0; }
.company { font-size: 12px; font-weight: 700; text-transform: uppercase; overflow-wrap: anywhere; }
.meta { margin-top: 3px; font-weight: 600; }
h1 { margin: 12px 0 3px; font-size: 14px; font-weight: 700; }
.subtitle { margin: 2px 0; }
.preliminary { margin-top: 6px; color: #6b4c1f; font-weight: 600; }
.two-columns { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; align-items: start; margin-top: 16px; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 9px; }
col.note-col { width: 39px; } col.money-col { width: 97px; }
th, td { padding: 3px 2px; vertical-align: top; }
thead th { text-align: left; font-weight: 700; border-bottom: 1px solid #333; padding-bottom: 6px; }
.label { overflow-wrap: anywhere; }
.note { text-align: center; white-space: nowrap; font-size: 8px; }
.money { text-align: right; font-family: Arial, Helvetica, sans-serif; font-variant-numeric: tabular-nums; white-space: nowrap; }
.unit { display: block; font-size: 8px; }
.group th { padding: 7px 2px 4px; text-align: left; font-weight: 700; background: #f3f4f6; }
.subtotal th, .subtotal td { border-top: 1px solid #9ca3af; font-weight: 700; padding-top: 6px; }
.subtotal-main th, .subtotal-main td { border-top: 1px solid #9ca3af; font-weight: 700; padding-top: 6px; }
.grand-total th, .grand-total td { border-top: 2px solid #111827; border-bottom: 2px solid #111827; font-weight: 700; padding: 7px 2px; }
.caution { color: #a24120; }
tr { break-inside: avoid; }
.notes { border-top: 1px solid #ccc; margin-top: 17px; font-size: 8px; }
.notes ul { padding-left: 12px; margin: 5px 0; }
.notes li { margin: 2px 0; }
@media screen { body { max-width: 1120px; margin: 0 auto; padding: 24px; } }
</style></head><body>
<header>
<div class="company">${e(input.companyName)}</div>
<div class="meta">RUC ${e(input.companyTaxId)}</div>
${input.companyAddress ? '<div class="meta">' + e(input.companyAddress) + '</div>' : ''}
<h1>Estado de Situación Financiera</h1>
<p class="subtitle">${e(formatPositionDate(data.as_of))}</p>
<p class="subtitle">${e(currencyPositionLabel(data.currency))}</p>
<p class="preliminary">PRELIMINAR · SIN NOTAS EXPLICATIVAS COMPLETAS NI COMPARATIVOS</p>
</header>
<div class="two-columns">
<section>${table('Activo', data.as_of.slice(0, 4), data.currency, assetBody)}</section>
<section>${table('Pasivo y Patrimonio', data.as_of.slice(0, 4), data.currency, liabilitiesBody)}</section>
</div>
<footer class="notes">
<p><strong>Diferencia contable exacta:</strong> ${e(model.difference)} ${e(data.currency)}.
Notas con «—»: sin referencia documental vinculada. Importes visualizados a 2 decimales, cálculos con 4.</p>
<ul>${warnings}</ul>
</footer></body></html>`;
}

export function openFinancialPositionPrintDialog(input: FinancialPositionPrintInput): boolean {
  const popup = window.open('', '_blank');
  if (!popup) return false;
  popup.opener = null;
  popup.addEventListener('load', () => { popup.focus(); popup.print(); }, { once: true });
  popup.document.open();
  popup.document.write(buildFinancialPositionPrintHtml(input));
  popup.document.close();
  return true;
}
