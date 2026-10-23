<script setup lang="ts">
import { onBeforeUnmount, onMounted, shallowRef } from 'vue';
import { AccountingApiClient, ApiHttpError } from '@/modules/accounting/api/httpClient';
import type { CompanyId } from '@/modules/accounting/types/accountingAccount.types';
import type { CostCenterAccount, CostCenterInput, CostCenterMaster, CostCenterStatus } from '../types/costCenterMaster.types';
import CostCenterEditor from '../components/CostCenterEditor.vue';

const props = defineProps<{ activeCompanyId: CompanyId; getAccessToken: () => string | null; canWrite: boolean }>();
// App keys this view by company. Capture the tenant for all requests from this instance.
const companyId = props.activeCompanyId;
const api = new AccountingApiClient('', { getAccessToken: props.getAccessToken, getActiveCompanyId: () => companyId });
const rows = shallowRef<readonly CostCenterMaster[]>([]);
const accounts = shallowRef<readonly CostCenterAccount[]>([]);
const search = shallowRef(''), status = shallowRef<CostCenterStatus>('active');
const page = shallowRef(1), lastPage = shallowRef(1), total = shallowRef(0);
const loading = shallowRef(false), saving = shallowRef(false), accountsReady = shallowRef(false);
const error = shallowRef(''), formError = shallowRef(''), success = shallowRef('');
const editing = shallowRef<CostCenterMaster | null | undefined>(undefined);
const deactivating = shallowRef<CostCenterMaster | null>(null);
let alive = true, sequence = 0;
const current = (): boolean => alive && companyId === props.activeCompanyId;
function explain(e: unknown): string {
  if (e instanceof ApiHttpError && e.status === 422) {
    const messages = Object.values(e.validationErrors).flat();
    if (messages.length) return messages.join(' · ');
  }
  return e instanceof Error ? e.message : 'No fue posible completar la operación.';
}
async function load(): Promise<void> {
  if (!current()) return;
  const request = ++sequence;
  loading.value = true; error.value = ''; rows.value = [];
  try {
    const result = await api.fetchCostCenterMaster({ search: search.value, status: status.value, page: page.value });
    if (!current() || request !== sequence) return;
    rows.value = result.data; total.value = result.meta.total; lastPage.value = result.meta.last_page;
  } catch (e) { if (current() && request === sequence) error.value = explain(e); }
  finally { if (current() && request === sequence) loading.value = false; }
}
async function start(center: CostCenterMaster | null): Promise<void> {
  if (!props.canWrite || saving.value || !current()) return;
  error.value = ''; formError.value = ''; success.value = ''; deactivating.value = null;
  if (!accountsReady.value) {
    loading.value = true;
    try {
      const result = await api.fetchOperationalAccounts();
      if (!current()) return;
      accounts.value = result; accountsReady.value = true;
    } catch (e) { if (current()) error.value = explain(e); return; }
    finally { if (current()) loading.value = false; }
  }
  if (current()) editing.value = center;
}
async function save(payload: CostCenterInput): Promise<void> {
  if (!current() || !props.canWrite || saving.value || editing.value === undefined) return;
  const id = editing.value?.id;
  saving.value = true; formError.value = '';
  try {
    if (id == null) await api.createCostCenter(payload);
    else await api.updateCostCenter(id, payload);
    if (!current()) return;
    editing.value = undefined; success.value = 'Centro de costo guardado y auditado.';
    page.value = 1; await load();
  } catch (e) { if (current()) formError.value = explain(e); }
  finally { if (current()) saving.value = false; }
}
async function deactivate(): Promise<void> {
  if (!current() || !props.canWrite || saving.value || !deactivating.value) return;
  saving.value = true; error.value = ''; success.value = '';
  try {
    await api.deactivateCostCenter(deactivating.value.id);
    if (!current()) return;
    deactivating.value = null; success.value = 'Centro desactivado. Sus movimientos se conservan.';
    page.value = 1; await load();
  } catch (e) { if (current()) error.value = explain(e); }
  finally { if (current()) saving.value = false; }
}
function filter(): void { page.value = 1; void load(); }
function goToPage(value: number): void { page.value = value; void load(); }
onMounted(() => { void load(); });
onBeforeUnmount(() => { alive = false; ++sequence; });
</script>

<template>
  <section class="space-y-4" aria-label="Centros de costo">
    <header class="flex flex-wrap items-center justify-between gap-3">
      <div><h1 class="text-xl font-semibold">Centros de costo</h1><p class="text-xs text-muted">Empresa {{ activeCompanyId }} · Destinos contables y trazabilidad</p></div>
      <button v-if="canWrite" type="button" :disabled="loading || saving" class="rounded bg-brand px-3 py-2 text-xs font-semibold text-white disabled:opacity-50" @click="start(null)">Nuevo centro</button>
    </header>
    <form class="flex flex-wrap items-end gap-3 rounded-lg border border-line bg-surface p-4" @submit.prevent="filter">
      <label class="grow text-xs">Buscar por código o nombre<input v-model="search" maxlength="150" class="mt-1 block w-full rounded border border-line bg-background p-2" /></label>
      <label class="text-xs">Estado<select v-model="status" class="mt-1 block rounded border border-line bg-background p-2"><option value="active">Activos</option><option value="inactive">Inactivos</option><option value="all">Todos</option></select></label>
      <button type="submit" :disabled="loading || saving" class="rounded border border-line px-3 py-2 text-xs disabled:opacity-50">Consultar</button>
    </form>
    <p v-if="error" role="alert" class="text-sm text-red-600">{{ error }}</p>
    <p v-if="success" role="status" class="text-sm text-emerald-600">{{ success }}</p>
    <CostCenterEditor v-if="editing !== undefined && canWrite" :key="editing?.id ?? 'new'" :center="editing" :accounts="accounts" :saving="saving" :error="formError" @save="save" @cancel="editing = undefined" />
    <div v-if="deactivating && canWrite" role="group" aria-label="Confirmar desactivación" class="rounded-lg border border-amber-300 bg-surface p-4 text-sm">
      <p>¿Desactivar {{ deactivating.code }} · {{ deactivating.name }}? Dejará de estar disponible para nuevas partidas; sus movimientos anteriores se conservan.</p>
      <div class="mt-3 flex gap-2">
        <button type="button" :disabled="saving" class="rounded border border-line px-3 py-2" @click="deactivating = null">Cancelar</button>
        <button type="button" :disabled="saving" class="rounded bg-red-700 px-3 py-2 text-white" @click="deactivate">Confirmar desactivación</button>
      </div>
    </div>
    <div class="overflow-x-auto rounded-lg border border-line bg-surface">
      <table class="w-full min-w-[700px] text-left text-xs">
        <thead><tr><th scope="col" class="p-3">Código y nombre</th><th scope="col" class="p-3">Destino Debe</th><th scope="col" class="p-3">Contrapartida Haber</th><th scope="col" class="p-3">Estado</th><th v-if="canWrite" scope="col" class="p-3">Acciones</th></tr></thead>
        <tbody class="divide-y divide-line">
          <tr v-if="loading"><td :colspan="canWrite ? 5 : 4" class="p-6 text-center">Cargando…</td></tr>
          <tr v-else-if="rows.length === 0"><td :colspan="canWrite ? 5 : 4" class="p-6 text-center">No hay centros para esta consulta.</td></tr>
          <template v-else><tr v-for="row in rows" :key="row.id">
            <td class="p-3"><strong>{{ row.code }}</strong><span class="block text-muted">{{ row.name }}</span></td>
            <td class="p-3">{{ row.destination_debit_account?.code ?? 'No disponible' }}</td><td class="p-3">{{ row.destination_credit_account?.code ?? 'No disponible' }}</td>
            <td class="p-3">{{ row.is_active ? 'Activo' : 'Inactivo' }}</td>
            <td v-if="canWrite" class="p-3"><div v-if="row.is_active" class="flex gap-3">
              <button type="button" :disabled="saving" class="text-brand underline" :aria-label="'Editar ' + row.code" @click="start(row)">Editar</button>
              <button type="button" :disabled="saving" class="text-red-700 underline" :aria-label="'Desactivar ' + row.code" @click="editing = undefined; error = ''; deactivating = row">Desactivar</button>
            </div></td>
          </tr></template>
        </tbody>
      </table>
    </div>
    <nav class="flex items-center justify-between gap-3 text-xs" aria-label="Paginación de centros de costo">
      <span>{{ total }} centros · Página {{ page }} de {{ lastPage }}</span>
      <div class="flex gap-2"><button type="button" :disabled="loading || saving || page <= 1" class="rounded border border-line px-3 py-2 disabled:opacity-50" @click="goToPage(page - 1)">Anterior</button><button type="button" :disabled="loading || saving || page >= lastPage" class="rounded border border-line px-3 py-2 disabled:opacity-50" @click="goToPage(page + 1)">Siguiente</button></div>
    </nav>
  </section>
</template>
