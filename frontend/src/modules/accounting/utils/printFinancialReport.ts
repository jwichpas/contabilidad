/** Vista de impresion autocontenida; el navegador permite Guardar como PDF.
 * No genera un informe auditado ni incorpora estados aun no implementados.
 * Los textos provenientes de la API se escapan antes de insertarlos en HTML.
 */
export interface PrintableReportRow {
  readonly label: string;
  readonly value: string;
}
export interface PrintableReportSection {
  readonly heading: string;
  readonly rows: readonly PrintableReportRow[];
}
export interface PrintableFinancialReport {
  readonly title: string;
  readonly company: string;
  readonly period: string;
  readonly currency: string;
  readonly sections: readonly PrintableReportSection[];
  readonly notices: readonly string[];
}

function escapeHtml(value: string): string {
  return value.replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

export function buildFinancialReportPrintHtml(report: PrintableFinancialReport): string {
  const e = escapeHtml;
  const sections = report.sections.map((section) => `
    <section>
      <h2>${e(section.heading)}</h2>
      <table><tbody>${section.rows.map((row) => `
        <tr><th scope="row">${e(row.label)}</th><td>${e(row.value)}</td></tr>
      `).join('')}</tbody></table>
    </section>
  `).join('');
  const notices = report.notices.map((notice) => `<li>${e(notice)}</li>`).join('');
  return `<!doctype html>
<html lang="es"><head><meta charset="utf-8">
<title>${e(report.title)} - ${e(report.company)}</title>
<style>
@page { size: A4; margin: 15mm; }
* { box-sizing: border-box; }
body { color: #172033; background: white; font: 12px Arial, Helvetica, sans-serif; line-height: 1.5; margin: 0; }
header { border-bottom: 2px solid #27355b; padding-bottom: 14px; margin-bottom: 22px; }
h1 { font-size: 20px; margin: 9px 0 4px; }
h2 { color: #27355b; font-size: 13px; margin: 22px 0 7px; }
p { margin: 3px 0; }
.kicker { font-size: 10px; letter-spacing: 1px; text-transform: uppercase; color: #596579; }
.flag { color: #7c2d12; background: #fff6ed; border: 1px solid #d6a377; display: inline-block; padding: 3px 8px; font-size: 10px; font-weight: bold; }
.meta { color: #596579; }
table { width: 100%; border-collapse: collapse; font-size: 11px; }
th, td { border-bottom: 1px solid #dce2eb; padding: 7px 9px; }
th { text-align: left; font-weight: 500; overflow-wrap: anywhere; }
td { width: 34%; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; font-weight: 600; }
tr:last-child th,tr:last-child td { border-bottom: 2px solid #c3ccda; }
section { break-inside: avoid-page; }
footer { margin-top: 26px; padding-top: 10px; border-top: 1px solid #c3ccda; color: #505b70; font-size: 10px; }
ul { padding-left: 19px; }
li { margin-top: 4px; }
@media screen { body { max-width: 820px; padding: 25px 30px; margin: 0 auto; } }
</style></head>
<body>
<header><span class="kicker">Libro Mayor Perú · Reportes contables</span>
<h1>${e(report.title)}</h1>
<p>${e(report.company)}</p>
<p class="meta">Periodo: ${e(report.period)} · Moneda: ${e(report.currency)}</p>
<p class="flag">PRELIMINAR · DOCUMENTO DE PRUEBA / REVISIÓN</p></header>
${sections}
<footer><strong>Advertencias y alcance:</strong><ul>${notices}</ul>
<p>Impreso desde la vista del sistema; no acredita cumplimiento NIIF, auditoría ni presentación SUNAT.</p></footer>
</body></html>`;
}

/** Abre la ventana de impresion. En el dialogo seleccionar Guardar como PDF. */
export function openFinancialReportPrintDialog(report: PrintableFinancialReport): boolean {
  const popup = window.open('', '_blank');
  if (!popup) return false;
  popup.opener = null;
  popup.addEventListener('load', () => { popup.focus(); popup.print(); }, { once: true });
  popup.document.open();
  popup.document.write(buildFinancialReportPrintHtml(report));
  popup.document.close();
  return true;
}
