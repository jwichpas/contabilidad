import type { FinancialSnapshot } from '../types/financialSnapshot.types';
import type { FinancialReportLayoutSettings } from '../types/financialLayout.types';
import {
  buildComprehensiveIncomePresentation, formatIncomeAmount, formatIncomePercent,
  formatIncomePeriod,
} from './comprehensiveIncomePresentation.ts';
import { currencyPositionLabel } from './financialPositionPresentation.ts';

export interface ComprehensiveIncomePrintInput {
  readonly snapshot: FinancialSnapshot;
  readonly companyName: string;
  readonly companyTaxId: string;
  readonly companyAddress: string;
  readonly layout?: FinancialReportLayoutSettings | null;
  readonly layoutRevision?: number;
}
function escapeHtml(text: string): string {
  return text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
export function buildComprehensiveIncomePrintHtml(input: ComprehensiveIncomePrintInput): string {
  const r = buildComprehensiveIncomePresentation(input.snapshot,input.layout);
  const netSales = r.lines.find((line) => line.key === 'net_sales')?.amount ?? '0.0000';
  const body = r.lines.map((line) => {
    const kind = escapeHtml(line.kind);
    const negative = line.amount !== null && line.amount.startsWith('-');
    return '<tr class="' + kind + (negative ? ' negative' : '') + '"><th scope="row">'
      + escapeHtml(line.label) + '</th><td class="note">—</td><td class="amount">'
      + escapeHtml(formatIncomeAmount(line.amount)) + '</td><td class="percent">'
      + escapeHtml(formatIncomePercent(line.amount, netSales)) + '</td></tr>';
  }).join('');
  const warnings = r.warnings.map((warning) => '<li>' + escapeHtml(warning) + '</li>').join('');
  const e = escapeHtml;
  return `<!doctype html><html lang="es"><head><meta charset="utf-8">
<title>Estado de Resultados Integrales ${e(r.to)} - ${e(input.companyName)}</title>
<style>
@page { size: A4 portrait; margin: 16mm 18mm; }
* { box-sizing: border-box; }
body { font: 11px Arial, Helvetica, sans-serif; color: #171b22; background: #fff; margin: 0; line-height: 1.4; }
header { margin-bottom: 25px; }
h1 { margin: 14px 0 3px; font-size: 14px; }
.company { font-size: 13px; font-weight: 700; text-transform: uppercase; overflow-wrap: anywhere; }
.identity { margin: 3px 0; font-weight: 600; }
.subtitle { margin: 3px 0; }
.warning { color: #805317; }
.notice { margin-top: 8px; color: #805317; font-size: 9px; font-weight: bold; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 10px; }
col.note-col { width: 42px; } col.amount-col { width: 100px; } col.percent-col { width: 77px; }
th, td { padding: 5px 4px; }
thead th { border-bottom: 1px solid #525252; text-align: left; }
thead th.note { text-align: center; }
thead th.amount,thead th.percent { text-align: right; }
tbody th { text-align: left; font-weight: normal; overflow-wrap: anywhere; }
.note { text-align: center; }
.amount,.percent { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.subtotal { border-top: 1px solid #555; font-weight: 700; background: #f6f6f6; }
.subtotal th,.total th { font-weight: 700; }
.total { border-top: 2px solid #555; border-bottom: 2px solid #555; font-weight: 700; background: #f6f6f6; }
.negative .amount,.negative .percent { color: #bb2727; }
.warning th,.warning td { color: #805317; }
footer { border-top: 1px solid #b6b6b6; margin-top: 25px; padding-top: 8px; font-size: 9px; }
footer ul { padding-left: 18px; }
footer li { margin-top: 4px; }
tr { break-inside: avoid; }
@media screen { body { max-width: 780px; margin: 0 auto; padding: 20px; } }
</style></head><body>
<header>
<div class="company">${e(input.companyName)}</div>
<p class="identity">RUC ${e(input.companyTaxId)}</p>
${input.companyAddress ? '<p class="identity">' + e(input.companyAddress) + '</p>' : ''}
<h1>Estado de Resultados Integrales</h1>
<p class="subtitle">${e(formatIncomePeriod(r.from,r.to))}</p>
<p class="subtitle">${e(currencyPositionLabel(r.currency))}</p>
<p class="subtitle">Revisión de estructura de empresa: ${input.layoutRevision ?? 0}</p>
<p class="notice">PRELIMINAR · RESULTADOS INTEGRALES TOTALES PENDIENTES DE VALIDAR ORI</p>
</header>
<table aria-label="Estado de Resultados Integrales">
<colgroup><col><col class="note-col"><col class="amount-col"><col class="percent-col"></colgroup>
<thead><tr><th>Concepto</th><th class="note">Nota</th><th class="amount">${e(r.to.slice(0,4))} · ${e(r.currency)}</th><th class="percent">% ventas</th></tr></thead>
<tbody>${body}</tbody>
</table>
<footer><p>Notas con «—»: no existe todavía una nota explicativa vinculada. Importes originales a cuatro decimales; presentación a dos.</p><ul>${warnings}</ul>
</footer></body></html>`;
}
export function openComprehensiveIncomePrintDialog(input: ComprehensiveIncomePrintInput): boolean {
  const popup = window.open('', '_blank');
  if (!popup) return false;
  popup.opener = null;
  popup.addEventListener('load', () => { popup.focus(); popup.print(); }, { once: true });
  popup.document.open();
  popup.document.write(buildComprehensiveIncomePrintHtml(input));
  popup.document.close();
  return true;
}
