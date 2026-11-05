<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useQueryClient } from '@tanstack/vue-query';
import { Building2, CirclePlus, Loader2, Pencil, RefreshCcw, Search, ShieldCheck, Users, X } from 'lucide-vue-next';
import { AccountingApiClient, ApiHttpError } from '@/modules/accounting/api/httpClient';
import type { CompanyId } from '@/modules/accounting/types/accountingAccount.types';
import type { SunatCatalogItem } from '@/modules/accounting/types/catalogs.types';
import UiButton from '@/shared/ui/UiButton.vue';
import type { EntityMaster, EntityMasterInput, EntityMasterPage } from '../types/entityMaster.types';
import { filterEntitiesLocally, isCompleteEntityDirectory } from '../utils/entityLocalSearch';

const props = defineProps<{
  readonly activeCompanyId: CompanyId;
  readonly getAccessToken: () => string | null;
  readonly canWrite: boolean;
  readonly canDeactivate: boolean;
}>();

const queryClient = useQueryClient();
const api = computed(() => new AccountingApiClient('', {
  getAccessToken: props.getAccessToken,
  getActiveCompanyId: () => props.activeCompanyId,
}));

type RoleFilter = 'all' | 'is_client' | 'is_supplier' | 'is_employee';
const roleFilter = ref<RoleFilter>('all');
const searchInput = ref('');
const committedSearch = ref('');
const page = ref(1);
const list = ref<EntityMasterPage | null>(null);
// The unfiltered first page determines whether all records are already in memory.
const baseDirectory = ref<EntityMasterPage | null>(null);
const isLoading = ref(false);
const isSaving = ref(false);
const loadError = ref<string | null>(null);
const formError = ref<string | null>(null);
const successMessage = ref<string | null>(null);
const showForm = ref(false);
const editingId = ref<number | null>(null);
const pendingDeactivate = ref<EntityMaster | null>(null);
const documentTypes = ref<readonly SunatCatalogItem[]>([]);
let requestSequence = 0;

type Editable = {
  identity_document_type_code: string;
  tax_id: string;
  legal_name: string;
  commercial_name: string;
  fiscal_address: string;
  country_code: string;
  email: string;
  phone: string;
  is_client: boolean;
  is_supplier: boolean;
  is_employee: boolean;
  is_financial_institution: boolean;
  is_shareholder: boolean;
  is_tax_authority: boolean;
  is_related_party: boolean;
  is_retention_agent: boolean;
  is_good_taxpayer: boolean;
};
const emptyForm = (): Editable => ({
  identity_document_type_code: '6', tax_id: '', legal_name: '', commercial_name: '',
  fiscal_address: '', country_code: 'PER', email: '', phone: '',
  is_client: true, is_supplier: false, is_employee: false,
  is_financial_institution: false, is_shareholder: false, is_tax_authority: false,
  is_related_party: false, is_retention_agent: false, is_good_taxpayer: false,
});
const form = reactive<Editable>(emptyForm());
const flags = [
  { key: 'is_client', label: 'Cliente' },
  { key: 'is_supplier', label: 'Proveedor' },
  { key: 'is_employee', label: 'Trabajador' },
  { key: 'is_financial_institution', label: 'Institución financiera' },
  { key: 'is_shareholder', label: 'Accionista' },
  { key: 'is_tax_authority', label: 'Autoridad tributaria' },
] as const;

const isLocalDirectory = computed(() => isCompleteEntityDirectory(baseDirectory.value));
const listItems = computed(() => isLocalDirectory.value
  ? filterEntitiesLocally(baseDirectory.value?.data ?? [], searchInput.value, roleFilter.value)
  : list.value?.data ?? []);
const total = computed(() => isLocalDirectory.value ? listItems.value.length : list.value?.total ?? 0);
const canPrevious = computed(() => !isLocalDirectory.value && page.value > 1);
const canNext = computed(() => !isLocalDirectory.value && page.value < (list.value?.last_page ?? 1));

// Cache by company + filters + page. Never cache the Bearer or transfer rows between tenants.
const entityCachePrefix = (companyId: CompanyId) => ['entity-directory', companyId] as const;
const QUERY_FRESH_MS = 120_000;
const QUERY_GC_MS = 300_000;

async function loadEntities(): Promise<void> {
  const sequence = ++requestSequence;
  const requestedCompany = props.activeCompanyId;
  const requestToken = props.getAccessToken();
  // For a fully loaded directory, re-read only its base page, then filter in memory.
  const useBase = isLocalDirectory.value;
  const params: { search: string; role: RoleFilter; page: number } = useBase
    ? { search: '', role: 'all', page: 1 }
    : { search: committedSearch.value, role: roleFilter.value, page: page.value };
  const isBaseRequest = params.search === '' && params.role === 'all' && params.page === 1;
  isLoading.value = true;
  loadError.value = null;
  try {
    const requestApi = new AccountingApiClient('', {
      getAccessToken: () => requestToken,
      getActiveCompanyId: () => requestedCompany,
    });
    const response = await queryClient.fetchQuery({
      queryKey: [...entityCachePrefix(requestedCompany), params.search, params.role, params.page],
      queryFn: ({ signal }) => requestApi.fetchEntities({ ...params, signal }),
      staleTime: QUERY_FRESH_MS,
      gcTime: QUERY_GC_MS,
      retry: false,
    });
    if (sequence !== requestSequence || requestedCompany !== props.activeCompanyId
      || requestToken !== props.getAccessToken()) return;
    list.value = response;
    if (isBaseRequest) baseDirectory.value = response;

    // If adding a record makes a small directory exceed one page, revert to server filtering.
    if (isBaseRequest && !isCompleteEntityDirectory(response)
      && (committedSearch.value !== '' || roleFilter.value !== 'all' || page.value !== 1)) {
      await loadEntities();
    }
  } catch (error) {
    if (sequence === requestSequence && requestedCompany === props.activeCompanyId
      && requestToken === props.getAccessToken()) {
      list.value = null;
      if (isBaseRequest) baseDirectory.value = null;
      loadError.value = error instanceof Error ? error.message : 'No fue posible cargar los anexos.';
    }
  } finally {
    if (sequence === requestSequence) isLoading.value = false;
  }
}

async function refreshEntities(): Promise<void> {
  // Explicit refresh bypasses freshness and refetches only the current page.
  await queryClient.invalidateQueries({
    queryKey: entityCachePrefix(props.activeCompanyId),
    refetchType: 'none',
  });
  await loadEntities();
}

async function loadDocumentTypes(): Promise<void> {
  try {
    documentTypes.value = await queryClient.fetchQuery({
      queryKey: ['sunat-reference', 'document-types', 2],
      queryFn: ({ signal }) => api.value.fetchSunatTable(2, signal),
      staleTime: 3_600_000,
      gcTime: 3_600_000,
      retry: false,
    });
  } catch {
    // Keep safe known types usable even when global SUNAT catalog is temporarily inaccessible.
    documentTypes.value = [];
  }
}

const docChoices = computed(() => documentTypes.value.length > 0
  ? documentTypes.value
  : [{ code: '6', description: 'RUC' }, { code: '1', description: 'DNI' }]);

function applyFilters(): void {
  page.value = 1;
  committedSearch.value = searchInput.value.trim();
  // A complete directory is filtered reactively as the user types. Zero requests.
  if (isLocalDirectory.value) return;
  void loadEntities();
}

function changePage(next: number): void {
  if (isLocalDirectory.value || next < 1 || next > (list.value?.last_page ?? 1)) return;
  page.value = next;
  void loadEntities();
}

function openCreate(): void {
  if (!props.canWrite) return;
  Object.assign(form, emptyForm());
  editingId.value = null;
  formError.value = null;
  successMessage.value = null;
  showForm.value = true;
}

function openEdit(entity: EntityMaster): void {
  if (!props.canWrite || !entity.is_active) return;
  Object.assign(form, {
    identity_document_type_code: entity.identity_document_type_code,
    tax_id: entity.tax_id, legal_name: entity.legal_name,
    commercial_name: entity.commercial_name ?? '',
    fiscal_address: entity.fiscal_address ?? '', country_code: entity.country_code,
    email: entity.email ?? '', phone: entity.phone ?? '',
    is_client: entity.is_client, is_supplier: entity.is_supplier,
    is_employee: entity.is_employee, is_financial_institution: entity.is_financial_institution,
    is_shareholder: entity.is_shareholder, is_tax_authority: entity.is_tax_authority,
    is_related_party: entity.is_related_party, is_retention_agent: entity.is_retention_agent,
    is_good_taxpayer: entity.is_good_taxpayer,
  });
  editingId.value = entity.id;
  formError.value = null;
  successMessage.value = null;
  showForm.value = true;
}

function closeForm(): void {
  if (isSaving.value) return;
  showForm.value = false;
  editingId.value = null;
  formError.value = null;
}

function formatValidationError(error: unknown): string {
  if (error instanceof ApiHttpError && error.status === 422) {
    const entries = Object.values(error.validationErrors).flat();
    if (entries.length > 0) return entries.join(' · ');
  }
  return error instanceof Error ? error.message : 'No se pudo completar la operación.';
}

function validateForm(): string | null {
  if (!form.legal_name.trim()) return 'Ingrese la razón social o el nombre completo.';
  if (!form.tax_id.trim()) return 'Ingrese el número de documento.';
  if (form.identity_document_type_code === '1' && !/^\d{8}$/.test(form.tax_id.trim())) {
    return 'El DNI debe tener exactamente 8 dígitos.';
  }
  if (form.identity_document_type_code === '6' && !/^\d{11}$/.test(form.tax_id.trim())) {
    return 'El RUC debe tener exactamente 11 dígitos.';
  }
  if (!flags.some(({ key }) => form[key])) return 'Seleccione al menos un tipo de anexo.';
  if (!/^[A-Z]{3}$/.test(form.country_code.trim())) return 'El país debe ser un código de 3 letras.';
  return null;
}

async function saveEntity(): Promise<void> {
  if (!props.canWrite || isSaving.value) return;
  formError.value = validateForm();
  if (formError.value) return;
  const optional = (value: string): string | null => value.trim() || null;
  const payload: EntityMasterInput = {
    identity_document_type_code: form.identity_document_type_code,
    tax_id: form.tax_id.trim(), legal_name: form.legal_name.trim(),
    commercial_name: optional(form.commercial_name), fiscal_address: optional(form.fiscal_address),
    country_code: form.country_code.trim().toUpperCase(),
    email: optional(form.email), phone: optional(form.phone),
    is_client: form.is_client, is_supplier: form.is_supplier,
    is_employee: form.is_employee, is_financial_institution: form.is_financial_institution,
    is_shareholder: form.is_shareholder, is_tax_authority: form.is_tax_authority,
    is_related_party: form.is_related_party, is_retention_agent: form.is_retention_agent,
    is_good_taxpayer: form.is_good_taxpayer,
  };

  isSaving.value = true;
  try {
    const entity = editingId.value === null
      ? await api.value.createEntity(payload)
      : await api.value.updateEntity(editingId.value, payload);
    successMessage.value = 'Anexo ' + entity.legal_name + ' guardado y auditado.';
    showForm.value = false;
    editingId.value = null;
    await refreshEntities();
  } catch (error) {
    formError.value = formatValidationError(error);
  } finally {
    isSaving.value = false;
  }
}

async function deactivate(): Promise<void> {
  if (!props.canDeactivate || !pendingDeactivate.value || isSaving.value) return;
  isSaving.value = true;
  loadError.value = null;
  try {
    await api.value.deactivateEntity(pendingDeactivate.value.id);
    successMessage.value = 'Anexo desactivado sin eliminación física.';
    pendingDeactivate.value = null;
    await refreshEntities();
  } catch (error) {
    loadError.value = formatValidationError(error);
  } finally {
    isSaving.value = false;
  }
}

function roleLabels(entity: EntityMaster): string[] {
  return flags.filter(({ key }) => entity[key]).map(({ label }) => label);
}

watch(() => props.activeCompanyId, () => {
  ++requestSequence; // stale HTTP responses from old tenant must never populate this view
  baseDirectory.value = null;
  list.value = null;
  page.value = 1;
  roleFilter.value = 'all';
  searchInput.value = '';
  committedSearch.value = '';
  showForm.value = false;
  editingId.value = null;
  pendingDeactivate.value = null;
  successMessage.value = null;
  void loadEntities();
});

onMounted(() => {
  void loadEntities();
  void loadDocumentTypes();
});
</script>

<template>
  <section class="workspace-page space-y-5" aria-label="Directorio de terceros y anexos">
    <header class="workspace-heading">
      <div class="flex items-center gap-3">
        <div class="grid size-9 place-items-center rounded-lg bg-brand/10 text-brand">
          <Users class="size-5" />
        </div>
        <div>
          <h1 class="text-xl font-semibold tracking-tight text-foreground">Terceros y anexos</h1>
          <p class="text-xs text-muted">Directorio de clientes, proveedores, instituciones financieras y demás terceros de la empresa activa</p>
        </div>
      </div>
      <UiButton v-if="canWrite" variant="primary" @click="openCreate">
        <CirclePlus class="size-4" aria-hidden="true" /> Nuevo anexo
      </UiButton>
    </header>

    <div v-if="successMessage" role="status" class="rounded-md border border-pos/30 bg-pos/5 px-3 py-2 text-sm text-pos">
      {{ successMessage }}
    </div>
    <div v-if="loadError" role="alert" class="rounded-md border border-neg/30 bg-neg/5 px-3 py-2 text-sm text-neg">
      {{ loadError }}
    </div>

    <div class="workspace-section p-3 sm:p-4">
      <div class="workspace-toolbar flex flex-wrap items-end gap-2 border-0 p-0">
        <label class="workspace-field min-w-[200px] flex-1 space-y-1">
          <span class="text-xs font-medium text-foreground">Buscar por nombre o documento</span>
          <span class="flex items-center gap-2 rounded-md border border-line bg-background px-2">
            <Search class="size-4 text-muted" />
            <input v-model="searchInput" aria-label="Buscar anexo" aria-describedby="entity-search-help" type="search" maxlength="100" class="w-full bg-transparent py-2 text-sm outline-none" placeholder="RUC, DNI, razón social…" @keyup.enter="applyFilters" />
          </span>
          <span id="entity-search-help" class="block text-xs leading-5 text-muted" aria-live="polite">
            {{ isLocalDirectory ? 'Filtrado instantáneo sobre los ' + baseDirectory?.total + ' anexos cargados · sin consultas adicionales.' : searchInput.trim() !== committedSearch ? 'Directorio paginado: presiona Enter o pulsa Buscar para consultar.' : committedSearch ? 'Filtro paginado aplicado; las consultas se reutilizan desde caché.' : 'Directorio paginado: presiona Enter o pulsa Buscar para filtrar.' }}
          </span>
        </label>
        <label class="workspace-field space-y-1">
          <span class="block text-xs font-medium text-foreground">Tipo</span>
          <select v-model="roleFilter" class="rounded-md border border-line bg-background px-3 py-2 text-sm" @change="applyFilters">
            <option value="all">Todos</option>
            <option value="is_client">Clientes</option>
            <option value="is_supplier">Proveedores</option>
            <option value="is_employee">Trabajadores</option>
          </select>
        </label>
        <UiButton v-if="!isLocalDirectory" variant="secondary" @click="applyFilters">
          <Search class="size-4" aria-hidden="true" /> Buscar
        </UiButton>
        <UiButton variant="secondary" size="icon" aria-label="Actualizar directorio" :disabled="isLoading" @click="refreshEntities">
          <RefreshCcw class="size-4" :class="{ 'animate-spin': isLoading }" aria-hidden="true" />
        </UiButton>
      </div>
      <div class="mt-3 flex items-center justify-between text-xs text-muted">
        <span>{{ total }} anexos en la consulta · {{ isLocalDirectory ? 'filtrado local' : 'búsqueda paginada' }}</span>
        <span v-if="!canWrite" class="inline-flex items-center gap-1"><ShieldCheck class="size-3.5" /> Solo lectura</span>
      </div>

      <div class="mt-3 overflow-x-auto">
        <table class="workspace-table min-w-[680px]">
          <caption class="sr-only">Terceros y anexos registrados para la empresa</caption>
          <thead class="border-b border-line text-muted">
            <tr><th scope="col">Nombre / Razón social</th><th scope="col">Documento</th><th scope="col">Roles</th><th scope="col">Contacto</th><th scope="col" class="text-right">Acciones</th></tr>
          </thead>
          <tbody class="divide-y divide-line">
            <tr v-if="isLoading"><td colspan="5" class="py-4" role="status" aria-label="Cargando anexos">
              <span class="mb-3 flex items-center gap-2 text-muted"><Loader2 class="size-4 animate-spin text-brand" /> Cargando anexos…</span>
              <span v-for="row in 3" :key="row" class="ui-skeleton mb-2 block h-10 w-full" aria-hidden="true" />
            </td></tr>
            <tr v-else-if="listItems.length === 0"><td colspan="5" class="py-10 text-center text-muted">No hay anexos para estos filtros. {{ canWrite ? 'Puede registrar el primero.' : '' }}</td></tr>
            <tr v-for="entity in (isLoading ? [] : listItems)" :key="entity.id" class="hover:bg-surface-muted/60">
              <td class="py-3 pr-3">
                <span class="font-semibold text-foreground">{{ entity.legal_name }}</span>
                <span v-if="entity.commercial_name" class="block text-muted">{{ entity.commercial_name }}</span>
              </td>
              <td class="py-3 pr-3 font-mono">{{ entity.identity_document_type_code }} · {{ entity.tax_id }}</td>
              <td class="py-3 pr-3"><span v-for="role in roleLabels(entity)" :key="role" class="mb-1 mr-1 inline-block rounded border border-line px-1.5 py-0.5 text-[10px]">{{ role }}</span></td>
              <td class="py-3 pr-3 text-muted">{{ entity.email || entity.phone || '—' }}</td>
              <td class="py-3 text-right whitespace-nowrap">
                <button v-if="canWrite" type="button" class="mr-1 rounded border border-line px-2 py-1.5 hover:bg-background" :aria-label="'Editar ' + entity.legal_name" @click="openEdit(entity)"><Pencil class="size-3.5" /></button>
                <button v-if="canDeactivate" type="button" class="rounded border border-red-500/30 px-2 py-1.5 text-red-600 hover:bg-red-500/10" @click="pendingDeactivate = entity">Desactivar</button>
                <span v-if="!canWrite" class="text-muted">Lectura</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="mt-3 flex flex-wrap items-center justify-between gap-3 border-t border-line pt-3 text-sm">
        <span class="text-muted">Página {{ list?.current_page ?? 1 }} / {{ list?.last_page ?? 1 }}</span>
        <div class="flex gap-2">
          <button type="button" class="rounded border border-line px-3 py-1.5 disabled:opacity-40" :disabled="!canPrevious || isLoading" @click="changePage(page - 1)">Anterior</button>
          <button type="button" class="rounded border border-line px-3 py-1.5 disabled:opacity-40" :disabled="!canNext || isLoading" @click="changePage(page + 1)">Siguiente</button>
        </div>
      </div>
    </div>

    <div v-if="showForm && canWrite" class="workspace-section p-4 sm:p-5">
      <div class="mb-4 flex items-start justify-between gap-3">
        <div>
          <h2 class="text-base font-semibold">{{ editingId === null ? 'Registrar anexo' : 'Editar anexo' }}</h2>
          <p class="text-xs text-muted">El documento es único por empresa. Los cambios generan auditoría.</p>
        </div>
        <button type="button" aria-label="Cerrar formulario" class="rounded-md p-1 hover:bg-surface-muted" :disabled="isSaving" @click="closeForm"><X class="size-4" /></button>
      </div>

      <div v-if="formError" role="alert" class="mb-4 rounded-md border border-neg/30 bg-neg/5 px-3 py-2 text-xs text-neg">{{ formError }}</div>
      <form class="workspace-form grid grid-cols-1 gap-3 sm:grid-cols-2" @submit.prevent="saveEntity">
        <label class="space-y-1 text-xs">
          <span class="font-medium">Tipo de documento SUNAT *</span>
          <select v-model="form.identity_document_type_code" required class="w-full rounded-md border border-line bg-background px-3 py-2 text-sm">
            <option v-for="doc in docChoices" :key="doc.code" :value="doc.code">{{ doc.code }} — {{ doc.description }}</option>
          </select>
        </label>
        <label class="space-y-1 text-xs">
          <span class="font-medium">Número de documento *</span>
          <input v-model.trim="form.tax_id" required maxlength="20" class="w-full rounded-md border border-line bg-background px-3 py-2 text-sm" placeholder="DNI o RUC" />
        </label>
        <label class="space-y-1 text-xs sm:col-span-2">
          <span class="font-medium">Razón social / Nombre completo *</span>
          <input v-model.trim="form.legal_name" required maxlength="200" class="w-full rounded-md border border-line bg-background px-3 py-2 text-sm" />
        </label>
        <label class="space-y-1 text-xs">
          <span class="font-medium">Nombre comercial</span>
          <input v-model="form.commercial_name" maxlength="200" class="w-full rounded-md border border-line bg-background px-3 py-2 text-sm" />
        </label>
        <label class="space-y-1 text-xs">
          <span class="font-medium">País (ISO 3166-1 alfa-3)</span>
          <input v-model.trim="form.country_code" maxlength="3" required class="w-full rounded-md border border-line bg-background px-3 py-2 text-sm uppercase" />
        </label>
        <label class="space-y-1 text-xs sm:col-span-2">
          <span class="font-medium">Domicilio fiscal</span>
          <input v-model="form.fiscal_address" maxlength="300" class="w-full rounded-md border border-line bg-background px-3 py-2 text-sm" />
        </label>
        <label class="space-y-1 text-xs">
          <span class="font-medium">Correo electrónico</span>
          <input v-model="form.email" type="email" maxlength="200" class="w-full rounded-md border border-line bg-background px-3 py-2 text-sm" />
        </label>
        <label class="space-y-1 text-xs">
          <span class="font-medium">Teléfono</span>
          <input v-model="form.phone" maxlength="40" class="w-full rounded-md border border-line bg-background px-3 py-2 text-sm" />
        </label>
        <fieldset class="sm:col-span-2 rounded-md border border-line p-3">
          <legend class="px-1 text-xs font-semibold">Clasificación del anexo *</legend>
          <p class="mb-2 text-xs text-muted">Puede seleccionar varios roles para un mismo documento.</p>
          <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
            <label v-for="flag in flags" :key="flag.key" class="flex items-center gap-2 text-xs">
              <input v-model="form[flag.key]" type="checkbox" class="accent-brand" />
              {{ flag.label }}
            </label>
          </div>
        </fieldset>
        <fieldset class="sm:col-span-2 rounded-md border border-line p-3">
          <legend class="px-1 text-xs font-semibold">Condiciones adicionales</legend>
          <div class="flex flex-wrap gap-x-5 gap-y-2">
            <label class="flex gap-2 text-xs"><input v-model="form.is_related_party" type="checkbox" /> Parte vinculada</label>
            <label class="flex gap-2 text-xs"><input v-model="form.is_retention_agent" type="checkbox" /> Agente de retención</label>
            <label class="flex gap-2 text-xs"><input v-model="form.is_good_taxpayer" type="checkbox" /> Buen contribuyente</label>
          </div>
        </fieldset>
        <div class="flex flex-wrap items-center justify-end gap-2 sm:col-span-2">
          <UiButton variant="secondary" :disabled="isSaving" @click="closeForm">Cancelar</UiButton>
          <UiButton type="submit" variant="primary" :loading="isSaving">
            <Building2 v-if="!isSaving" class="size-4" aria-hidden="true" />
            {{ editingId === null ? 'Crear anexo' : 'Guardar cambios' }}
          </UiButton>
        </div>
      </form>
      <p class="mt-3 text-[11px] text-muted">Los datos bancarios y detracciones se administrarán por un flujo especializado; aquí no pueden modificarse.</p>
    </div>

    <div v-if="pendingDeactivate && canDeactivate" class="workspace-section border-neg/30 bg-neg/5 p-4" role="group" aria-label="Confirmación de desactivación">
      <p class="text-sm font-semibold">¿Desactivar {{ pendingDeactivate.legal_name }}?</p>
      <p class="mt-1 text-xs text-muted">El registro se conserva para trazabilidad contable. No se elimina físicamente.</p>
      <div class="mt-3 flex gap-2">
        <UiButton variant="secondary" :disabled="isSaving" @click="pendingDeactivate = null">Cancelar</UiButton>
        <UiButton variant="danger" :disabled="isSaving" @click="deactivate">Confirmar desactivación</UiButton>
      </div>
    </div>
  </section>
</template>
