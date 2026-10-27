<script setup lang="ts">
import { computed, onMounted, ref, shallowRef } from 'vue';
import { FileBarChart2, RefreshCw } from 'lucide-vue-next';
import { AccountingApiClient } from '../api/httpClient';
import { formatExactLocalAmount } from '../utils/formatExactLocalAmount';
import type { CompanyId } from '../types/accountingAccount.types';
import type { FinancialSnapshot, FinancialSnapshotAccount, GeneralLedgerPage } from '../types/financialSnapshot.types';

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
const selectedAccount = shallowRef<FinancialSnapshotAccount | null>(null);
const ledger = shallowRef<GeneralLedgerPage | null>(null);
const ledgerLoading = shallowRef(false);
const ledgerError = shallowRef<string | null>(null);
let requestVersion = 0;
let ledgerVersion = 0;

const sections = [
  { key: 'assets', label: 'Activo' },
  { key: 'liabilities', label: 'Pasivo' },
  { key: 'equity', label: 'Patrimonio' },
  { key: 'result', label: 'Resultado acumulado sin cerrar' },
] as const;

const grouped = computed(() => sections.map((section) => ({
  ...section,
  accounts: snapshot.value?.accounts.filter((account) => account.section === section.key) ?? [],
  rubrics: snapshot.value?.rubrics.filter((rubric) => rubric.section === section.key) ?? [],
  total: snapshot.value?.totals[section.key] ?? '0.0000',
})));
const unpresented = computed(() => snapshot.value?.accounts.filter(
  (account) => account.section === 'unclassified' || account.section === 'excluded',
) ?? []);
const incomeRubrics = computed(() => snapshot.value?.rubrics.filter(
  (rubric) => rubric.section === 'result' && rubric.period_amount_local !== '0.0000',
) ?? []);

function money(value: string): string {
  return formatExactLocalAmount(value, snapshot.value?.currency ?? 'PEN');
}

async function load(): Promise<void> {
  if (!asOf.value) return;
  const version = ++requestVersion;
  const companyId = props.activeCompanyId;
  ledgerVersion++;
  selectedAccount.value = null;
  ledger.value = null;
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

async function loadLedger(page: number): Promise<void> {
  const account = selectedAccount.value;
  if (!account || !snapshot.value) return;
  const version = ++ledgerVersion;
  const companyId = props.activeCompanyId;
  ledgerLoading.value = true;
  ledgerError.value = null;
  try {
    const response = await client.value.fetchGeneralLedger(
      account.id, `${snapshot.value.as_of.slice(0, 4)}-01-01`, snapshot.value.as_of, page,
    );
    if (version === ledgerVersion && companyId === props.activeCompanyId) ledger.value = response;
  } catch (cause) {
    if (version === ledgerVersion) ledgerError.value = cause instanceof Error ? cause.message : 'No se pudo consultar el Libro Mayor.';
  } finally {
    if (version === ledgerVersion) ledgerLoading.value = false;
  }
}

function openLedger(account: FinancialSnapshotAccount): void {
  selectedAccount.value = account;
  ledger.value = null;
  void loadLedger(1);
}

function closeLedger(): void {
  ledgerVersion++;
  selectedAccount.value = null;
  ledger.value = null;
  ledgerError.value = null;
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
          <p class="mt-1 max-w-3xl text-sm text-muted">Saldos de los asientos contabilizados de esta empresa hasta la fecha elegida. Cifras en moneda funcional de la empresa con cuatro decimales.</p>
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
      <section v-if="selectedAccount" class="space-y-4">
        <button type="button" class="rounded-lg border border-border bg-surface px-3 py-2 text-sm font-semibold text-brand" @click="closeLedger">← Volver a estados financieros</button>
        <div class="rounded-xl border border-border bg-surface p-4">
          <h2 class="text-lg font-semibold">Libro Mayor · {{ accountLabel(selectedAccount) }}</h2>
          <p class="mt-1 text-sm text-muted">Movimientos del ejercicio {{ snapshot.as_of.slice(0, 4) }} hasta {{ snapshot.as_of }}. Saldo deudor positivo y acreedor negativo.</p>
          <div v-if="ledgerLoading" role="status" class="mt-4 space-y-2" aria-label="Consultando Libro Mayor"><div class="ui-skeleton h-10" /><div class="ui-skeleton h-28" /></div>
          <p v-else-if="ledgerError" role="alert" class="mt-4 rounded-lg border border-danger p-3 text-sm text-danger">{{ ledgerError }} <button type="button" class="underline" @click="loadLedger(ledger?.page ?? 1)">Reintentar</button></p>
          <template v-else-if="ledger">
            <div class="mt-4 rounded-lg bg-soft p-3 text-sm">Saldo inicial al {{ ledger.from }}: <strong class="font-mono">{{ money(ledger.opening_local) }}</strong></div>
            <div v-if="ledger.total === 0" class="mt-4 text-sm text-muted">Sin movimientos en este período.</div>
            <div v-else class="mt-4 overflow-x-auto">
              <table class="w-full min-w-[720px] table-fixed text-left text-xs sm:text-sm">
                <thead class="bg-soft text-muted"><tr><th scope="col" class="w-[14%] p-2">Fecha</th><th scope="col" class="w-[14%] p-2">Asiento</th><th scope="col" class="w-[30%] p-2">Glosa</th><th scope="col" class="w-[14%] p-2 text-right">Debe {{ snapshot.currency }}</th><th scope="col" class="w-[14%] p-2 text-right">Haber {{ snapshot.currency }}</th><th scope="col" class="w-[14%] p-2 text-right">Saldo {{ snapshot.currency }}</th></tr></thead>
                <tbody><tr v-for="row in ledger.rows" :key="row.id" class="border-t border-border"><td class="p-2">{{ row.entry_date }}</td><td class="break-words p-2 font-mono">{{ row.entry_number }}</td><td class="break-words p-2">{{ row.description }}</td><td class="p-2 text-right font-mono">{{ money(row.debit_local) }}</td><td class="p-2 text-right font-mono">{{ money(row.credit_local) }}</td><td class="p-2 text-right font-mono font-semibold">{{ money(row.balance_local) }}</td></tr></tbody>
              </table>
            </div>
            <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-sm">
              <span>{{ ledger.total }} movimientos · página {{ ledger.page }} de {{ ledger.last_page }}</span>
              <div class="flex gap-2"><button type="button" class="rounded-lg border border-border px-3 py-2 disabled:opacity-40" :disabled="ledger.page <= 1 || ledgerLoading" @click="loadLedger(ledger.page - 1)">Anterior</button><button type="button" class="rounded-lg border border-border px-3 py-2 disabled:opacity-40" :disabled="ledger.page >= ledger.last_page || ledgerLoading" @click="loadLedger(ledger.page + 1)">Siguiente</button></div>
            </div>
          </template>
        </div>
      </section>
      <template v-else>
      <p class="rounded-lg border border-border bg-soft p-3 text-sm text-muted">
        Vista preliminar. El resultado incluye ingresos, gastos y costos acumulados aún sin asiento de cierre.
        Los ajustes y las notas de presentación siguen pendientes de conciliación.
      </p>
      <p v-if="snapshot.analytic_routing_status !== 'ACTIVE'"
        class="rounded-lg border border-amber-500/40 bg-surface p-3 text-sm text-muted" role="note">
        Las reglas de destino analítico 94/95 contra 79
        {{ snapshot.analytic_routing_status === 'DRAFT' ? 'están en borrador y aún no generan imputaciones automáticas' : 'aún no están configuradas para esta empresa' }}.
        Los estados financieros muestran solo los asientos efectivamente registrados.
      </p>
      <div v-if="snapshot.unmapped_account_ids.length" role="note"
        class="rounded-xl border border-amber-500/40 bg-surface p-3 text-sm text-muted">
        Hay {{ snapshot.unmapped_account_ids.length }} cuenta(s) con movimientos sin rubro financiero configurado.
        Se incluyen en los totales para conservar la conciliación, pero requieren clasificación antes de emitir estados definitivos.
      </div>
      <div v-if="snapshot.accounts.length === 0" role="status" class="rounded-xl border border-border bg-surface p-8 text-center text-sm text-muted">
        No hay partidas contabilizadas hasta {{ snapshot.as_of }} en esta empresa.
      </div>
      <template v-else>
        <div class="grid gap-4 lg:grid-cols-2">
          <section v-for="section in grouped" :key="section.key" class="min-w-0 rounded-xl border border-border bg-surface p-4">
            <h2 class="text-base font-semibold text-foreground">{{ section.label }}</h2>
            <div v-if="section.rubrics.length" class="mt-3 divide-y divide-border">
              <div v-for="rubric in section.rubrics" :key="`${section.key}-${rubric.code}`" class="py-3 text-sm">
                <div class="flex flex-wrap items-start justify-between gap-2 font-medium">
                  <span class="min-w-0">{{ rubric.name }}</span>
                  <span class="font-mono tabular-nums">{{ money(rubric.amount_local) }}</span>
                </div>
                <details class="mt-2 text-muted">
                  <summary class="cursor-pointer text-xs text-brand">Ver {{ rubric.account_ids.length }} cuenta(s)</summary>
                  <div class="mt-2 space-y-2 pl-3">
                    <div v-for="account in section.accounts.filter((row) => rubric.account_ids.includes(row.id))"
                      :key="account.id" class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                      <button type="button" class="min-w-0 break-words text-left text-brand hover:underline"
                        :aria-label="`Ver Libro Mayor de ${accountLabel(account)}`"
                        @click="openLedger(account)">{{ accountLabel(account) }}</button>
                      <span class="font-mono tabular-nums">{{ money(account.amount_local) }}</span>
                    </div>
                  </div>
                </details>
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
          <h2 class="text-base font-semibold">Estado de Resultados por naturaleza · {{ snapshot.income_statement.from }} al {{ snapshot.income_statement.to }}</h2>
          <p class="mt-1 text-sm text-muted">Movimientos del ejercicio en cuentas de ingresos, gastos y costos; no equivale al resultado acumulado mostrado en patrimonio.</p>
          <div v-for="rubric in incomeRubrics" :key="rubric.code"
            class="mt-2 border-b border-border py-2 text-sm">
            <div class="flex flex-wrap justify-between gap-x-4 gap-y-1 font-medium">
              <span>{{ rubric.name }}</span>
              <span class="font-mono tabular-nums">{{ money(rubric.period_amount_local) }}</span>
            </div>
            <details class="mt-2 text-muted">
              <summary class="cursor-pointer text-xs text-brand">Ver {{ rubric.account_ids.length }} cuenta(s)</summary>
              <div class="mt-2 space-y-2 pl-3">
                <div v-for="account in snapshot.accounts.filter((row) => rubric.account_ids.includes(row.id))"
                  :key="account.id" class="flex flex-wrap justify-between gap-x-3 gap-y-1">
                  <button type="button" class="min-w-0 break-words text-left text-brand hover:underline"
                    @click="openLedger(account)">{{ accountLabel(account) }}</button>
                  <span class="font-mono tabular-nums">{{ money(account.period_amount_local ?? '0.0000') }}</span>
                </div>
              </div>
            </details>
          </div>
          <div class="mt-3 space-y-2 text-sm"><div class="flex justify-between gap-2"><span>Ingresos</span><strong class="font-mono">{{ money(snapshot.income_statement.revenue) }}</strong></div><div class="flex justify-between gap-2"><span>Gastos y costos</span><strong class="font-mono">{{ money(snapshot.income_statement.expenses) }}</strong></div><div class="flex justify-between gap-2 border-t border-border pt-3"><strong>Resultado del ejercicio</strong><strong class="font-mono">{{ money(snapshot.income_statement.net) }}</strong></div></div>
        </section>

        <section class="rounded-xl border border-border bg-surface p-4">
          <h2 class="text-base font-semibold">Balance de comprobación</h2>
          <p class="mt-1 text-sm text-muted">Incluye también cuentas analíticas y de orden. Los originales extornados se compensan con sus asientos de reversión.</p>
          <button type="button" class="mt-3 rounded-lg border border-border px-3 py-2 text-sm font-semibold text-brand" :aria-expanded="showTrialBalance" @click="showTrialBalance = !showTrialBalance">{{ showTrialBalance ? 'Ocultar' : 'Ver' }} detalle por cuenta</button>
          <div v-if="showTrialBalance" class="mt-3 overflow-x-auto">
            <table class="w-full min-w-[560px] table-fixed text-left text-sm">
              <thead class="bg-soft text-muted"><tr><th scope="col" class="w-1/2 p-2">Cuenta</th><th scope="col" class="w-1/4 p-2 text-right">Debe {{ snapshot.currency }}</th><th scope="col" class="w-1/4 p-2 text-right">Haber {{ snapshot.currency }}</th></tr></thead>
              <tbody><tr v-for="account in snapshot.accounts" :key="account.id" class="border-t border-border"><td class="break-words p-2"><button type="button" class="text-left text-brand hover:underline" @click="openLedger(account)">{{ accountLabel(account) }}</button></td><td class="p-2 text-right font-mono tabular-nums">{{ money(account.debit_local) }}</td><td class="p-2 text-right font-mono tabular-nums">{{ money(account.credit_local) }}</td></tr></tbody>
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
    </template>
  </div>
</template>
