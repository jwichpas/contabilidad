<script setup lang="ts">
import { computed, onScopeDispose, ref, shallowRef, watch } from 'vue';
import { AlertCircle, CalendarRange, FileSpreadsheet, Printer, RefreshCw } from 'lucide-vue-next';
import { AccountingApiClient } from '../api/httpClient';
import type { CompanyId } from '../types/accountingAccount.types';
import type { AnnualCashFlowReport } from '../types/cashFlow.types';
import { buildAnnualCashFlowMatrix, formatMatrixAmount } from '../utils/annualCashFlowMatrix';
import { openAnnualCashFlowPrint } from '../utils/printAnnualCashFlow';

const props = defineProps<{
  readonly activeCompanyId: CompanyId;
  readonly companyName: string;
  readonly companyTaxId: string;
  readonly getAccessToken: () => string | null;
}>();
const year = ref(new Date().getFullYear());
const report = shallowRef<AnnualCashFlowReport | null>(null);
const pending = shallowRef(false);
const error = shallowRef<string | null>(null);
const printError = shallowRef<string | null>(null);
let requestVersion = 0;
const client = computed(() => new AccountingApiClient('', {
  getAccessToken: props.getAccessToken,
  getActiveCompanyId: () => props.activeCompanyId,
}));
const months = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
const rows = computed(() => report.value ? buildAnnualCashFlowMatrix(report.value) : []);
const notDetailedCodes = ['1198','1298','1498','1598','1798','1898'];
const incompleteDetail = computed(() =>
  rows.value.some((row) => notDetailedCodes.includes(row.code) && row.total !== '0.0000'));
const format = formatMatrixAmount;

async function loadYear(): Promise<void> {
  const y = Number(year.value);
  if (!Number.isInteger(y) || y < 2000 || y > 2100) {
    error.value = 'Indica un año entre 2000 y 2100.';
    return;
  }
  const version = ++requestVersion;
  const tenant = props.activeCompanyId;
  report.value = null;
  error.value = null;
  pending.value = true;
  printError.value = null;
  try {
    const result = await client.value.fetchAnnualCashFlowReport(y);
    if (version === requestVersion && tenant === props.activeCompanyId) report.value = result;
  } catch (cause) {
    if (version === requestVersion && tenant === props.activeCompanyId)
      error.value = cause instanceof Error ? cause.message : 'No se pudo consultar el estado anual.';
  } finally {
    if (version === requestVersion) pending.value = false;
  }
}
function printYear(): void {
  if (!report.value || pending.value) return;
  const ok = openAnnualCashFlowPrint({
    year: report.value.year,
    company: props.companyName,
    taxId: props.companyTaxId,
    currency: report.value.currency,
    rows: rows.value,
    classified: report.value.classification_complete && !incompleteDetail.value,
    reconciled: report.value.is_reconciled,
  });
  printError.value = ok ? null : 'Habilita las ventanas emergentes para imprimir o guardar el PDF.';
}
function exportCsv(): void {
  if (!report.value) return;
  const header = ['Código', 'Concepto', ...months, 'Total'];
  const cell = (value: string) => '"' + value.replace(/"/g, '""') + '"';
  const csv = [header, ...rows.value.map((row) => [row.code, row.label, ...row.values, row.total])]
    .map((row) => row.map(cell).join(';')).join('\r\n');
  const file = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(file);
  const link = document.createElement('a');
  link.href = url;
  link.download = `flujo-efectivo-${report.value.year}-preliminar.csv`;
  link.click();
  URL.revokeObjectURL(url);
}

watch(() => props.activeCompanyId, () => { void loadYear(); }, { immediate: true, flush: 'sync' });
onScopeDispose(() => { requestVersion++; });
</script>

<template>
  <section class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0 max-w-2xl">
        <p class="text-xs font-medium text-brand">Estado de flujos de efectivo · Método directo</p>
        <h2 class="mt-1 flex items-center gap-2 text-lg font-semibold"><CalendarRange class="h-5 w-5" /> Formato anual detallado</h2>
        <p class="mt-2 text-sm text-muted">Enero a diciembre, códigos de presentación referenciales, subtotales de cada actividad y conciliación mensual.</p>
      </div>
      <form class="flex flex-wrap items-end gap-2" @submit.prevent="loadYear">
        <label for="annual-cash-flow-year" class="flex flex-col gap-1 text-xs text-muted">Ejercicio
          <input id="annual-cash-flow-year" v-model.number="year" type="number" min="2000" max="2100"
            class="w-28 rounded-lg border border-border bg-surface px-3 py-2 text-sm text-foreground" />
        </label>
        <button type="submit" :disabled="pending" class="inline-flex min-h-10 items-center gap-2 rounded-lg bg-brand px-3 py-2 text-sm font-medium text-white disabled:opacity-40">
          <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': pending }" /> Consultar
        </button>
        <button type="button" :disabled="pending || !report" @click="printYear"
          class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-border bg-surface px-3 py-2 text-sm font-medium disabled:opacity-40">
          <Printer class="h-4 w-4" /> PDF horizontal
        </button>
        <button type="button" :disabled="pending || !report" @click="exportCsv"
          class="inline-flex min-h-10 items-center gap-2 rounded-lg border border-border bg-surface px-3 py-2 text-sm font-medium disabled:opacity-40">
          <FileSpreadsheet class="h-4 w-4" /> CSV
        </button>
      </form>
    </div>

    <p v-if="printError" role="alert" class="text-sm text-danger">{{ printError }}</p>
    <div v-if="pending" role="status" class="rounded-lg border border-border p-8 text-sm text-muted">Consultando movimientos y conciliación de los doce meses…</div>
    <p v-else-if="error" role="alert" class="rounded-lg border border-danger p-4 text-sm text-danger">{{ error }}</p>
    <template v-else-if="report">
      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div v-for="card in [
          { label: 'Efectivo inicial', value: report.totals.opening_local },
          { label: 'Operación', value: report.totals.operating_local },
          { label: 'Financiación', value: report.totals.financing_local },
          { label: 'Efectivo final', value: report.totals.closing_local },
        ]" :key="card.label" class="rounded-lg border border-border bg-surface p-3">
          <p class="text-xs text-muted">{{ card.label }}</p>
          <p class="mt-2 font-mono text-lg font-semibold tabular-nums">{{ format(card.value) }}</p>
        </div>
      </div>

      <div v-if="!report.is_reconciled || !report.classification_complete || incompleteDetail"
        role="note" class="flex items-start gap-2 rounded-lg border border-amber-500/40 bg-surface p-3 text-sm">
        <AlertCircle class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
        <div>
          <strong>Presentación pendiente de revisión.</strong>
          <p v-if="incompleteDetail" class="mt-1 text-muted">
            Hay cobros o pagos cuyo tipo exacto (ventas, proveedores, préstamos, etc.) no consta en el código del área.
            Permanecen visibles en «pendientes de desagregación»; no se han atribuido por la glosa ni por la cuenta contrapartida.
          </p>
          <p v-if="!report.classification_complete" class="mt-1 text-muted">
            Existen movimientos sin clasificación o ajustes que impiden confirmar el flujo completo.
          </p>
          <p v-if="!report.is_reconciled" class="mt-1 text-muted">Revisa la conciliación antes de emitir un informe externo.</p>
        </div>
      </div>

      <div class="rounded-lg border border-border bg-surface">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-3 text-xs text-muted">
          <span>Empresa: {{ companyName }} · RUC {{ companyTaxId }} · {{ report.year }} · {{ report.currency }} · 4 decimales exactos</span>
          <span>Desplaza horizontalmente para ver los 12 meses y el total anual</span>
        </div>
        <div class="max-w-full overflow-x-auto">
          <table class="w-full min-w-[1450px] border-collapse text-[11px] tabular-nums" aria-label="Estado de flujos de efectivo anual">
            <thead>
              <tr class="bg-soft text-muted">
                <th scope="col" class="sticky left-0 z-20 w-16 border-b border-r border-border bg-soft px-2 py-3 text-left">Código</th>
                <th scope="col" class="sticky left-16 z-20 min-w-64 border-b border-r border-border bg-soft px-3 py-3 text-left">Concepto</th>
                <th v-for="month in months" :key="month" scope="col" class="min-w-24 border-b border-border px-2 py-3 text-right">{{ month }}</th>
                <th scope="col" class="min-w-28 border-b border-l border-border px-3 py-3 text-right">Total</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in rows" :key="item.code"
                :class="{
                  'font-semibold text-foreground': ['section','subtotal','summary','balance'].includes(item.kind),
                  'text-muted': ['detail','subheading'].includes(item.kind),
                }">
                <th scope="row" class="sticky left-0 z-10 border-b border-r border-border px-2 py-2 text-left font-mono"
                  :class="['section','summary'].includes(item.kind) ? 'bg-brand/10' : item.kind === 'balance' ? 'bg-soft' : 'bg-surface'">{{ item.code }}</th>
                <td class="sticky left-16 z-10 border-b border-r border-border px-3 py-2"
                  :class="['section','summary'].includes(item.kind) ? 'bg-brand/10' : item.kind === 'balance' ? 'bg-soft' : 'bg-surface'">
                  {{ item.label }}
                </td>
                <td v-for="(value, index) in item.values" :key="index" class="border-b border-border px-2 py-2 text-right font-mono"
                  :class="item.kind === 'summary' ? 'bg-brand/10' : item.kind === 'balance' ? 'bg-soft' : ''">
                  {{ ['section','subheading'].includes(item.kind) ? '' : format(value) }}
                </td>
                <td class="border-b border-l border-border px-3 py-2 text-right font-mono font-semibold"
                  :class="item.kind === 'summary' ? 'bg-brand/10' : item.kind === 'balance' ? 'bg-soft' : ''">
                  {{ ['section','subheading'].includes(item.kind) ? '' : format(item.total) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <p class="text-xs leading-relaxed text-muted">
        Los códigos se utilizan únicamente para esta presentación referencial inspirada en el Formato 3.18.
        Para detallar una actividad, configura su área de flujo con el código específico (por ejemplo, F318-1102 para cobros por ventas)
        antes de registrar los movimientos. Cambiar una etiqueta no reclasifica asientos históricos.
        La impresión es preliminar y no reemplaza un archivo PLE homologado ni las revelaciones requeridas por NIC 7.
      </p>
    </template>
  </section>
</template>
