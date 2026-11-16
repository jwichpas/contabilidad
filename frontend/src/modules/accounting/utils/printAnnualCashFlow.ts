import type { AnnualCashFlowMatrixRow } from './annualCashFlowMatrix';
import { formatMatrixAmount } from './annualCashFlowMatrix.ts';

export interface AnnualCashFlowPrintInput {
  readonly year: number;
  readonly throughMonth: number;
  readonly presentationRevision?: number;
  readonly company: string;
  readonly taxId?: string;
  readonly currency: string;
  readonly rows: readonly AnnualCashFlowMatrixRow[];
  readonly classified: boolean;
  readonly reconciled: boolean;
}

const MONTHS = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio',
  'Agosto','Setiembre','Octubre','Noviembre','Diciembre'];
function safe(value: string): string {
  return value.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

/** Hoja horizontal completa; nunca confundir esta impresión con PLE SUNAT. */
export function buildAnnualCashFlowPrintHtml(input: AnnualCashFlowPrintInput): string {
  const header = MONTHS.map((month) => '<th scope="col">' + safe(month) + '</th>').join('');
  const body = input.rows.map((row) =>
    '<tr class="' + safe(row.kind) + '"><th scope="row">' + safe(row.code) + '</th>'
    + '<td class="concept">' + safe(row.label) + '</td>'
    + row.values.map((value, index) => '<td class="amount">'
      + (index < input.throughMonth ? safe(formatMatrixAmount(value)) : '') + '</td>').join('')
    + '<td class="amount total">' + safe(formatMatrixAmount(row.total)) + '</td></tr>'
  ).join('');
  const notices = [
    'Reporte preliminar basado en los asientos y áreas de flujo registrados de la empresa.',
    'Los cobros y pagos sin subclasificación exacta se presentan como pendientes de desagregación.',
    'Los códigos son de presentación referencial: este documento no es un archivo PLE validado para SUNAT.',
    ...(input.classified ? [] : ['Existen movimientos sin clasificar o ajustes por revisar.']),
    ...(input.reconciled ? [] : ['Existen diferencias pendientes de conciliación.']),
  ].map((notice) => '<li>' + safe(notice) + '</li>').join('');
  return `<!doctype html><html lang="es"><head><meta charset="utf-8">
<title>Flujo de efectivo ${input.year} · ${safe(input.company)}</title>
<style>
@page { size: A3 landscape; margin: 10mm; }
* { box-sizing: border-box; }
body { margin: 0; color: #202633; background: white; font: 9px Arial, sans-serif; }
h1 { font-size: 16px; margin: 7px 0; }
p { margin: 2px 0; }
.badge { display: inline-block; font-weight: bold; padding: 3px 6px; border: 1px solid #b9a37e; }
header { margin-bottom: 12px; }
table { border-collapse: collapse; width: 100%; table-layout: fixed; }
th, td { border: 1px solid #c7d0db; padding: 4px 2px; overflow-wrap: anywhere; }
thead th { background: #e9eef6; font-size: 8px; }
thead th:nth-child(1), tbody th { width: 42px; }
thead th:nth-child(2), .concept { width: 205px; text-align: left; }
tbody th { text-align: center; font-weight: normal; }
.amount { text-align: right; white-space: nowrap; font: 8px Consolas, monospace; letter-spacing: -.25px; }
.total { font-weight: bold; }
.section { background: #e9f2ff; font-weight: bold; }
.subheading { background: #f4f5f9; font-weight: bold; }
.subtotal { background: #f5f6f8; font-weight: bold; }
.summary { background: #fff1bc; font-weight: bold; }
.balance { background: #d8f2e3; font-weight: bold; }
.warning { background: #fff7ed; }
tr { break-inside: avoid; }
footer { margin-top: 14px; font-size: 9px; }
ul { padding-left: 15px; margin-top: 4px; }
@media screen { body { padding: 18px; min-width: 1400px; } }
</style></head><body>
<header><p>LIBRO DE INVENTARIOS Y BALANCES · REFERENCIA VISUAL FORMATO 3.18</p>
<h1>Estado de flujos de efectivo — Método directo — ${input.year} · Hasta ${MONTHS[input.throughMonth - 1] ?? ''}</h1>
<p><strong>Empresa:</strong> ${safe(input.company)}
<strong>Moneda:</strong> ${safe(input.currency)} · ${input.taxId ? '<strong>RUC:</strong> ' + safe(input.taxId) : ''}</p>
<p>Revisión de presentación por empresa: ${input.presentationRevision ?? 0}</p>
<p class="badge">PRELIMINAR · NO APTO PARA PRESENTACIÓN SUNAT</p></header>
<table><thead><tr><th>Cód.</th><th>Concepto</th>${header}<th>Total</th></tr></thead>
<tbody>${body}</tbody></table>
<footer><strong>Notas:</strong><ul>${notices}</ul></footer></body></html>`;
}

export function openAnnualCashFlowPrint(input: AnnualCashFlowPrintInput): boolean {
  const popup = window.open('', '_blank');
  if (!popup) return false;
  popup.opener = null;
  popup.addEventListener('load', () => { popup.focus(); popup.print(); }, { once: true });
  popup.document.open();
  popup.document.write(buildAnnualCashFlowPrintHtml(input));
  popup.document.close();
  return true;
}
