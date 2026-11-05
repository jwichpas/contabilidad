<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useQueryClient } from '@tanstack/vue-query';
import { BookOpen, CheckCircle2, Plus, RefreshCw, ShieldCheck } from 'lucide-vue-next';
import { AccountingApiClient } from '../api/httpClient';
import type { CompanyId } from '../types/accountingAccount.types';
import type { CompanySubdiaryItem } from '../types/journalEntry.types';

const props = defineProps<{
  readonly activeCompanyId: CompanyId;
  readonly getAccessToken: () => string | null;
  readonly canWrite: boolean;
}>();
const queryClient = useQueryClient();
const cachePrefix = computed(() => ['company-subdiaries', props.activeCompanyId] as const);
const api = computed(() => new AccountingApiClient('', {
  getAccessToken: props.getAccessToken,
  getActiveCompanyId: () => props.activeCompanyId,
}));
const records = ref<readonly CompanySubdiaryItem[]>([]);
const loading = ref(false);
const saving = ref(false);
const search = ref('');
const code = ref('');
const name = ref('');
const editingId = ref<number | null>(null);
const message = ref('');
const error = ref('');
let sequence = 0;

const visible = computed(() => records.value.filter(item =>
  `${item.code} ${item.name}`.toLowerCase().includes(search.value.trim().toLowerCase()),
));

async function load(): Promise<void> {
  const requestId = ++sequence;
  const tenant = props.activeCompanyId;
  const token = props.getAccessToken();
  loading.value = true;
  error.value = '';
  try {
    const requestApi = new AccountingApiClient('', {
      getAccessToken: () => token,
      getActiveCompanyId: () => tenant,
    });
    const data = await queryClient.fetchQuery({
      queryKey: ['company-subdiaries', tenant, 'all'],
      queryFn: ({ signal }) => requestApi.fetchCompanySubdiaries(false, signal),
      staleTime: 60_000,
      gcTime: 300_000,
      retry: false,
    });
    if (requestId === sequence && tenant === props.activeCompanyId
      && token === props.getAccessToken()) records.value = data;
  } catch (e) {
    if (requestId === sequence && tenant === props.activeCompanyId
      && token === props.getAccessToken()) {
      error.value = e instanceof Error ? e.message : 'No se pudieron cargar los subdiarios.';
    }
  } finally {
    if (requestId === sequence) loading.value = false;
  }
}

async function refresh(): Promise<void> {
  await queryClient.invalidateQueries({ queryKey: cachePrefix.value, refetchType: 'none' });
  await load();
}
function reset(): void {
  code.value = '';
  name.value = '';
  editingId.value = null;
}
function edit(item: CompanySubdiaryItem): void {
  editingId.value = item.id;
  code.value = item.code;
  name.value = item.name;
  message.value = '';
  error.value = '';
}
async function save(): Promise<void> {
  if (!props.canWrite || saving.value) return;
  const value = name.value.trim();
  const normalizedCode = code.value.trim().toUpperCase();
  if (!value || value.length < 2 || value.length > 120) {
    error.value = 'El nombre debe contener entre 2 y 120 caracteres.';
    return;
  }
  if (editingId.value === null && !/^[A-Z0-9_]{2,24}$/.test(normalizedCode)) {
    error.value = 'Código: 2 a 24 caracteres, letras mayúsculas, números o guion bajo.';
    return;
  }
  saving.value = true;
  error.value = '';
  try {
    if (editingId.value === null) {
      await api.value.createCompanySubdiary({ code: normalizedCode, name: value });
      message.value = 'Subdiario creado para esta empresa.';
    } else {
      await api.value.renameCompanySubdiary(editingId.value, value);
      message.value = 'Nombre actualizado sin cambiar el código histórico.';
    }
    reset();
    await refresh();
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'No se pudo guardar el subdiario.';
  } finally {
    saving.value = false;
  }
}
async function changeStatus(item: CompanySubdiaryItem): Promise<void> {
  if (!props.canWrite || saving.value) return;
  const action = item.is_active ? 'desactivar' : 'activar';
  if (!window.confirm(`¿Confirma ${action} el subdiario ${item.code}? Los asientos históricos permanecerán intactos.`)) return;
  saving.value = true;
  error.value = '';
  try {
    await api.value.setCompanySubdiaryActive(item.id, !item.is_active);
    message.value = `Subdiario ${item.code} ${item.is_active ? 'desactivado' : 'activado'}.`;
    await refresh();
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'No se pudo actualizar el estado.';
  } finally {
    saving.value = false;
  }
}
onMounted(() => { void load(); });
</script>

<template>
  <section class="mx-auto w-full max-w-6xl space-y-5">
    <header class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <div class="flex items-center gap-2">
          <BookOpen class="h-5 w-5 text-brand" />
          <h1 class="text-xl font-semibold text-foreground">Maestro de subdiarios</h1>
        </div>
        <p class="mt-1 text-xs text-muted">
          Configuración independiente por empresa · Códigos estables · Asientos históricos inmutables
        </p>
      </div>
      <button type="button" :disabled="loading" class="rounded-lg border border-line bg-surf px-3 py-2 text-xs font-semibold text-foreground disabled:opacity-40" @click="refresh">
        <RefreshCw class="mr-1 inline h-3.5 w-3.5" /> Actualizar
      </button>
    </header>

    <div v-if="error" role="alert" class="rounded-lg border border-red-400/40 bg-red-500/10 p-3 text-sm text-red-700">{{ error }}</div>
    <div v-if="message" role="status" class="flex items-center gap-2 rounded-lg border border-green-400/30 bg-green-500/10 p-3 text-sm text-green-800">
      <CheckCircle2 class="h-4 w-4" /> {{ message }}
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_320px]">
      <div class="overflow-hidden rounded-xl border border-line bg-surf">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line p-4">
          <div class="text-sm font-semibold text-foreground">Subdiarios de la empresa ({{ records.length }})</div>
          <input v-model="search" type="search" placeholder="Buscar por código o nombre"
            class="min-w-0 rounded-md border border-line bg-surf px-3 py-2 text-xs text-foreground"
            aria-label="Buscar subdiario" />
        </div>
        <p v-if="loading" role="status" class="p-6 text-sm text-muted">Consultando subdiarios…</p>
        <p v-else-if="visible.length === 0" class="p-6 text-sm text-muted">No hay subdiarios para esta búsqueda.</p>
        <div v-else class="w-full overflow-x-auto">
          <table class="w-full min-w-[520px] text-left text-xs">
            <thead class="border-b border-line bg-soft text-muted">
              <tr><th class="p-3">Código</th><th class="p-3">Nombre</th><th class="p-3">Estado</th><th class="p-3 text-right">Acciones</th></tr>
            </thead>
            <tbody>
              <tr v-for="item in visible" :key="item.id" class="border-b border-line/50">
                <td class="p-3 font-mono font-semibold text-foreground">{{ item.code }}</td>
                <td class="p-3 text-foreground">{{ item.name }}</td>
                <td class="p-3">
                  <span :class="item.is_active ? 'text-green-700' : 'text-muted'">{{ item.is_active ? 'Activo' : 'Inactivo' }}</span>
                </td>
                <td class="whitespace-nowrap p-3 text-right">
                  <button v-if="canWrite" type="button" class="mr-2 rounded border border-line px-2 py-1 text-foreground" :disabled="saving" @click="edit(item)">Renombrar</button>
                  <button v-if="canWrite" type="button" :disabled="saving" class="rounded border border-line px-2 py-1 text-foreground" @click="changeStatus(item)">
                    {{ item.is_active ? 'Desactivar' : 'Activar' }}
                  </button>
                  <span v-else class="text-muted">Solo lectura</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="self-start rounded-xl border border-line bg-surf p-4">
        <div class="mb-3 flex items-center gap-2 text-sm font-semibold text-foreground">
          <Plus class="h-4 w-4 text-brand" /> {{ editingId === null ? 'Nuevo subdiario' : 'Renombrar subdiario' }}
        </div>
        <p class="mb-4 text-xs text-muted">
          Los códigos no se modifican una vez creados. Desactive un subdiario para impedir su uso futuro sin borrar su historial.
        </p>
        <form class="space-y-3" @submit.prevent="save">
          <label class="block text-xs font-medium text-foreground">
            Código
            <input v-model="code" type="text" maxlength="24" :disabled="!canWrite || editingId !== null"
              placeholder="EJ: HONORARIOS" class="mt-1 block w-full rounded-md border border-line bg-surf px-3 py-2 font-mono text-xs text-foreground disabled:opacity-50" />
          </label>
          <label class="block text-xs font-medium text-foreground">
            Nombre
            <input v-model="name" type="text" maxlength="120" :disabled="!canWrite"
              placeholder="Nombre del subdiario" class="mt-1 block w-full rounded-md border border-line bg-surf px-3 py-2 text-xs text-foreground disabled:opacity-50" />
          </label>
          <div class="flex gap-2">
            <button type="submit" :disabled="!canWrite || saving" class="rounded-md bg-brand px-4 py-2 text-xs font-semibold text-white disabled:opacity-40">
              {{ saving ? 'Guardando…' : editingId === null ? 'Crear subdiario' : 'Guardar nombre' }}
            </button>
            <button v-if="editingId !== null" type="button" class="rounded-md border border-line px-3 py-2 text-xs text-foreground" @click="reset">Cancelar</button>
          </div>
        </form>
        <p class="mt-5 flex gap-2 border-t border-line pt-4 text-[11px] text-muted">
          <ShieldCheck class="h-4 w-4 shrink-0" /> Aislamiento RLS por empresa. Los asientos mantienen el código original aun si el subdiario está inactivo.
        </p>
      </div>
    </div>
  </section>
</template>
