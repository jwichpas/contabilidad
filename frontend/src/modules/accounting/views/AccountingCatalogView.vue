<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import {
  AlertCircle,
  Banknote,
  Building2,
  Coins,
  FileSpreadsheet,
  Filter,
  FolderTree,
  Keyboard,
  Layers,
  Minimize2,
  Plus,
  RefreshCw,
  Search,
  Sparkles,
  Workflow,
} from 'lucide-vue-next';
import AccountDetailInspector from '../components/AccountDetailInspector.vue';
import AccountFormModal from '../components/AccountFormModal.vue';
import AccountTreeNodeRow from '../components/AccountTreeNodeRow.vue';
import { useAccountingCatalogs } from '../composables/useAccountingCatalogs';
import { useAccountTree } from '../composables/useAccountTree';
import type {
  CompanyId,
  CreateAccountingAccountPayload,
  FlattenedAccountRow,
} from '../types/accountingAccount.types';

const props = defineProps<{
  readonly activeCompanyId: CompanyId;
  readonly getAccessToken: () => string | null;
}>();

const {
  treeNodes,
  visibleRows,
  searchQuery,
  onlyOperational,
  includeInactive,
  focusedRowIndex,
  focusedRow,
  isLoading,
  errorMessage,
  fetchTree,
  refreshTree,
  expandAll,
  collapseAll,
  toggleExpand,
  handleTreeKeydown,
  createAccount,
  updateAccount,
  deactivateAccount,
} = useAccountTree({
  getActiveCompanyId: () => props.activeCompanyId,
  getAccessToken: props.getAccessToken,
});

const {
  costCenters,
  cashFlowAreas,
  currencyOptions,
  rubricOptions,
  costCenterOptions,
  cashFlowAreaOptions,
  loadCatalogs,
} = useAccountingCatalogs({
  getActiveCompanyId: () => props.activeCompanyId,
  getAccessToken: props.getAccessToken,
});

const searchInputRef = ref<HTMLInputElement | null>(null);
const catalogsReadyForCompany = shallowRef<CompanyId | null>(null);
const catalogsLoading = shallowRef(false);
let pendingCatalogLoad: Promise<void> | null = null;

// No descargar catálogos de edición hasta que el usuario abra el formulario.
// Evita seis GET costosos en el montaje del Plan de Cuentas.
async function ensureEditingCatalogs(): Promise<void> {
  if (catalogsReadyForCompany.value === props.activeCompanyId) return;
  if (pendingCatalogLoad) return pendingCatalogLoad;
  const requestedCompany = props.activeCompanyId;
  catalogsLoading.value = true;
  pendingCatalogLoad = (async () => {
    try {
      await loadCatalogs();
      if (requestedCompany === props.activeCompanyId) {
        catalogsReadyForCompany.value = requestedCompany;
      }
    } finally {
      catalogsLoading.value = false;
      pendingCatalogLoad = null;
    }
  })();
  return pendingCatalogLoad;
}

const isModalOpen = shallowRef(false);
const modalMode = shallowRef<'create' | 'edit'>('create');
const modalParentRow = shallowRef<FlattenedAccountRow | null>(null);
const modalEditingRow = shallowRef<FlattenedAccountRow | null>(null);
const isMobileInspectorOpen = shallowRef(false);

type ElementFilterCode = 'ALL' | '1' | '2_3' | '4' | '5' | '6' | '7' | '9';
const activeElementFilter = shallowRef<ElementFilterCode>('ALL');
const onlyWithDestination = shallowRef(false);
const onlyCashFlow = shallowRef(false);

const elementTabs: ReadonlyArray<{
  readonly id: ElementFilterCode;
  readonly code: string;
  readonly label: string;
}> = [
  { id: 'ALL', code: 'PCGE', label: 'Todos los Elementos' },
  { id: '1', code: 'Elem. 1', label: 'Disponible y Exigible (10-19)' },
  { id: '4', code: 'Elem. 4', label: 'Pasivo (40-49)' },
  { id: '5', code: 'Elem. 5', label: 'Patrimonio (50-59)' },
  { id: '6', code: 'Elem. 6', label: 'Gastos por Naturaleza (60-69)' },
  { id: '7', code: 'Elem. 7', label: 'Ingresos y CICC (70-79)' },
  { id: '9', code: 'Elem. 9', label: 'Analítica Destino (94-95)' },
];

// Aplanamiento completo para métricas ejecutivas KPI
const allAccountsFlat = computed(() => {
  const result: FlattenedAccountRow['node'][] = [];
  const walk = (nodes: typeof treeNodes.value): void => {
    for (const node of nodes) {
      result.push(node);
      if (node.children.length > 0) {
        walk(node.children);
      }
    }
  };
  walk(treeNodes.value);
  return result;
});

const kpiMetrics = computed(() => {
  const all = allAccountsFlat.value;
  const total = all.length;
  const operational = all.filter((a) => a.is_operational).length;
  const foreignOrMulti = all.filter(
    (a) => a.is_operational && (a.currency_mode === 'USD' || a.currency_mode === 'MULTI'),
  ).length;
  const withEntity = all.filter(
    (a) => a.requires_auxiliary || a.entity_requirement_type !== 'NONE',
  ).length;
  const withDestination = all.filter(
    (a) => a.requires_cost_center || a.has_automatic_destination,
  ).length;
  const withCashFlowOrRubric = all.filter(
    (a) => a.requires_cash_flow_area || a.financial_statement_rubric !== 'NONE',
  ).length;

  return {
    total,
    operational,
    groups: total - operational,
    foreignOrMulti,
    withEntity,
    withDestination,
    withCashFlowOrRubric,
  };
});

// Filtrado combinado con las pestañas de Elemento Contable y chips rápidos
const filteredRows = computed<readonly FlattenedAccountRow[]>(() => {
  return visibleRows.value.filter((row) => {
    if (activeElementFilter.value !== 'ALL') {
      const firstDigit = row.node.code.charAt(0);
      if (activeElementFilter.value === '2_3') {
        if (firstDigit !== '2' && firstDigit !== '3') return false;
      } else if (firstDigit !== activeElementFilter.value) {
        return false;
      }
    }

    if (onlyWithDestination.value) {
      if (!row.node.requires_cost_center && !row.node.has_automatic_destination) {
        return false;
      }
    }

    if (onlyCashFlow.value) {
      if (!row.node.requires_cash_flow_area && row.node.cash_flow_category === 'NONE') {
        return false;
      }
    }

    return true;
  });
});

const selectedInspectorRow = computed<FlattenedAccountRow | null>(() => {
  return focusedRow.value ?? filteredRows.value[0] ?? visibleRows.value[0] ?? null;
});

const selectRowByNode = (row: FlattenedAccountRow): void => {
  const idx = visibleRows.value.findIndex((r) => r.node.id === row.node.id);
  if (idx >= 0) {
    focusedRowIndex.value = idx;
  }
};

const inspectRowOnMobile = (row: FlattenedAccountRow): void => {
  selectRowByNode(row);
  isMobileInspectorOpen.value = true;
};

const openCreateRootModal = async (): Promise<void> => {
  await ensureEditingCatalogs();
  modalMode.value = 'create';
  modalParentRow.value = null;
  modalEditingRow.value = null;
  isModalOpen.value = true;
};

const openCreateChildModal = async (parentRow: FlattenedAccountRow): Promise<void> => {
  if (parentRow.node.is_operational || parentRow.node.level >= 4) return;
  await ensureEditingCatalogs();
  modalMode.value = 'create';
  modalParentRow.value = parentRow;
  modalEditingRow.value = null;
  isModalOpen.value = true;
};

const openEditModal = async (row: FlattenedAccountRow): Promise<void> => {
  await ensureEditingCatalogs();
  modalMode.value = 'edit';
  modalParentRow.value = null;
  modalEditingRow.value = row;
  isModalOpen.value = true;
};

const handleDeactivate = async (row: FlattenedAccountRow): Promise<void> => {
  await deactivateAccount(row.node.id);
};

const handleModalSubmit = async (payload: CreateAccountingAccountPayload): Promise<void> => {
  if (modalMode.value === 'create') {
    await createAccount(payload);
  } else if (modalEditingRow.value !== null) {
    await updateAccount(modalEditingRow.value.node.id, {
      name: payload.name,
      account_type: payload.account_type,
      balance_type: payload.balance_type,
      currency_mode: payload.currency_mode,
      requires_currency: payload.requires_currency,
      requires_auxiliary: payload.requires_auxiliary,
      entity_requirement_type: payload.entity_requirement_type,
      requires_cost_center: payload.requires_cost_center,
      requires_company_area: payload.requires_company_area,
      has_automatic_destination: payload.has_automatic_destination,
      destination_debit_code: payload.destination_debit_code,
      destination_credit_code: payload.destination_credit_code,
      requires_cash_flow_area: payload.requires_cash_flow_area,
      cash_flow_category: payload.cash_flow_category,
      financial_statement_rubric: payload.financial_statement_rubric,
      is_operational: payload.is_operational,
      is_active: modalEditingRow.value.node.is_active,
    });
  }
  isModalOpen.value = false;
};

const onGlobalKeydown = (event: KeyboardEvent): void => {
  if (isModalOpen.value) {
    return;
  }

  const target = event.target as HTMLElement | null;
  const isTypingInInput =
    target?.tagName === 'INPUT' || target?.tagName === 'SELECT' || target?.tagName === 'TEXTAREA';

  if ((event.key === '/' || (event.ctrlKey && event.key.toLowerCase() === 'k')) && !isTypingInInput) {
    event.preventDefault();
    searchInputRef.value?.focus();
    searchInputRef.value?.select();
    return;
  }

  if (event.altKey && event.key.toLowerCase() === 'n') {
    event.preventDefault();
    if (
      focusedRow.value !== null &&
      !focusedRow.value.node.is_operational &&
      focusedRow.value.node.level < 4
    ) {
      openCreateChildModal(focusedRow.value);
    } else {
      openCreateRootModal();
    }
    return;
  }

  if (!isTypingInInput) {
    handleTreeKeydown(event, {
      onEdit: openEditModal,
      onCreateChild: openCreateChildModal,
      onDeactivate: handleDeactivate,
    });
  }
};

onMounted(async () => {
  window.addEventListener('keydown', onGlobalKeydown);
  if (props.getAccessToken()) {
    await fetchTree();
  }
});

watch(
  () => props.getAccessToken(),
  async (token) => {
    if (token) {
      await fetchTree();
    }
  },
);

watch(
  () => props.activeCompanyId,
  async () => {
    catalogsReadyForCompany.value = null;
    if (props.getAccessToken()) {
      await fetchTree();
    }
  },
);

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onGlobalKeydown);
});
</script>

<template>
  <section class="workspace-page space-y-4" aria-label="Plan contable PCGE">
    <header class="workspace-heading">
      <div>
        <h1>Plan contable empresarial (PCGE)</h1>
        <p>Consulta y configura cuentas, dimensiones contables y destinos automáticos de la empresa.</p>
      </div>
      <span class="rounded-md border border-line bg-soft px-3 py-1.5 text-xs font-medium text-muted">Vista jerárquica</span>
    </header>
    <p v-if="catalogsLoading" role="status" class="rounded-md border border-line bg-soft px-3 py-2 text-xs text-muted">
      Cargando las opciones necesarias para configurar la cuenta…
    </p>
    <!-- 1. Banda Ejecutiva de KPIs del Plan Contable -->
    <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 gap-3">
      <!-- KPI 1: Total Cuentas -->
      <div class="kpi flex flex-col justify-between">
        <div class="flex items-center justify-between">
          <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">
            Estructura PCGE
          </span>
          <span class="w-7 h-7 rounded-md bg-brand/10 text-brand inline-flex items-center justify-center">
            <FolderTree class="w-4 h-4" />
          </span>
        </div>
        <div class="mt-2 flex items-baseline gap-2">
          <b class="text-2xl font-semibold num text-foreground">{{ kpiMetrics.total }}</b>
          <span class="text-xs text-muted">cuentas</span>
        </div>
        <div class="mt-1.5 flex items-center gap-2 text-[11px] text-muted">
          <span class="text-pos font-semibold">{{ kpiMetrics.operational }} hojas</span>
          <span>·</span>
          <span>{{ kpiMetrics.groups }} grupos</span>
        </div>
      </div>

      <!-- KPI 2: Bimoneda / Moneda Extranjera -->
      <div class="kpi flex flex-col justify-between">
        <div class="flex items-center justify-between">
          <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">
            Bimoneda / NIC 21
          </span>
          <span class="w-7 h-7 rounded-md bg-pos/10 text-pos inline-flex items-center justify-center">
            <Coins class="w-4 h-4" />
          </span>
        </div>
        <div class="mt-2 flex items-baseline gap-2">
          <b class="text-2xl font-semibold num text-foreground">
            {{ kpiMetrics.foreignOrMulti }}
          </b>
          <span class="text-xs text-muted">USD / Multi</span>
        </div>
        <p class="mt-1.5 text-[11px] text-muted truncate">
          T.C. NUMERIC(14,6) compra/venta
        </p>
      </div>

      <!-- KPI 3: Control de Entidad / RUC -->
      <div class="kpi flex flex-col justify-between">
        <div class="flex items-center justify-between">
          <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">
            Control de Anexos
          </span>
          <span
            class="w-7 h-7 rounded-md bg-soft text-brand inline-flex items-center justify-center"
          >
            <Building2 class="w-4 h-4" />
          </span>
        </div>
        <div class="mt-2 flex items-baseline gap-2">
          <b class="text-2xl font-semibold num text-foreground">
            {{ kpiMetrics.withEntity }}
          </b>
          <span class="text-xs text-muted">con entidad</span>
        </div>
        <p class="mt-1.5 text-[11px] text-muted truncate">
          Cliente, Proveedor, Banco, SUNAT
        </p>
      </div>

      <!-- KPI 4: Centro de Costos y Destino 94/95 vs 79 -->
      <div class="kpi flex flex-col justify-between">
        <div class="flex items-center justify-between">
          <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">
            Destino 94/95 ↔ 79
          </span>
          <span class="w-7 h-7 rounded-md bg-soft text-accent inline-flex items-center justify-center">
            <Workflow class="w-4 h-4" />
          </span>
        </div>
        <div class="mt-2 flex items-baseline gap-2">
          <b class="text-2xl font-semibold num text-foreground">
            {{ kpiMetrics.withDestination }}
          </b>
          <span class="text-xs text-muted">con CeCo</span>
        </div>
        <p class="mt-1.5 text-[11px] text-muted truncate">
          Amarre automático Elemento 6 → 9
        </p>
      </div>

      <!-- KPI 5: Estados Financieros y Flujo NIC 7 -->
      <div
        class="col-span-2 sm:col-span-1 kpi flex flex-col justify-between"
      >
        <div class="flex items-center justify-between">
          <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">
            EEFF & Flujo NIC 7
          </span>
          <span class="w-7 h-7 rounded-md bg-soft text-brand inline-flex items-center justify-center">
            <FileSpreadsheet class="w-4 h-4" />
          </span>
        </div>
        <div class="mt-2 flex items-baseline gap-2">
          <b class="text-2xl font-semibold num text-foreground">
            {{ kpiMetrics.withCashFlowOrRubric }}
          </b>
          <span class="text-xs text-muted">mapeadas</span>
        </div>
        <p class="mt-1.5 text-[11px] text-muted truncate">
          Inc. 1041 Efectivo y Equivalentes
        </p>
      </div>
    </div>

    <!-- 2. Barra de Herramientas, Buscador y Filtros por Elemento Contable -->
    <div class="panel workspace-section p-0 overflow-hidden">
      <!-- Fila Superior: Búsqueda, Filtros Rápidos y Botón Primario -->
      <div class="workspace-toolbar border-0 flex flex-col gap-3 rounded-none lg:flex-row lg:items-center lg:justify-between">
        <!-- Buscador Instantáneo -->
        <div class="relative min-w-0 flex-1 max-w-xl">
          <Search class="w-4 h-4 text-muted absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
          <input
            ref="searchInputRef"
            v-model="searchQuery"
            type="search"
            aria-label="Buscar cuenta contable por código, denominación o rubro"
            placeholder="Buscar cuenta por código (1041, 6361, 9411), denominación o rubro EEFF..."
            class="w-full min-h-10 rounded-md border border-line bg-surf pl-9 pr-16 py-2 text-sm text-foreground placeholder:text-muted/75 focus:outline-none focus:ring-2 focus:ring-brand/20"
          />
          <kbd
            class="hidden sm:inline-flex items-center gap-0.5 absolute right-2.5 top-1/2 -translate-y-1/2 px-1.5 py-0.5 text-[10px] font-mono font-semibold text-muted bg-soft border border-line rounded"
          >
            Ctrl K
          </kbd>
        </div>

        <!-- Controles de Vista y Acción Principal -->
        <div class="flex flex-wrap items-center gap-2">
          <!-- Chips de filtro rápido -->
          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs font-medium transition-colors cursor-pointer"
            :class="
              onlyOperational
                ? 'tag ok font-semibold'
                : 'border-line bg-surf text-muted hover:text-foreground'
            "
            @click="onlyOperational = !onlyOperational"
          >
            <Layers class="w-3.5 h-3.5" />
            Solo Operativas (N4)
          </button>

          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs font-medium transition-colors cursor-pointer"
            :class="
              onlyWithDestination
                ? 'tag wa font-semibold'
                : 'border-line bg-surf text-muted hover:text-foreground'
            "
            @click="onlyWithDestination = !onlyWithDestination"
          >
            <Workflow class="w-3.5 h-3.5" />
            Destino 94/95
          </button>

          <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md border px-2.5 py-1.5 text-xs font-medium transition-colors cursor-pointer"
            :class="
              onlyCashFlow
                ? 'tag ok font-semibold'
                : 'border-line bg-surf text-muted hover:text-foreground'
            "
            @click="onlyCashFlow = !onlyCashFlow"
          >
            <Banknote class="w-3.5 h-3.5" />
            Flujo Cta. 10
          </button>

          <div class="h-5 w-px bg-line hidden sm:block" />

          <!-- Expandir / Colapsar -->
          <div class="inline-flex items-center rounded-md border border-line bg-soft p-0.5">
            <button
              type="button"
              class="px-2 py-1 rounded-md text-xs font-medium text-muted hover:text-foreground hover:bg-surf transition-colors cursor-pointer"
              title="Expandir todos los niveles"
              @click="expandAll"
            >
              Expandir
            </button>
            <button
              type="button"
              class="px-2 py-1 rounded-md text-xs font-medium text-muted hover:text-foreground hover:bg-surf transition-colors cursor-pointer inline-flex items-center gap-1"
              title="Colapsar a Nivel 1 (Elementos)"
              @click="collapseAll"
            >
              <Minimize2 class="w-3 h-3" />
              N1
            </button>
          </div>

          <button
            type="button"
            class="inline-flex items-center justify-center w-8 h-8 rounded-md border border-line bg-surf text-muted hover:text-foreground transition-colors cursor-pointer"
            title="Sincronizar Plan de Cuentas"
            @click="refreshTree"
          >
            <RefreshCw class="w-3.5 h-3.5" :class="{ 'animate-spin': isLoading }" />
          </button>

          <button
            type="button"
            class="ui-btn ui-btn-primary"
            @click="openCreateRootModal"
          >
            <Plus class="w-4 h-4" />
            <span>Nueva Cuenta</span>
            <kbd class="ui-kbd bg-white/20 text-white border-white/30 hidden md:inline-flex">
              Alt+N
            </kbd>
          </button>
        </div>
      </div>

      <!-- Fila Inferior: Pestañas por Elemento del PCGE -->
      <div
        class="px-3.5 py-2 bg-soft border-t border-line flex items-center gap-1.5 overflow-x-auto"
        role="tablist"
        aria-label="Filtro por elemento del Plan Contable"
      >
        <Filter class="w-3.5 h-3.5 text-muted shrink-0 mr-1" />
        <button
          v-for="tab in elementTabs"
          :key="tab.id"
          type="button"
          role="tab"
          :aria-selected="activeElementFilter === tab.id"
          class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium whitespace-nowrap transition-colors cursor-pointer"
          :class="
            activeElementFilter === tab.id
              ? 'bg-brand text-white font-semibold shadow-2xs'
              : 'text-muted hover:text-foreground hover:bg-surf'
          "
          @click="activeElementFilter = tab.id"
        >
          <span class="font-mono text-[11px] opacity-85">{{ tab.code }}</span>
          <span class="hidden sm:inline">· {{ tab.label }}</span>
        </button>

        <label class="ml-auto inline-flex items-center gap-2 pl-3 text-xs text-muted whitespace-nowrap cursor-pointer select-none">
          <input v-model="includeInactive" type="checkbox" class="ui-switch" />
          <span>Inactivas</span>
        </label>
      </div>
    </div>

    <!-- Banner de Alerta si ocurre error de dominio -->
    <div
      v-if="errorMessage"
      class="flex items-center gap-2.5 rounded-xl border border-danger/40 bg-danger/10 px-4 py-3 text-xs font-medium text-danger"
      role="alert"
    >
      <AlertCircle class="w-4 h-4 shrink-0" />
      <span>{{ errorMessage }}</span>
    </div>

    <!-- 3. Estación de Trabajo Master-Detail (Árbol Jerárquico + Inspector Ficha Técnica) -->
    <div class="grid grid-cols-1 xl:grid-cols-[1fr_390px] gap-4 items-start">
      <!-- Panel Izquierdo: Grilla Jerárquica PCGE -->
      <div class="panel workspace-section p-0 overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-left border-collapse" role="treegrid" aria-label="Plan Contable General Empresarial">
            <thead>
              <tr
                class="border-b border-line bg-soft text-[11px] font-bold uppercase tracking-wider text-muted"
              >
                <th class="py-2.5 pl-3 pr-3 w-48">Código / Nivel</th>
                <th class="py-2.5 px-3">Denominación Contable</th>
                <th class="py-2.5 px-3 w-40 hidden md:table-cell">Naturaleza / Saldo</th>
                <th class="py-2.5 px-3">Configuración Operativa</th>
                <th class="py-2.5 px-3 w-44 hidden lg:table-cell">Rubro EEFF</th>
                <th class="py-2.5 pl-2 pr-3 text-right w-32">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <AccountTreeNodeRow
                v-for="row in filteredRows"
                :key="row.node.id"
                :row="row"
                :is-focused="selectedInspectorRow?.node.id === row.node.id"
                @toggle-expand="toggleExpand"
                @select-row="selectRowByNode"
                @inspect-row="inspectRowOnMobile"
                @create-child="openCreateChildModal"
                @edit-account="openEditModal"
                @deactivate-account="handleDeactivate"
              />
              <tr v-if="filteredRows.length === 0">
                <td colspan="6" class="py-12 text-center">
                  <div class="max-w-sm mx-auto space-y-2">
                    <Sparkles class="w-6 h-6 text-muted mx-auto" />
                    <p class="text-sm font-semibold text-foreground">
                      No se encontraron cuentas contables con los filtros activos
                    </p>
                    <p class="text-xs text-muted">
                      Prueba limpiando la búsqueda o seleccionando "Todos los Elementos".
                    </p>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Barra de estado inferior estilo Terminal Financiero -->
        <div
          class="px-4 py-2.5 bg-soft border-t border-line flex flex-wrap items-center justify-between gap-2 text-[11px] text-muted"
        >
          <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <span class="inline-flex items-center gap-1 font-medium text-foreground">
              <Keyboard class="w-3.5 h-3.5 text-brand" />
              Navegación Rápida:
            </span>
            <span><kbd class="px-1 py-0.5 rounded bg-surf border border-line font-mono text-[10px]">↑↓</kbd> Mover</span>
            <span><kbd class="px-1 py-0.5 rounded bg-surf border border-line font-mono text-[10px]">←→</kbd> Colapsar/Expandir</span>
            <span><kbd class="px-1 py-0.5 rounded bg-surf border border-line font-mono text-[10px]">Enter</kbd> Configurar</span>
            <span><kbd class="px-1 py-0.5 rounded bg-surf border border-line font-mono text-[10px]">Alt+N</kbd> Nueva Hija</span>
          </div>

          <div class="font-mono text-[11px] num">
            <strong>{{ filteredRows.length }}</strong> filas visibles · <strong>{{ kpiMetrics.total }}</strong> cuentas en el plan
          </div>
        </div>
      </div>

      <!-- Panel Derecho: Inspector Ficha Técnica de Cuenta Contable -->
      <AccountDetailInspector
        :selected-row="selectedInspectorRow"
        :is-mobile-open="isMobileInspectorOpen"
        @close-mobile="isMobileInspectorOpen = false"
        @create-child="openCreateChildModal"
        @edit-account="openEditModal"
        @deactivate-account="handleDeactivate"
      />
    </div>

    <!-- Modal de Creación / Configuración de Cuenta Contable -->
    <AccountFormModal
      :is-open="isModalOpen"
      :mode="modalMode"
      :parent-row="modalParentRow"
      :editing-row="modalEditingRow"
      :currency-options="currencyOptions"
      :rubric-options="rubricOptions"
      :cost-center-options="costCenterOptions"
      :cash-flow-area-options="cashFlowAreaOptions"
      :cost-centers="costCenters"
      :cash-flow-areas="cashFlowAreas"
      @close="isModalOpen = false"
      @submit="handleModalSubmit"
    />
  </section>
</template>
