<script setup lang="ts">
import { onBeforeUnmount, onMounted, shallowRef } from 'vue';
import { AccountingApiClient, ApiHttpError } from '@/modules/accounting/api/httpClient';
import type { CompanyId } from '@/modules/accounting/types/accountingAccount.types';
import type { CostCenterAccount, CostCenterInput, CostCenterMaster, CostCenterStatus } from '../types/costCenterMaster.types';
import CostCenterEditor from '../components/CostCenterEditor.vue';
import UiButton from '@/shared/ui/UiButton.vue';

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
  <section class="workspace-page space-y-4" aria-label="Centros de costo">
    <header class="workspace-heading">
      <div>
        <h1>Centros de costo</h1>
        <p>Empresa {{ activeCompanyId }} · Destinos contables y trazabilidad.</p>
      </div>
      <UiButton v-if="canWrite" variant="primary" :disabled="loading || saving" @click="start(null)">
        Nuevo centro
      </UiButton>
    </header>

    <form class="workspace-toolbar flex flex-wrap items-end gap-3" @submit.prevent="filter">
      <label class="workspace-field min-w-[210px] flex-1">
        Buscar por código o nombre
        <input v-model="search" type="search" maxlength="150"
          class="mt-1 block w-full rounded-md border border-line bg-surf px-3 py-2 text-foreground" />
      </label>
      <label class="workspace-field min-w-[150px]">
        Estado
        <select v-model="status" class="mt-1 block w-full rounded-md border border-line bg-surf px-3 py-2 text-foreground">
          <option value="active">Activos</option>
          <option value="inactive">Inactivos</option>
          <option value="all">Todos</option>
        </select>
      </label>
      <UiButton type="submit" variant="secondary" :disabled="loading || saving">
        Consultar
      </UiButton>
    </form>

    <p v-if="error" role="alert" class="rounded-md border border-neg/35 bg-neg/5 px-3 py-2 text-sm text-neg">{{ error }}</p>
    <p v-if="success" role="status" class="rounded-md border border-pos/35 bg-pos/5 px-3 py-2 text-sm text-pos">{{ success }}</p>

    <CostCenterEditor
      v-if="editing !== undefined && canWrite"
      :key="editing?.id ?? 'new'"
      :center="editing"
      :accounts="accounts"
      :saving="saving"
      :error="formError"
      @save="save"
      @cancel="editing = undefined"
    />

    <div v-if="deactivating && canWrite" role="group" aria-label="Confirmar desactivación"
      class="workspace-section border-warn/40 bg-warn/5 p-4 text-sm text-foreground">
      <p>¿Desactivar {{ deactivating.code }} · {{ deactivating.name }}? Dejará de estar disponible para nuevas partidas; sus movimientos anteriores se conservan.</p>
      <div class="mt-3 workspace-actions">
        <UiButton variant="secondary" :disabled="saving" @click="deactivating = null">Cancelar</UiButton>
        <UiButton variant="danger" :disabled="saving" @click="deactivate">Confirmar desactivación</UiButton>
      </div>
    </div>

    <div class="workspace-section overflow-x-auto">
      <table class="workspace-table min-w-[760px]">
        <caption class="sr-only">Maestro de centros de costo de la empresa</caption>
        <thead>
          <tr>
            <th scope="col">Código y nombre</th>
            <th scope="col">Destino Debe</th>
            <th scope="col">Contrapartida Haber</th>
            <th scope="col">Estado</th>
            <th v-if="canWrite" scope="col">Acciones</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-line">
          <tr v-if="loading"><td :colspan="canWrite ? 5 : 4" class="text-center" role="status">Cargando centros de costo…</td></tr>
          <tr v-else-if="rows.length === 0"><td :colspan="canWrite ? 5 : 4" class="text-center text-muted">No hay centros para esta consulta.</td></tr>
          <template v-else>
            <tr v-for="row in rows" :key="row.id">
              <td>
                <strong class="font-mono font-semibold">{{ row.code }}</strong>
                <span class="block text-muted">{{ row.name }}</span>
              </td>
              <td class="font-mono">{{ row.destination_debit_account?.code ?? 'No disponible' }}</td>
              <td class="font-mono">{{ row.destination_credit_account?.code ?? 'No disponible' }}</td>
              <td>
                <span class="rounded-md border px-2 py-1 text-xs font-semibold"
                  :class="row.is_active ? 'border-pos/30 bg-pos/5 text-pos' : 'border-line bg-soft text-muted'">
                  {{ row.is_active ? 'Activo' : 'Inactivo' }}
                </span>
              </td>
              <td v-if="canWrite">
                <div v-if="row.is_active" class="workspace-actions">
                  <button type="button" :disabled="saving"
                    class="rounded-md px-2 py-1 font-semibold text-brand hover:bg-soft disabled:opacity-40"
                    :aria-label="'Editar ' + row.code" @click="start(row)">Editar</button>
                  <button type="button" :disabled="saving"
                    class="rounded-md px-2 py-1 font-semibold text-neg hover:bg-neg/10 disabled:opacity-40"
                    :aria-label="'Desactivar ' + row.code" @click="editing = undefined; error = ''; deactivating = row">
                    Desactivar
                  </button>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <nav class="flex flex-wrap items-center justify-between gap-3 text-sm text-muted" aria-label="Paginación de centros de costo">
      <span>{{ total }} centros · Página {{ page }} de {{ lastPage }}</span>
      <div class="workspace-actions">
        <UiButton variant="secondary" size="sm" :disabled="loading || saving || page <= 1" @click="goToPage(page - 1)">Anterior</UiButton>
        <UiButton variant="secondary" size="sm" :disabled="loading || saving || page >= lastPage" @click="goToPage(page + 1)">Siguiente</UiButton>
      </div>
    </nav>
  </section>
</template>
