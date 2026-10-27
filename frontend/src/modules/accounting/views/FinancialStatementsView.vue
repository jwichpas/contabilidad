<script setup lang="ts">
import { computed, onMounted, ref, shallowRef } from 'vue';
import { FileBarChart2, RefreshCw } from 'lucide-vue-next';
import { AccountingApiClient } from '../api/httpClient';
import type { CompanyId } from '../types/accountingAccount.types';
import type { FinancialSnapshot, FinancialSnapshotAccount } from '../types/financialSnapshot.types';

const props = defineProps<{
  readonly activeCompanyId: CompanyId;
  readonly getAccessToken: () => string | null;
}>();

const client = computed(() => new AccountingApiClient('', {
  getAccessToken: props.getAccessToken,
  getActiveCompanyId: () => props.activeCompanyId,
}));
const today = new Date();
const asOf = ref(`${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`);
const snapshot = shallowRef<FinancialSnapshot | null>(null);
const loading = shallowRef(false);
const error = shallowRef<string | null>(null);
const showTrialBalance = shallowRef(false);
let requestVersion = 0;

const sections = [
  { key: 'assets', label: 'Activo' },
  { key: 'liabilities', label: 'Pasivo' },
  { key: 'equity', label: 'Patrimonio' },
  { key: 'result', label: 'Resultado acumulado sin cerrar' },
] as const;

const grouped = computed(() => sections.map((section) => ({
  ...section,
  accounts: snapshot.value?.accounts.filter((account) => account.section === section.key) ?? [],
  total: snapshot.value?.totals[section.key] ?? '0.0000',
})));
const unpresented = computed(() => snapshot.value?.accounts.filter(
  (account) => account.section === 'unclassified' || account.section === 'excluded',
) ?? []);

function money(value: string): string {
  return `S/ ${Number(value).toLocaleString('es-PE', { minimumFractionDigits: 4, maximumFractionDigits: 4 })}`;
}

async function load(): Promise<void> {
  if (!asOf.value) return;
  const version = ++requestVersion;
  const companyId = props.activeCompanyId;
  loading.value = true;
  error.value = null;
  snapshot.value = null;
  try {
    const response = await client.value.fetchFinancialSnapshot(asOf.value);
    if (version === requestVersion && companyId === props.activeCompanyId) snapshot.value = response;
  } catch (cause) {
    if (version === requestVersion) error.value = cause instanceof Error ? cause.message : 'No se pudieron consultar los saldos.';
  } finally {
    if (version === requestVersion) loading.value = false;
  }
}

function accountLabel(account: FinancialSnapshotAccount): string {
  return `${account.code} · ${account.name}`;
}

onMounted(() => { void load(); });
</script>

<template>
  <div class="space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-4 border-b border-border pb-5">
      <div class="flex items-start gap-3">
        <div class="rounded-xl bg-brand/10 p-3 text-brand"><FileBarChart2 class="h-5 w-5" /></div>
        <div>
          <h1 class="text-xl font-semibold text-foreground">Estado de situación financiera preliminar</h1>
          <p class="mt-1 max-w-3xl text-sm text-muted">Saldos de los asientos contabilizados de esta empresa hasta la fecha elegida. Cifras en soles con cuatro decimales.</p>
        </div>
      </div>
      <form class="flex flex-wrap items-end gap-2" @submit.prevent="load">
        <label for="financial-as-of" class="flex flex-col gap-1 text-xs font-medium text-muted">
          Al cierre de
          <input id="financial-as-of" v-model="asOf" type="date" min="2000-01-01" max="2100-12-31" required class="rounded-lg border border-border bg-surface px-3 py-2 text-foreground" />
        </label>
        <button type="submit" :disabled="loading" class="inline-flex items-center gap-2 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
          <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': loading }" /> Consultar
        </button>
      </form>
    </header>

    <div v-if="loading" role="status" class="space-y-3" aria-label="Calculando estado financiero">
      <div class="ui-skeleton h-20 rounded-xl" /><div class="ui-skeleton h-40 rounded-xl" /><div class="ui-skeleton h-40 rounded-xl" />
    </div>
    <p v-else-if="error" role="alert" class="rounded-xl border border-danger bg-surface p-4 text-sm text-danger">{{ error }}</p>
    <template v-else-if="snapshot">
      <p class="rounded-lg border border-border bg-soft p-3 text-sm text-muted">
        Vista preliminar. El resultado incluye ingresos, gastos y costos acumulados aún sin asiento de cierre. Los rubros y ajustes de presentación siguen pendientes de conciliación.
      </p>
      <div v-if="snapshot.accounts.length === 0" role="status" class="rounded-xl border border-border bg-surface p-8 text-center text-sm text-muted">
        No hay partidas contabilizadas hasta {{ snapshot.as_of }} en esta empresa.
      </div>
      <template v-else>
        <div class="grid gap-4 lg:grid-cols-2">
          <section v-for="section in grouped" :key="section.key" class="min-w-0 rounded-xl border border-border bg-surface p-4">
            <h2 class="text-base font-semibold text-foreground">{{ section.label }}</h2>
            <div v-if="section.accounts.length" class="mt-3 divide-y divide-border">
              <div v-for="account in section.accounts" :key="account.id" class="flex min-w-0 flex-wrap justify-between gap-x-4 gap-y-1 py-2 text-sm">
                <span class="min-w-0 break-words text-muted">{{ accountLabel(account) }}</span>
                <span class="font-mono tabular-nums text-foreground">{{ money(account.amount_local) }}</span>
              </div>
            </div>
            <p v-else class="mt-3 text-sm text-muted">Sin movimientos.</p>
            <div class="mt-3 flex justify-between gap-2 border-t border-border pt-3 font-semibold"><span>Total</span><span class="font-mono tabular-nums">{{ money(section.total) }}</span></div>
          </section>
        </div>
        <div class="rounded-xl border border-border bg-soft p-4 text-sm">
          <div class="flex flex-wrap justify-between gap-2"><span>Activo</span><strong class="font-mono tabular-nums">{{ money(snapshot.totals.assets) }}</strong></div>
          <div class="mt-2 flex flex-wrap justify-between gap-2"><span>Pasivo + patrimonio + resultado</span><strong class="font-mono tabular-nums">{{ money(snapshot.totals.liabilities_equity_result) }}</strong></div>
          <div class="mt-3 flex flex-wrap justify-between gap-2 border-t border-border pt-3"><strong>Diferencia de presentación</strong><strong class="font-mono tabular-nums" :class="snapshot.totals.difference === '0.0000' ? 'text-emerald-700 dark:text-emerald-300' : 'text-danger'">{{ money(snapshot.totals.difference) }}</strong></div>
          <p v-if="snapshot.totals.difference !== '0.0000'" class="mt-2 text-muted">Revise las cuentas fuera de presentación y los asientos de cierre. La vista no oculta diferencias.</p>
        </div>

        <section class="rounded-xl border border-border bg-surface p-4">
          <h2 class="text-base font-semibold">Balance de comprobación</h2>
          <p class="mt-1 text-sm text-muted">Incluye también cuentas analíticas y de orden. Los originales extornados se compensan con sus asientos de reversión.</p>
          <button type="button" class="mt-3 rounded-lg border border-border px-3 py-2 text-sm font-semibold text-brand" :aria-expanded="showTrialBalance" @click="showTrialBalance = !showTrialBalance">{{ showTrialBalance ? 'Ocultar' : 'Ver' }} detalle por cuenta</button>
          <div v-if="showTrialBalance" class="mt-3 overflow-x-auto">
            <table class="w-full min-w-[560px] table-fixed text-left text-sm">
              <thead class="bg-soft text-muted"><tr><th scope="col" class="w-1/2 p-2">Cuenta</th><th scope="col" class="w-1/4 p-2 text-right">Debe PEN</th><th scope="col" class="w-1/4 p-2 text-right">Haber PEN</th></tr></thead>
              <tbody><tr v-for="account in snapshot.accounts" :key="account.id" class="border-t border-border"><td class="break-words p-2">{{ accountLabel(account) }}</td><td class="p-2 text-right font-mono tabular-nums">{{ money(account.debit_local) }}</td><td class="p-2 text-right font-mono tabular-nums">{{ money(account.credit_local) }}</td></tr></tbody>
              <tfoot><tr class="border-t border-border font-semibold"><th scope="row" class="p-2">Totales</th><td class="p-2 text-right font-mono">{{ money(snapshot.totals.debit_local) }}</td><td class="p-2 text-right font-mono">{{ money(snapshot.totals.credit_local) }}</td></tr></tfoot>
            </table>
          </div>
          <p class="mt-3 text-sm">Diferencia Debe − Haber: <strong class="font-mono">{{ money(snapshot.totals.trial_difference) }}</strong></p>
        </section>
        <section v-if="unpresented.length" class="rounded-xl border border-amber-500/30 bg-surface p-4 text-sm">
          <h2 class="font-semibold">Cuentas fuera de la presentación</h2>
          <p class="mt-1 text-muted">Incluye cuentas analíticas 9, puente 79, de orden u otras sin clasificación. Sus movimientos sí figuran en el balance de comprobación.</p>
          <ul class="mt-2 space-y-1"><li v-for="account in unpresented" :key="account.id">{{ accountLabel(account) }} · {{ money(account.amount_local) }}</li></ul>
        </section>
      </template>
    </template>
  </div>
</template>
