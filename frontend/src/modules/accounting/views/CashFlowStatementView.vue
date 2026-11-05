<script setup lang="ts">
import { computed, shallowRef, watch } from 'vue';
import { ArrowDownLeft, ArrowUpRight, CheckCircle2, RefreshCw, AlertCircle } from 'lucide-vue-next';
import UiSelect from '../../../shared/ui/UiSelect.vue';
import CashFlowMovements from '../components/CashFlowMovements.vue';
import { useCashFlowReport } from '../composables/useCashFlowReport';
import { CASH_FLOW_LABELS } from '../types/cashFlow.types';
import type { CashFlowFilter, CashFlowQuery } from '../types/cashFlow.types';
import type { CompanyId } from '../types/accountingAccount.types';
import { formatExactLocalAmount } from '../utils/formatExactLocalAmount';

const props = defineProps<{ readonly activeCompanyId: CompanyId; readonly getAccessToken: () => string | null }>();
const now = new Date();
const end = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
const from = shallowRef(`${now.getFullYear()}-01-01`);
const to = shallowRef(end);
const query = shallowRef<CashFlowQuery>({ from: from.value, to: to.value, section: 'ALL', page: 1 });
const { report, pending, error, load } = useCashFlowReport(() => props.activeCompanyId, props.getAccessToken);
const filterOptions = Object.entries(CASH_FLOW_LABELS).map(([value, label]) => ({ value: value as CashFlowFilter, label }));
const filter = computed({
  get: () => query.value.section ?? 'ALL',
  set: (section: CashFlowFilter) => selectDetails(section),
});
const sections = computed(() => [
  { key: 'OPERATING' as const, label: 'Actividades de operación', amount: report.value?.totals.operating_local ?? '0.0000' },
  { key: 'INVESTING' as const, label: 'Actividades de inversión', amount: report.value?.totals.investing_local ?? '0.0000' },
  { key: 'FINANCING' as const, label: 'Actividades de financiamiento', amount: report.value?.totals.financing_local ?? '0.0000' },
].map((section) => ({ ...section, groups: report.value?.groups.filter((group) => group.section === section.key) ?? [] })));
const selectedArea = computed(() => report.value?.groups.find((group) => group.area_id === query.value.area_id)?.name);
const reconciliation = computed(() => report.value ? [
  { label: 'Efectivo inicial', value: report.value.totals.opening_local },
  { label: 'Flujo neto de operación, inversión y financiamiento', value: report.value.totals.net_flow_local },
  { label: 'Efecto de cambio identificado', value: report.value.totals.exchange_effect_local },
  { label: 'Otros ajustes a revisar', value: report.value.totals.other_adjustments_local },
  { label: 'Efectivo final calculado (sin partidas sin clasificar)', value: report.value.totals.expected_closing_local },
  { label: 'Efectivo final en libros', value: report.value.totals.closing_local },
] : []);
const money = (value: string): string => formatExactLocalAmount(value, report.value?.currency ?? 'PEN');

function consult(): void {
  query.value = { from: from.value, to: to.value, section: 'ALL', page: 1 };
  void load(query.value);
}
function selectDetails(section: CashFlowFilter, areaId?: number): void {
  query.value = { ...query.value, section, area_id: areaId, page: 1 };
  void load(query.value);
}
function changePage(page: number): void {
  query.value = { ...query.value, page };
  void load(query.value);
}

watch(() => props.activeCompanyId, () => {
  query.value = { from: from.value, to: to.value, section: 'ALL', page: 1 };
  void load(query.value);
}, { immediate: true });
</script>

<template>
  <div class="space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-4 border-b border-border pb-5">
      <div class="max-w-2xl">
        <p class="text-xs font-medium text-brand">Estados financieros · Método directo</p>
        <h1 class="mt-1 text-xl font-semibold">Estado de flujos de efectivo</h1>
        <p class="mt-2 text-sm text-muted">Cobros y pagos del Libro Diario, agrupados por actividad y conciliados con el efectivo en libros.</p>
      </div>
      <form class="flex w-full flex-wrap items-end gap-2 lg:w-auto" @submit.prevent="consult">
        <label for="cash-flow-from" class="flex min-w-0 flex-1 flex-col gap-1 text-xs text-muted">Desde
          <input id="cash-flow-from" v-model="from" type="date" min="2000-01-01" :max="to || '2100-12-31'" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-foreground" />
        </label>
        <label for="cash-flow-to" class="flex min-w-0 flex-1 flex-col gap-1 text-xs text-muted">Hasta
          <input id="cash-flow-to" v-model="to" type="date" :min="from || '2000-01-01'" max="2100-12-31" required class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm text-foreground" />
        </label>
        <button type="submit" :disabled="pending" class="inline-flex min-h-10 items-center gap-2 rounded-lg bg-brand px-4 py-2 text-sm font-medium text-white disabled:opacity-50"><RefreshCw class="h-4 w-4" :class="{ 'animate-spin': pending }" />Consultar</button>
      </form>
    </header>

    <div v-if="pending" role="status" aria-label="Calculando flujo de efectivo" class="space-y-3"><div class="ui-skeleton h-24 rounded-xl" /><div class="ui-skeleton h-60 rounded-xl" /></div>
    <div v-else-if="error" role="alert" class="rounded-xl border border-danger p-4 text-sm text-danger">{{ error }} <button type="button" class="ml-2 underline" @click="load(query)">Reintentar</button></div>
    <template v-else-if="report">
      <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted"><span>Del {{ report.from }} al {{ report.to }} · {{ report.currency }} · 4 decimales</span><span>Vista preliminar · Clasificación vigente de cuentas y áreas</span></div>
      <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-border bg-surface p-4"><p class="text-xs text-muted">Efectivo inicial</p><p class="mt-2 break-words font-mono text-lg font-semibold tabular-nums">{{ money(report.totals.opening_local) }}</p></div>
        <div class="rounded-xl border border-border bg-surface p-4"><p class="text-xs text-muted">Flujo neto de actividades</p><p class="mt-2 break-words font-mono text-lg font-semibold tabular-nums">{{ money(report.totals.net_flow_local) }}</p></div>
        <div class="rounded-xl border border-border bg-surface p-4"><p class="text-xs text-muted">Efectivo final en libros</p><p class="mt-2 break-words font-mono text-lg font-semibold tabular-nums">{{ money(report.totals.closing_local) }}</p></div>
      </div>

      <div v-if="!report.classification_complete" role="note" class="space-y-2 rounded-xl border border-amber-500/40 bg-surface p-4 text-sm">
        <p class="flex items-center gap-2 font-semibold"><AlertCircle class="h-4 w-4 shrink-0 text-amber-600" />Hay partidas o cuentas que requieren revisión</p>
        <p class="text-muted">Las partidas sin clasificar no se incorporan a las actividades. Una diferencia neta de cero no sustituye la revisión de todas las partidas.</p>
        <div class="flex flex-wrap gap-2">
          <button v-if="report.counts.UNCLASSIFIED" type="button" class="rounded-lg border border-border px-3 py-2 text-brand" @click="selectDetails('UNCLASSIFIED')">Revisar {{ report.counts.UNCLASSIFIED }} sin clasificar</button>
          <button v-if="report.counts.OTHER_ADJUSTMENT" type="button" class="rounded-lg border border-border px-3 py-2 text-brand" @click="selectDetails('OTHER_ADJUSTMENT')">Revisar {{ report.counts.OTHER_ADJUSTMENT }} ajustes</button>
        </div>
        <p v-if="report.unconfigured_accounts.length" class="text-muted">Hay {{ report.unconfigured_accounts.length }} cuenta(s) del grupo 10 con movimientos sin rubro financiero y sin marca de efectivo equivalente. Sus importes no están incluidos; revise la configuración en el Plan de cuentas.</p>
        <ul v-if="report.unconfigured_accounts.length" class="space-y-1 text-xs text-muted"><li v-for="account in report.unconfigured_accounts" :key="account.id">{{ account.code }} · {{ account.name }}</li></ul>
      </div>
      <p v-if="report.cash_accounts.length === 0" role="status" class="rounded-xl border border-border bg-surface p-5 text-sm text-muted">No hay movimientos hasta esta fecha en cuentas configuradas como efectivo y equivalentes.</p>

      <section class="overflow-hidden rounded-xl border border-border bg-surface" aria-label="Actividades del flujo de efectivo">
        <div v-for="section in sections" :key="section.key" class="border-b border-border p-4 last:border-b-0 sm:p-5">
          <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="font-semibold">{{ section.label }}</h2><strong class="font-mono text-sm tabular-nums">{{ money(section.amount) }}</strong></div>
          <p v-if="section.groups.length === 0" class="mt-3 text-xs text-muted">Sin flujos clasificados en esta actividad.</p>
          <div v-for="group in section.groups" :key="group.area_id" class="mt-4 border-t border-border pt-3">
            <div class="flex flex-wrap justify-between gap-2 text-sm"><button type="button" class="break-words text-left text-brand hover:underline" @click="selectDetails(section.key, group.area_id)">{{ group.code }} · {{ group.name }} <span class="text-xs text-muted">({{ group.count }})</span></button><strong class="font-mono tabular-nums">{{ money(group.net_local) }}</strong></div>
            <div class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-xs text-muted"><span class="inline-flex items-center gap-1"><ArrowDownLeft class="h-3 w-3" />Cobros: {{ money(group.inflow_local) }}</span><span class="inline-flex items-center gap-1"><ArrowUpRight class="h-3 w-3" />Pagos: {{ money(group.outflow_local) }}</span></div>
          </div>
        </div>
        <div class="flex flex-wrap justify-between gap-2 bg-soft p-4 font-semibold sm:p-5"><span>Aumento / disminución por actividades</span><span class="font-mono tabular-nums">{{ money(report.totals.net_flow_local) }}</span></div>
      </section>

      <section class="rounded-xl border border-border bg-surface p-4 sm:p-5">
        <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="font-semibold">Conciliación del efectivo</h2><span class="inline-flex items-center gap-1.5 text-xs" :class="report.is_reconciled ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300'"><CheckCircle2 v-if="report.is_reconciled" class="h-4 w-4" /><AlertCircle v-else class="h-4 w-4" />{{ report.is_reconciled ? 'Conciliado · clasificación completa' : 'Pendiente de revisión' }}</span></div>
        <dl class="mt-4 divide-y divide-border text-sm"><div v-for="row in reconciliation" :key="row.label" class="flex flex-wrap justify-between gap-2 py-2.5"><dt class="text-muted">{{ row.label }}</dt><dd class="font-mono tabular-nums">{{ money(row.value) }}</dd></div><div class="flex flex-wrap justify-between gap-2 py-3 font-semibold"><dt>Diferencia por explicar</dt><dd class="font-mono tabular-nums">{{ money(report.totals.difference_local) }}</dd></div></dl>
        <p class="mt-2 text-xs leading-relaxed text-muted">Las transferencias internas no suman cobros ni pagos. Las aperturas al inicio del período se incluyen en el efectivo inicial. Solo se separa el efecto de cambio identificado en ajustes contra las cuentas de diferencia de cambio configuradas.</p>
        <details v-if="report.cash_accounts.length" class="mt-4 text-sm"><summary class="cursor-pointer text-brand">Ver composición del efectivo · {{ report.cash_accounts.length }} cuentas</summary><div class="mt-3 space-y-3"><div v-for="account in report.cash_accounts" :key="account.id" class="border-t border-border pt-2"><p class="break-words text-xs">{{ account.code }} · {{ account.name }}</p><div class="mt-1 flex flex-wrap gap-x-6 gap-y-1 text-xs text-muted"><span>Inicial: {{ money(account.opening_local) }}</span><span>Final: {{ money(account.closing_local) }}</span></div></div></div></details>
      </section>

      <section class="rounded-xl border border-border bg-surface p-4 sm:p-5">
        <div class="flex flex-wrap items-end justify-between gap-3"><div><h2 class="font-semibold">Detalle de movimientos</h2><p class="mt-1 text-xs text-muted">Entradas positivas, salidas negativas. Incluye extornos en su fecha contable.</p></div><div class="w-full sm:w-64"><label for="cash-flow-filter" class="mb-1 block text-xs text-muted">Mostrar</label><UiSelect id="cash-flow-filter" v-model="filter" :options="filterOptions" /></div></div>
        <p v-if="selectedArea" class="my-3 text-xs text-muted">Área: {{ selectedArea }} <button type="button" class="ml-2 text-brand underline" @click="selectDetails(filter)">Quitar filtro de área</button></p>
        <div class="mt-4"><CashFlowMovements :details="report.details" :currency="report.currency" @page="changePage" /></div>
      </section>
    </template>
  </div>
</template>
