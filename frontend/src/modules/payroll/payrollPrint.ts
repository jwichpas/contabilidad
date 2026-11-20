import { formatExactLocalAmount } from '@/modules/accounting/utils/formatExactLocalAmount';
import { formatAccountingDate } from '@/modules/accounting/utils/journalPresentation';
import { escapeStatementText as escape } from '@/modules/accounting/utils/printStatementTable';
import type { Payroll, PayrollRow } from './payroll.types';

export type PayrollPrintEmployer = { name: string; ruc: string; address?: string | null };

const afpNames: Record<string, string> = { HA: 'Hábitat', IN: 'Integra', PR: 'Profuturo', RI: 'Prima' };

function money(amount?: string | null) {
  return amount == null ? '—' : formatExactLocalAmount(amount, 'PEN').replace(/^PEN (.*,[0-9]{2})00$/, 'S/ $1');
}

function hours(minutes: number) {
  return `${Math.floor(minutes / 60)} h ${String(minutes % 60).padStart(2, '0')} min`;
}

function name(row: PayrollRow) {
  const { given_names, surname, second_surname } = row.worker;
  return [given_names, surname, second_surname].filter(Boolean).join(' ');
}

function date(value?: string | null) {
  return value ? formatAccountingDate(value) : '—';
}

function pension(row: PayrollRow) {
  return row.worker.pension === 'AFP' ? afpNames[row.worker.afp ?? ''] ?? 'AFP' : 'ONP';
}

function readyHours(row: PayrollRow, payroll: Payroll) {
  return payroll.input_mode !== 'independent' || row.attendance.jornada_confirmed;
}

type Pair = readonly [label: string, value: string];
function fields(pairs: readonly Pair[]) {
  return '<dl>'+pairs.map(([label, value]) => `<div><dt>${escape(label)}</dt><dd>${escape(value)}</dd></div>`).join('')+'</dl>';
}
function table(headers: string[], rows: string[][], totals?: string[]) {
  const cells = (values: string[], tag: 'th' | 'td') =>
    values.map(value => `<${tag}>${escape(value)}</${tag}>`).join('');
  return `<table><thead><tr>${cells(headers, 'th')}</tr></thead>
    <tbody>${rows.map(row => '<tr>'+cells(row, 'td')+'</tr>').join('')}</tbody>
    ${totals ? '<tfoot><tr>'+cells(totals, 'td')+'</tr></tfoot>' : ''}</table>`;
}
function lines(title: string, pairs: Pair[]) {
  return `<section><h2>${title}</h2>${table(['Concepto', 'Importe en soles'], pairs.map(([label, amount]) => [label, money(amount)]))}</section>`;
}

function document(title: string, employer: PayrollPrintEmployer, month: string, status: string, body: string, landscape = false) {
  return `<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>${escape(title)} - ${escape(month)}</title>
    <style>
      @page{size:${landscape ? 'A3 landscape' : 'A4 portrait'};margin:12mm}
      *{box-sizing:border-box}
      body{font:11px Arial,Helvetica,sans-serif;color:#202032;margin:12px;padding:0}
      @media print{body{margin:0}}
      header{display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px;border-bottom:2px solid #5b36a2;padding-bottom:12px}
      .eyebrow{color:#5b36a2;font-weight:bold;text-transform:uppercase;letter-spacing:.07em}
      h1{font-size:23px;margin:3px 0 7px}h2{font-size:12px;margin:0 0 5px}
      .meta{margin:2px 0;color:#505064}.status{padding:6px 8px;background:#f1ebfb;color:#4b287e;font-weight:bold;align-self:flex-start}
      section{margin-top:12px;break-inside:avoid} dl{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px 12px;margin:8px 0}
      dt{color:#585768;font-size:10px}dd{margin:2px 0 0;font-weight:600;overflow-wrap:anywhere}
      table{width:100%;border-collapse:collapse;font-size:10px}
      th,td{padding:5px 4px;border-bottom:1px solid #dad6e4;text-align:left;overflow-wrap:anywhere}
      th{background:#f3effa}td:last-child,th:last-child{text-align:right}tr{break-inside:avoid}
      .report{overflow-x:auto}.report th{min-width:40px}.report td{white-space:nowrap;font-variant-numeric:tabular-nums}
      .report td:nth-child(3),.report td:nth-child(4){white-space:normal;min-width:72px}
      @media print{.report{overflow:visible;break-inside:auto}}
      thead{display:table-header-group}tfoot{display:table-row-group;font-weight:bold;background:#f3effa}
      .net{background:#f3effa;border:1px solid #dacaf1;display:flex;justify-content:space-between;padding:9px 11px;margin-top:12px;font-size:15px}
      .signature{margin-top:21px;padding-top:8px;border-top:1px solid #aaa;display:flex;justify-content:space-between;color:#505064}
      .note{font-size:10px;color:#4b287e;margin:8px 0}
    </style></head><body><header><div><div class="eyebrow">Remuneraciones · ${escape(month)}</div><h1>${escape(title)}</h1>
    <strong>${escape(employer.name)}</strong><p class="meta">RUC ${escape(employer.ruc)}</p>
    ${employer.address ? '<p class="meta">'+escape(employer.address)+'</p>' : ''}</div><span class="status">${escape(status)}</span>
    </header>${body}</body></html>`;
}

export function payslipMissing(row: PayrollRow, payroll: Payroll) {
  const profile = row.worker.payslip_profile;
  return [
    !profile?.sex && 'sexo', !profile?.birth_date && 'fecha de nacimiento', !profile?.address && 'domicilio',
    !profile?.nationality && 'nacionalidad', row.worker.pension === 'AFP' && !row.worker.cuspp && 'CUSPP',
    row.attendance.worked_days == null && 'días trabajados', !readyHours(row, payroll) && 'horas trabajadas',
    row.attendance.vacation_paid_days > 0 && !row.vacation_periods?.length && 'fechas de vacaciones',
  ].filter(Boolean).join(', ');
}

export function buildPayslipHtml(employer: PayrollPrintEmployer, month: string, payroll: Payroll, row: PayrollRow, stale = false) {
  const { worker, attendance, amounts } = row;
  const profile = worker.payslip_profile;
  const missing = payslipMissing(row, payroll);
  const ready = payroll.approved && !missing;
  const dates = row.vacation_periods?.map(({ from, to }) => date(from)+' al '+date(to)).join(' · ');
  const pensionLines: Pair[] = amounts.afp_contribution ? [
    ['Aporte al fondo AFP', amounts.afp_contribution], ['Prima AFP', amounts.afp_insurance ?? '0'],
    ['Comisión sobre remuneración', amounts.afp_commission ?? '0'],
  ] : [[pension(row), amounts.pension ?? '0']];
  const workerFields = fields([
    ['Trabajador', name(row)], ['DNI', worker.dni], ['Código interno', worker.code],
    ['Cargo', worker.position], ['Fecha de ingreso', date(worker.hired_on)], ['Fecha de cese', date(worker.ended_on)],
    ['Sexo', profile?.sex === 'F' ? 'Femenino' : profile?.sex === 'M' ? 'Masculino' : '—'],
    ['Fecha de nacimiento', date(profile?.birth_date)], ['Nacionalidad', profile?.nationality ?? '—'],
    ['Domicilio', profile?.address ?? '—'], ['Sistema previsional', pension(row)], ['CUSPP', worker.cuspp ?? '—'],
  ]);
  const workFields = fields([
    ['Días pagados', String(attendance.paid_days)], ['Días trabajados', String(attendance.worked_days ?? '—')],
    ['Horas ordinarias', readyHours(row, payroll) ? hours(attendance.ordinary_minutes) : '—'],
    ['Horas extra', readyHours(row, payroll) ? hours(Number(attendance.overtime_25) + Number(attendance.overtime_35)) : '—'],
    ['Días de vacaciones pagados', String(attendance.vacation_paid_days)], ['Periodo de vacaciones', dates || '—'],
  ]);
  const body = `<section><h2>Datos del trabajador</h2>${workerFields}</section>
    <section><h2>Jornada</h2>${workFields}</section>
    ${lines('Ingresos', [
      ['Básico devengado', amounts.basic ?? '0'], ['Asignación familiar', amounts.family_allowance ?? '0'],
      ['Horas extra', amounts.overtime ?? '0'], ['Otros ingresos afectos', attendance.taxable_extra],
      ['Otros ingresos inafectos', attendance.nontaxable_extra], ['Total ingresos', amounts.gross ?? '0'],
    ])}
    ${lines('Descuentos del trabajador', [...pensionLines, ['Quinta categoría', amounts.income_tax ?? '0'], ['Otros descuentos', amounts.other_deductions ?? '0']])}
    ${lines('Aportes del empleador', [['EsSalud', amounts.essalud ?? '0'], ...(amounts.employer_insurance ? [['Póliza de seguro', amounts.employer_insurance] as const] : [])])}
    <div class="net"><strong>Neto a pagar</strong><strong>${escape(money(amounts.net))}</strong></div>
    ${stale ? '<p class="note">Importes del cálculo anterior. Recalcula la planilla.</p>' : ''}
    ${missing ? '<p class="note">Datos pendientes: '+escape(missing)+'.</p>' : ''}
    ${ready ? '<div class="signature"><span>Firma del empleador</span><span>Firma o constancia de recepción</span></div>' : ''}`;
  return document('Boleta de pago', employer, month, ready ? 'Planilla aprobada' : 'Vista previa', body);
}

export function buildPayrollReportHtml(employer: PayrollPrintEmployer, month: string, payroll: Payroll, stale = false) {
  const rows = payroll.rows.map(row => {
    const { worker, attendance, amounts } = row;
    return [worker.code, worker.dni, name(row), worker.position, date(worker.hired_on), money(worker.salary),
      String(attendance.worked_days ?? '—'), readyHours(row, payroll) ? hours(attendance.ordinary_minutes) : '—',
      String(attendance.vacation_paid_days), String(attendance.paid_days), money(amounts.basic), money(amounts.family_allowance ?? '0'),
      money(amounts.gross), pension(row), money(amounts.afp_contribution), money(amounts.afp_insurance), money(amounts.afp_commission),
      money(amounts.pension), money(amounts.income_tax), money(amounts.other_deductions), money(amounts.net),
      money(amounts.employer_insurance), money(amounts.essalud)];
  });
  const totals = ['', '', 'Totales', ...Array<string>(7).fill(''), money(payroll.totals.basic), money(payroll.totals.family_allowance),
    money(payroll.totals.gross), '', money(payroll.totals.afp_contribution), money(payroll.totals.afp_insurance),
    money(payroll.totals.afp_commission), money(payroll.totals.pension), money(payroll.totals.income_tax), money(payroll.totals.other_deductions),
    money(payroll.totals.net), money(payroll.totals.employer_insurance), money(payroll.totals.essalud)];
  const content = table(['Cód.', 'DNI', 'Trabajador', 'Cargo', 'Ingreso', 'Sueldo contractual', 'Días trabajados', 'Horas ordinarias',
    'Días vacaciones', 'Días pagados', 'Básico', 'Asignación familiar', 'Total ingresos', 'Pensión', 'Aporte AFP', 'Prima AFP',
    'Comisión AFP', 'Total pensión', 'Quinta', 'Otros descuentos', 'Neto a pagar', 'Póliza', 'EsSalud'], rows, totals);
  return document('Planilla de remuneraciones', employer, month, payroll.approved ? 'Planilla aprobada' : 'Borrador',
    '<section class="report">'+content+'</section>'+(stale ? '<p class="note">Importes del cálculo anterior. Recalcula la planilla.</p>' : ''), true);
}

export function printPayrollDocument(html: string) {
  const popup = window.open('', '_blank');
  if (!popup) return false;
  popup.opener = null;
  popup.document.open();
  popup.document.write(html);
  popup.document.close();
  popup.focus();
  popup.print();
  return true;
}
