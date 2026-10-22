<script setup lang="ts">
import { computed, onMounted, ref, shallowRef } from 'vue';
import {
  AlertTriangle, CalendarRange, CheckCircle2, Clock3, LockKeyhole, LockOpen,
  RefreshCw, ShieldCheck, X,
} from 'lucide-vue-next';
import { AccountingApiClient, ApiHttpError } from '../api/httpClient';
import type { CompanyId } from '../types/accountingAccount.types';
import type { FiscalPeriodAction, FiscalPeriodSummary, FiscalPeriodStatus } from '../types/fiscalPeriod.types';

const props = defineProps<{
  readonly activeCompanyId: CompanyId;
  readonly getAccessToken: () => string | null;
  readonly canManagePeriods: boolean;
}>();

const apiClient = computed(() => new AccountingApiClient('', {
  getAccessToken: props.getAccessToken,
  getActiveCompanyId: () => props.activeCompanyId,
}));

const today = new Date();
const selectedYear = ref(today.getFullYear());
const isLoading = shallowRef(false);
const isSaving = shallowRef(false);
const errorMessage = shallowRef<string | null>(null);
const successMessage = shallowRef<string | null>(null);
const periods = shallowRef<readonly FiscalPeriodSummary[]>([]);
type PeriodActionRequest =
  | { readonly action: 'open'; readonly month: number; readonly year: number; readonly period: null }
  | { readonly action: FiscalPeriodAction; readonly month: number; readonly year: number; readonly period: FiscalPeriodSummary };
const pendingAction = shallowRef<PeriodActionRequest | null>(null);
const reason = ref('');
let requestVersion = 0;

const monthNames = [
  'Enero','Febrero','Marzo','Abril','Mayo','Junio',
  'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre',
] as const;

const monthRows = computed(() => monthNames.map((name, index) => ({
  month: index + 1,
  name,
  period: periods.value.find((item) => item.month === index + 1) ?? null,
})));

const totals = computed(() => ({
  open: periods.value.filter((p) => p.status === 'OPEN').length,
  closed: periods.value.filter((p) => p.status === 'CLOSED').length,
  locked: periods.value.filter((p) => p.status === 'LOCKED').length,
  entries: periods.value.reduce((sum, p) => sum + p.entry_count, 0),
}));

const actionLabels: Record<FiscalPeriodAction | 'open', string> = {
  open: 'Abrir período',
  close: 'Cerrar período',
  reopen: 'Reabrir período',
  lock: 'Bloquear definitivamente',
};

const confirmValid = computed(() => reason.value.trim().length >= 10
  && reason.value.trim().length <= 500 && !isSaving.value);

function periodLabel(status: FiscalPeriodStatus | null): string {
  if (status === 'OPEN') return 'Abierto';
  if (status === 'CLOSED') return 'Cerrado';
  if (status === 'LOCKED') return 'Bloqueado';
  return 'Sin registrar';
}

function statusClasses(status: FiscalPeriodStatus | null): string {
  if (status === 'OPEN') return 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 border-emerald-500/30';
  if (status === 'CLOSED') return 'bg-amber-500/10 text-amber-700 dark:text-amber-300 border-amber-500/30';
  if (status === 'LOCKED') return 'bg-rose-500/10 text-rose-700 dark:text-rose-300 border-rose-500/30';
  return 'bg-soft text-muted border-line';
}

async function loadPeriods(): Promise<void> {
  const version = ++requestVersion;
  isLoading.value = true;
  errorMessage.value = null;
  try {
    const loaded = await apiClient.value.fetchFiscalPeriods(selectedYear.value);
    if (version !== requestVersion) return;
    periods.value = loaded;
  } catch (error) {
    if (version === requestVersion) {
      errorMessage.value = error instanceof Error ? error.message : 'No se pudieron consultar los períodos.';
    }
  } finally {
    if (version === requestVersion) isLoading.value = false;
  }
}

function navigateYear(offset: number): void {
  if (isSaving.value) return;
  const next = selectedYear.value + offset;
  if (next < 2000 || next > 2100) return;
  selectedYear.value = next;
  periods.value = [];
  successMessage.value = null;
  void loadPeriods();
}

function requestOpen(month: number): void {
  if (!props.canManagePeriods || isSaving.value || isLoading.value) return;
  if (periods.value.some((p) => p.year === selectedYear.value && p.month === month)) return;
  pendingAction.value = { action: 'open', month, year: selectedYear.value, period: null };
  reason.value = '';
  errorMessage.value = null;
  successMessage.value = null;
}

function requestAction(period: FiscalPeriodSummary, action: FiscalPeriodAction): void {
  if (!props.canManagePeriods || isSaving.value) return;
  // Defense in depth: backend checks this again under SELECT FOR UPDATE.
  if ((action === 'close' && period.status !== 'OPEN')
    || ((action === 'reopen' || action === 'lock') && period.status !== 'CLOSED')) return;
  pendingAction.value = { period, action, month: period.month, year: period.year };
  reason.value = '';
  errorMessage.value = null;
  successMessage.value = null;
}

function cancelAction(): void {
  if (isSaving.value) return;
  pendingAction.value = null;
  reason.value = '';
}

async function confirmAction(): Promise<void> {
  const pending = pendingAction.value;
  if (!pending || !props.canManagePeriods || !confirmValid.value) return;
  isSaving.value = true;
  errorMessage.value = null;
  successMessage.value = null;
  const companyId = props.activeCompanyId;
  try {
    const result = pending.action === 'open'
      ? await apiClient.value.openFiscalPeriod(pending.year, pending.month, reason.value.trim())
      : await apiClient.value.changeFiscalPeriodStatus(
          pending.period.id, pending.action, reason.value.trim(),
        );
    if (props.activeCompanyId !== companyId) return;
    pendingAction.value = null;
    reason.value = '';
    successMessage.value = result.message;
    await loadPeriods();
  } catch (error) {
    const message = error instanceof Error ? error.message : 'No se pudo cambiar el estado del período.';
    errorMessage.value = error instanceof ApiHttpError && error.status === 409
      ? `El estado del período cambió o la transición ya no es válida. ${message}`
      : message;
    if (error instanceof ApiHttpError && error.status === 409) {
      pendingAction.value = null;
      await loadPeriods();
    }
  } finally {
    isSaving.value = false;
  }
}

onMounted(() => { void loadPeriods(); });
</script>

<template>
  <div class="space-y-5">
    <header class="flex flex-col gap-3 border-b border-border pb-5 sm:flex-row sm:items-start sm:justify-between">
      <div class="flex items-start gap-3">
        <div class="rounded-xl bg-brand/10 p-3 text-brand"><CalendarRange class="h-5 w-5" /></div>
        <div>
          <h1 class="text-xl font-semibold tracking-tight text-foreground">Períodos contables</h1>
          <p class="mt-1 max-w-2xl text-xs leading-relaxed text-muted">
            Control mensual de apertura, cierre y bloqueo por empresa. Todas las modificaciones quedan auditadas.
          </p>
        </div>
      </div>
      <button
        type="button"
        :disabled="isLoading || isSaving"
        class="inline-flex items-center justify-center gap-2 rounded-lg border border-line bg-card px-3 py-2 text-xs font-semibold hover:bg-soft disabled:opacity-50"
        @click="loadPeriods"
      >
        <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': isLoading }" />
        Actualizar
      </button>
    </header>

    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line bg-card p-3">
      <div class="flex items-center gap-2">
        <button type="button" :disabled="selectedYear <= 2000 || isLoading" class="rounded-md border border-line px-3 py-1.5 text-xs hover:bg-soft disabled:opacity-40" aria-label="Año anterior" @click="navigateYear(-1)">‹</button>
        <span class="min-w-16 text-center font-mono text-base font-bold">{{ selectedYear }}</span>
        <button type="button" :disabled="selectedYear >= 2100 || isLoading" class="rounded-md border border-line px-3 py-1.5 text-xs hover:bg-soft disabled:opacity-40" aria-label="Año siguiente" @click="navigateYear(1)">›</button>
      </div>
      <div class="flex flex-wrap items-center gap-2 text-xs">
        <span class="rounded-md bg-emerald-500/10 px-2.5 py-1 font-semibold text-emerald-700 dark:text-emerald-300">{{ totals.open }} abiertos</span>
        <span class="rounded-md bg-amber-500/10 px-2.5 py-1 font-semibold text-amber-700 dark:text-amber-300">{{ totals.closed }} cerrados</span>
        <span class="rounded-md bg-rose-500/10 px-2.5 py-1 font-semibold text-rose-700 dark:text-rose-300">{{ totals.locked }} bloqueados</span>
        <span class="rounded-md bg-soft px-2.5 py-1 text-muted">{{ totals.entries }} asientos</span>
      </div>
    </div>

    <div v-if="errorMessage" role="alert" class="flex items-start gap-2 rounded-lg border border-rose-500/30 bg-rose-500/10 p-3 text-xs text-rose-700 dark:text-rose-300">
      <AlertTriangle class="h-4 w-4 shrink-0" />
      <span>{{ errorMessage }}</span>
    </div>
    <div v-if="successMessage" role="status" class="flex items-start gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 text-xs text-emerald-700 dark:text-emerald-300">
      <CheckCircle2 class="h-4 w-4 shrink-0" />
      <span>{{ successMessage }}</span>
    </div>

    <div class="overflow-hidden rounded-xl border border-line bg-card">
      <div v-if="isLoading" class="p-4 text-xs text-muted" role="status">Consultando períodos de la empresa seleccionada…</div>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[610px] text-left text-xs">
          <thead class="bg-soft text-[11px] uppercase tracking-wide text-muted">
            <tr>
              <th scope="col" class="px-4 py-3 font-semibold">Mes</th>
              <th scope="col" class="px-4 py-3 font-semibold">Estado</th>
              <th scope="col" class="px-4 py-3 font-semibold">Asientos</th>
              <th scope="col" class="px-4 py-3 font-semibold">Último cierre</th>
              <th scope="col" class="px-4 py-3 text-right font-semibold">Acciones</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-for="row in monthRows" :key="row.month" class="hover:bg-soft/60">
              <td class="px-4 py-3 font-semibold text-foreground">{{ row.name }}</td>
              <td class="px-4 py-3">
                <span class="inline-flex rounded-md border px-2 py-1 font-medium" :class="statusClasses(row.period?.status ?? null)">
                  {{ periodLabel(row.period?.status ?? null) }}
                </span>
              </td>
              <td class="px-4 py-3 font-mono text-muted">{{ row.period?.entry_count ?? '—' }}</td>
              <td class="px-4 py-3 text-muted">
                {{ row.period?.closed_at ? row.period.closed_at.slice(0, 10) : '—' }}
              </td>
              <td class="px-4 py-3">
                <div v-if="canManagePeriods" class="flex items-center justify-end gap-2">
                  <button v-if="!row.period" type="button" :disabled="isSaving || isLoading" class="rounded-md border border-emerald-500/40 px-2.5 py-1.5 font-semibold text-emerald-700 hover:bg-emerald-500/10 disabled:opacity-50 dark:text-emerald-300" @click="requestOpen(row.month)">Abrir</button>
                  <button v-else-if="row.period?.status === 'OPEN'" type="button" :disabled="isSaving" class="rounded-md border border-amber-500/40 px-2.5 py-1.5 text-amber-700 hover:bg-amber-500/10 disabled:opacity-50 dark:text-amber-300" @click="requestAction(row.period!, 'close')">Cerrar</button>
                  <template v-else-if="row.period?.status === 'CLOSED'">
                    <button type="button" :disabled="isSaving" class="rounded-md border border-line px-2.5 py-1.5 text-foreground hover:bg-soft disabled:opacity-50" @click="requestAction(row.period!, 'reopen')">Reabrir</button>
                    <button type="button" :disabled="isSaving" class="rounded-md border border-rose-500/40 px-2.5 py-1.5 text-rose-700 hover:bg-rose-500/10 disabled:opacity-50 dark:text-rose-300" @click="requestAction(row.period!, 'lock')">Bloquear</button>
                  </template>
                  <span v-else-if="row.period?.status === 'LOCKED'" class="flex items-center gap-1 text-muted"><LockKeyhole class="h-3.5 w-3.5" /> Definitivo</span>
                </div>
                <span v-else class="block text-right text-muted">Solo lectura</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="border-t border-line bg-soft/50 p-3 text-[11px] text-muted">
        <Clock3 class="mr-1 inline h-3.5 w-3.5" />
        “Sin registrar” indica que aún no existe un período guardado. Un administrador puede abrirlo de forma explícita; nunca se crea al consultar la pantalla.
      </p>
    </div>

    <div class="flex items-start gap-2 rounded-lg border border-line bg-card p-4 text-xs text-muted">
      <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0 text-brand" />
      <p>
        Solo un administrador o contador principal de la empresa activa puede cambiar estados. El cierre
        impide nuevos asientos y extornos en ese mes; la reapertura requiere justificación.
        Los períodos bloqueados no admiten reapertura ordinaria.
      </p>
    </div>

    <div v-if="pendingAction" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/65 p-4" role="presentation" @click.self="cancelAction">
      <div role="dialog" aria-modal="true" aria-labelledby="period-action-title" aria-describedby="period-action-description" class="w-full max-w-lg rounded-xl border border-border bg-surface p-5 text-foreground shadow-2xl ring-1 ring-black/5 dark:ring-white/10">
        <div class="flex items-start justify-between gap-4">
          <div class="flex items-center gap-2">
            <LockOpen v-if="pendingAction.action === 'reopen' || pendingAction.action === 'open'" class="h-5 w-5 text-amber-500" />
            <LockKeyhole v-else class="h-5 w-5 text-brand" />
            <h2 id="period-action-title" class="text-base font-semibold text-foreground">
              {{ actionLabels[pendingAction.action] }}
            </h2>
          </div>
          <button type="button" :disabled="isSaving" class="rounded-md p-1 text-muted hover:bg-soft focus-visible:ring-2 focus-visible:ring-brand" aria-label="Cancelar operación" @click="cancelAction"><X class="h-4 w-4" /></button>
        </div>
        <p id="period-action-description" class="mt-3 text-sm leading-relaxed text-muted">
          {{ monthNames[pendingAction.month - 1] }} {{ pendingAction.year }} ·
          {{ pendingAction.period?.entry_count ?? 0 }} asientos.
          <span v-if="pendingAction.action === 'lock'" class="font-semibold text-rose-600 dark:text-rose-300">Este bloqueo no podrá revertirse desde el flujo ordinario.</span>
          <span v-else-if="pendingAction.action === 'open'">Se creará el período en estado abierto; no se generarán asientos automáticamente y la apertura quedará auditada.</span>
          <span v-else>La operación dejará constancia en la auditoría contable.</span>
        </p>
        <label for="period-reason" class="mt-4 block text-xs font-semibold text-foreground">Motivo obligatorio (10–500 caracteres)</label>
        <textarea
          id="period-reason"
          v-model="reason"
          :disabled="isSaving"
          maxlength="500"
          rows="4"
          class="mt-2 w-full resize-y rounded-lg border border-border bg-background p-3 text-sm text-foreground outline-none focus:border-brand"
          placeholder="Explica el motivo y la autorización correspondiente…"
        />
        <div class="mt-1 text-right text-[11px] text-muted">{{ reason.trim().length }}/500</div>
        <p v-if="errorMessage" role="alert" class="mt-3 rounded-md border border-rose-500/30 bg-rose-500/10 p-2.5 text-xs text-rose-700 dark:text-rose-300">
          {{ errorMessage }}
        </p>
        <div class="mt-4 flex justify-end gap-2">
          <button type="button" :disabled="isSaving" class="rounded-lg border border-border bg-surface px-4 py-2 text-xs font-semibold text-foreground hover:bg-soft disabled:opacity-50" @click="cancelAction">Cancelar</button>
          <button type="button" :disabled="!confirmValid" class="rounded-lg bg-brand px-4 py-2 text-xs font-semibold text-on-brand hover:opacity-90 disabled:opacity-50" @click="confirmAction">
            {{ isSaving ? 'Registrando…' : 'Confirmar y auditar' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
