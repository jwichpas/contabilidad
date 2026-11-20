<script setup lang="ts">
import { ref } from 'vue';
import { Download, RefreshCw } from 'lucide-vue-next';
import type { AccountingApiClient } from '../api/httpClient';
import type { TaxBalanceAmounts, TaxCloseTrialBalance, TaxOpeningSource } from '../types/annualTaxClose.types';

const props = defineProps<{ client: AccountingApiClient; year: number }>();
const report = ref<TaxCloseTrialBalance>();
const loading = ref(false);
const error = ref('');
const columns: [keyof TaxBalanceAmounts, string][] = [
  ['opening_debit', 'Apertura Debe'], ['opening_credit', 'Apertura Haber'],
  ['movement_debit', 'Movimiento Debe'], ['movement_credit', 'Movimiento Haber'],
  ['transfer_debit', 'Transferencias Debe'], ['transfer_credit', 'Transferencias Haber'],
];

async function load(opening?: TaxOpeningSource) {
  if (loading.value) return;
  loading.value = true;
  error.value = '';
  try {
    report.value = await props.client.fetchAnnualTaxTrialBalance(props.year, opening);
  } catch (reason) {
    report.value = undefined;
    error.value = reason instanceof Error ? reason.message : 'No se pudo calcular el balance.';
  } finally {
    loading.value = false;
  }
}

function download() {
  const data = report.value;
  if (!data?.balanced || data.rows.length === 0 || data.unmapped.length > 0) return;
  const rows = [
    ['Código SUNAT', ...columns.map(([, label]) => label), 'Cuentas del mayor'],
    ...data.rows.map((row) => [row.code, ...columns.map(([key]) => row[key]), row.accounts.join(' ')]),
  ];
  // Keep leading zeros in codes and export the exact ledger amounts for review.
  const csv = '\uFEFF' + rows.map((row) => row.map((cell) => '"' + cell.replaceAll('"', '""') + '"').join(',')).join('\r\n');
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
  const link = document.createElement('a');
  link.href = url;
  link.download = `Balance-trabajo-FV710-${props.year}.csv`;
  link.click();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}
</script>

<template>
  <details class="rounded-lg border border-border bg-surface p-4">
    <summary class="cursor-pointer font-semibold">Balance de Comprobación para el FV 710</summary>
    <p class="mt-2 text-sm text-muted">Corte del mayor al 31 de diciembre. El CSV sirve para conciliar y completar la plantilla SUNAT.</p>
    <div class="mt-3 flex flex-wrap gap-2">
      <button class="ui-btn ui-btn-secondary" :disabled="loading" @click="load(report?.opening_source ?? undefined)">
        <RefreshCw /> {{ report ? 'Actualizar balance' : 'Consultar mayor' }}
      </button>
      <button v-if="report?.balanced && report.rows.length && !report.unmapped.length" class="ui-btn ui-btn-secondary" :disabled="loading" @click="download">
        <Download /> Descargar balance de trabajo
      </button>
    </div>
    <p v-if="loading" role="status" class="mt-2 text-sm text-muted">Conciliando el mayor…</p>
    <p v-if="error" role="alert" class="mt-2 text-sm text-danger">{{ error }}</p>
    <template v-if="report">
      <div v-if="report.historical_opening_available && report.entry_opening_available" class="mt-3 flex flex-wrap items-center gap-2">
        <p class="text-sm">Hay saldos históricos y asientos de apertura. Elija el origen que corresponde.</p>
        <button class="ui-btn ui-btn-secondary" :disabled="loading || report.opening_source === 'historical'" @click="load('historical')">Saldos históricos</button>
        <button class="ui-btn ui-btn-secondary" :disabled="loading || report.opening_source === 'entry'" @click="load('entry')">Asientos de apertura</button>
      </div>
      <p v-if="report.opening_ambiguous" role="status" class="mt-2 text-sm text-muted">Saldos iniciales pendientes de selección.</p>
      <p v-else-if="!report.balanced" role="alert" class="mt-2 text-sm text-danger">El mayor presenta diferencias entre el Debe y el Haber.</p>
      <p v-if="!report.rows.length && !report.unmapped.length" class="mt-3 text-sm text-muted">El corte no contiene movimientos ni saldos para mostrar.</p>
      <div v-if="report.unmapped.length" class="mt-3 rounded border border-border p-3">
        <h3 class="text-sm font-semibold">Cuentas pendientes de correspondencia</h3>
        <ul class="mt-2 list-inside list-disc text-sm">
          <li v-for="account in report.unmapped" :key="account.code">{{ account.code }} · {{ account.name }}</li>
        </ul>
      </div>
      <div v-if="report.rows.length" class="mt-3 overflow-x-auto">
        <table class="w-full text-sm">
          <caption class="py-1 text-left text-muted">Importes exactos en PEN · {{ report.rows.length }} códigos SUNAT con información</caption>
          <thead><tr>
            <th scope="col" class="p-2 text-left">Cuenta</th>
            <th v-for="[key, label] in columns" :key="key" scope="col" class="whitespace-nowrap p-2 text-right">{{ label }}</th>
          </tr></thead>
          <tbody>
            <tr v-for="row in report.rows" :key="row.code" class="border-t border-border">
              <th scope="row" class="p-2 text-left">{{ row.code }} <span class="block max-w-56 break-words text-xs font-normal text-muted">{{ row.accounts.join(', ') }}</span></th>
              <td v-for="[key] in columns" :key="key" class="p-2 text-right tabular-nums">{{ row[key] }}</td>
            </tr>
          </tbody>
          <tfoot><tr class="border-t border-border font-semibold">
            <th scope="row" class="whitespace-nowrap p-2 text-left">Total del mayor</th>
            <td v-for="[key] in columns" :key="key" class="p-2 text-right tabular-nums">{{ report.totals[key] }}</td>
          </tr></tfoot>
        </table>
      </div>
    </template>
  </details>
</template>
