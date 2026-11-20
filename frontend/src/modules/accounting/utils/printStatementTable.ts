/** PDF through native browser print; values and labels are HTML-escaped. */
export interface PrintStatementTable {
  readonly company:string;readonly taxId:string;readonly title:string;
  readonly period:string;readonly currency:string;
  readonly columns:readonly string[];readonly rows:readonly (readonly string[])[];
  readonly notices:readonly string[];
  readonly landscape?:boolean;
}
export function escapeStatementText(value:string):string {
  return value.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
export function buildStatementTableHtml(data:PrintStatementTable):string {
  const e=escapeStatementText;
  const cells=data.rows.map((row)=>'<tr>'+row.map((value,i)=>
    '<td'+(i>0?' class="numeric"':'')+'>'+e(value)+'</td>').join('')+'</tr>').join('');
  const heads=data.columns.map((label)=>'<th>'+e(label)+'</th>').join('');
  const notes=data.notices.map((n)=>'<li>'+e(n)+'</li>').join('');
  return `<!doctype html><html lang="es"><head><meta charset="UTF-8">
<title>${e(data.title)} - ${e(data.company)}</title>
<style>
@page{size:A4 ${data.landscape?'landscape':'portrait'};margin:12mm}
body{font:10px Arial,Helvetica,sans-serif;color:#172033}
h1{font-size:15px;margin:15px 0 5px}.company{font-size:12px;font-weight:700;text-transform:uppercase}
.meta{margin:3px 0}.preliminary{font-weight:700;color:#8c4c19;margin:12px 0}
table{width:100%;border-collapse:collapse;table-layout:auto;font-size:9px}
th,td{padding:6px 4px;border-bottom:1px solid #ccc;overflow-wrap:anywhere}
th{text-align:left;background:#eceef2}.numeric{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
tr{break-inside:avoid}footer{border-top:1px solid #aaa;font-size:9px;margin-top:20px;padding-top:8px}
</style></head><body><header><div class="company">${e(data.company)}</div>
<div class="meta">RUC ${e(data.taxId)}</div>
<h1>${e(data.title)}</h1><p class="meta">${e(data.period)} · ${e(data.currency)}</p>
<div class="preliminary">PRELIMINAR · NO VALIDADO PARA PLE/SUNAT NI ESTADOS AUDITADOS</div></header>
<table><thead><tr>${heads}</tr></thead><tbody>${cells}</tbody></table>
<footer><strong>Advertencias:</strong><ul>${notes}</ul></footer></body></html>`;
}
export function openStatementTablePrint(data:PrintStatementTable):boolean {
  const popup=window.open('','_blank');
  if(!popup)return false;
  popup.opener=null;
  popup.addEventListener('load',()=>{popup.focus();popup.print();},{once:true});
  popup.document.open();
  popup.document.write(buildStatementTableHtml(data));
  popup.document.close();
  return true;
}
