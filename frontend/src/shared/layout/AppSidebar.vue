<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import {
  ArrowLeftRight,
  BookOpen,
  ShoppingCart,
  Receipt,
  Search,
  Landmark,
  Package,
  CalendarRange,
  Building2,
  CirclePlus,
  FileBarChart2,
  FolderTree,
  Users,
  LockKeyhole,
  FileClock,
  Globe2,
  LogOut,
  Moon,
  Sun,
  Wallet,
  X,
} from 'lucide-vue-next';
import UiSelect from '@/shared/ui/UiSelect.vue';
import type { UiSelectOption } from '@/shared/ui/types';
import type { CompanyId } from '@/modules/accounting/types/accountingAccount.types';
import type { UserCompany } from '@/shared/auth/useAuthSession';
import type { ActiveCompanyInfo, NavigationModule } from './navigation.types';

const props = defineProps<{
  readonly currentModule: NavigationModule;
  readonly isCollapsed: boolean;
  readonly isOpenMobile: boolean;
  readonly activeCompanyId: CompanyId;
  readonly userCompanies: readonly UserCompany[];
  readonly activeCompany: ActiveCompanyInfo;
  readonly isDarkMode: boolean;
  readonly canManagePayroll: boolean;
  readonly isSwitchingCompany: boolean;
}>();

const emit = defineEmits<{
  (e: 'navigate', module: NavigationModule): void;
  (e: 'switch-company', companyId: CompanyId): void;
  (e: 'close-mobile'): void;
  (e: 'toggle-dark'): void;
  (e: 'logout'): void;
}>();

const companyOptions = computed<readonly UiSelectOption<string>[]>(() => {
  if (props.userCompanies.length === 0) {
    return [
      {
        value: String(props.activeCompanyId),
        label: props.activeCompany.businessName,
        hint: `RUC ${props.activeCompany.taxId} · ${props.activeCompany.fiscalRegime}`,
      },
    ];
  }

  return props.userCompanies.map((c) => ({
    value: String(c.id),
    label: c.business_name,
    hint: `RUC ${c.tax_id} · ${c.fiscal_regime ?? 'Régimen General'}`,
  }));
});

const activeCompanyIdString = computed<string>({
  get: () => String(props.activeCompanyId),
  set: (val: string) => {
    emit('switch-company', Number(val) as CompanyId);
  },
});
const expanded = computed(() => !props.isCollapsed || props.isOpenMobile);
const query = ref('');
const closeButton = ref<HTMLButtonElement>();
const normalize = (value: string) => value.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
const groups = [
  { label: 'Operaciones', items: [
    { module: 'journal', label: 'Libro Diario', icon: BookOpen },
    { module: 'purchases', label: 'Registro de compras', icon: ShoppingCart },
    { module: 'sales', label: 'Registro de ventas', icon: Receipt },
    { module: 'payroll', label: 'Gestión de planillas', icon: Users },
    { module: 'exchange-rates', label: 'Tipo de cambio', icon: ArrowLeftRight },
  ] },
  { label: 'Cierre y reportes', items: [
    { module: 'monthly-work', label: 'Agenda contable mensual', icon: CalendarRange },
    { module: 'external-tax-reports', label: 'DAOT y PDB Exportadores', icon: CalendarRange },
    { module: 'sunat-calendar', label: 'Vencimientos SUNAT', icon: CalendarRange },
    { module: 'annual-tax-close', label: 'Cierre tributario anual', icon: Receipt },
    { module: 'financial-statements', label: 'Estados financieros', icon: FileBarChart2 },
    { module: 'cash-flow', label: 'Flujo de efectivo', icon: Wallet },
    { module: 'fiscal-periods', label: 'Periodos contables', icon: CalendarRange },
  ] },
  { label: 'Auxiliares', items: [
    { module: 'loans', label: 'Préstamos recibidos', icon: Landmark },
    { module: 'fixed-assets', label: 'Activos fijos', icon: Package },
  ] },
  { label: 'Maestros', items: [
    { module: 'catalog', label: 'Plan contable', icon: FolderTree },
    { module: 'entities', label: 'Terceros y anexos', icon: Users },
    { module: 'subdiaries', label: 'Maestro de subdiarios', icon: BookOpen },
    { module: 'cost-centers', label: 'Centros de costo', icon: FolderTree },
  ] },
  { label: 'Empresa', items: [
    { module: 'company', label: 'Datos de empresa', icon: Building2 },
    { module: 'fiscal-history', label: 'Historial fiscal', icon: FileClock },
    { module: 'company-create', label: 'Nueva empresa', icon: CirclePlus },
  ] },
  { label: 'Referencias', items: [
    { module: 'see-references', label: 'Catálogos SEE', icon: Globe2 },
    { module: 'sunat-references', label: 'Catálogos SUNAT y PLE', icon: FolderTree },
  ] },
] as const;
const visibleGroups = computed(() => {
  const term = expanded.value ? normalize(query.value.trim()) : '';
  return groups.map((group) => ({
    ...group,
    items: group.items.filter((item) => normalize(group.label + ' ' + item.label).includes(term)),
  })).filter((group) => group.items.length);
});

let previousFocus: HTMLElement | null = null;
watch(() => props.isOpenMobile, async (open) => {
  if (open) {
    previousFocus = document.activeElement as HTMLElement | null;
    await nextTick();
    closeButton.value?.focus();
  } else {
    query.value = '';
    await nextTick();
    previousFocus?.focus();
    previousFocus = null;
  }
});

function navigate(module: NavigationModule) {
  query.value = '';
  emit('navigate', module);
}
</script>

<template>
  <div class="lg:sticky lg:top-0 lg:h-dvh lg:shrink-0 lg:self-start lg:z-50">
    <!-- Backdrop para Drawer Móvil (< lg) -->
    <div
      v-if="isOpenMobile"
      class="fixed inset-0 z-40 bg-black/55 backdrop-blur-xs lg:hidden"
      @click="emit('close-mobile')"
    />

    <!-- Barra Lateral Corporativa (Fija en Desktop, Drawer en Móvil/Tablet) -->
    <aside
      id="workspace-sidebar"
      :role="isOpenMobile ? 'dialog' : undefined"
      :aria-modal="isOpenMobile ? true : undefined"
      :class="[
        'app-sidebar grid grid-rows-[auto_minmax(0,1fr)_auto] overflow-hidden border-r border-line transition-[width] duration-200 z-50 shrink-0 font-sans',
        isOpenMobile
          ? 'fixed inset-y-0 left-0 w-[min(19rem,90vw)] shadow-2xl'
          : 'hidden lg:grid lg:h-full',
        !isOpenMobile && (isCollapsed ? 'lg:w-16' : 'lg:w-[17.5rem]'),
      ]"
      aria-label="Menú principal del ERP Contable"
      @keydown.esc="isOpenMobile && emit('close-mobile')"
    >
      <!-- Cabecera de Marca + Selector Multi-Tenant -->
      <header class="app-sidebar-header space-y-3 border-b border-line p-3">
        <div class="flex items-center justify-between gap-2">
          <div class="flex items-center gap-2.5 min-w-0">
            <div
              class="w-9 h-9 rounded-lg bg-accent flex items-center justify-center font-mono font-bold text-sm text-white shadow-xs shrink-0"
            >
              LM
            </div>
            <div v-if="!isCollapsed || isOpenMobile" class="min-w-0">
              <div class="flex items-center gap-1.5">
                <h1 class="text-sm font-semibold tracking-tight text-foreground">
                  Libro Mayor Perú
                </h1>
              </div>
              <p class="mt-0.5 text-xs leading-4 text-muted">
                ERP financiero bimoneda
              </p>
            </div>
          </div>

          <button
            type="button"
            ref="closeButton"
            class="lg:hidden rounded-md p-1.5 text-muted hover:bg-hover hover:text-foreground cursor-pointer"
            aria-label="Cerrar menú lateral"
            @click="emit('close-mobile')"
          >
            <X class="w-4 h-4" />
          </button>
        </div>

        <!-- Selector Multi-Tenant con Aislamiento RLS -->
        <div
          v-if="!isCollapsed || isOpenMobile"
          class="rounded-lg bg-soft border border-line p-3 space-y-2"
        >
          <div class="flex items-center justify-between text-[10px] font-semibold uppercase tracking-wider text-muted">
            <span class="inline-flex items-center gap-1">
              <Building2 class="w-3.5 h-3.5" />
              Empresa activa
            </span>
          </div>

          <UiSelect
            id="tenant-selector"
            v-model="activeCompanyIdString"
            :disabled="isSwitchingCompany"
            :options="companyOptions"
            variant="default"
            size="sm"
          />

          <div class="space-y-0.5 pt-1 text-[11px] leading-4 text-muted">
            <p class="font-mono" :title="`RUC: ${activeCompany.taxId}`">RUC: {{ activeCompany.taxId }}</p>
            <p class="break-words" :title="activeCompany.fiscalRegime">{{ activeCompany.fiscalRegime }}</p>
          </div>
        </div>

        <label v-if="expanded" class="ui-input-group">
          <Search class="size-4" aria-hidden="true" />
          <span class="sr-only">Buscar módulo</span>
          <input v-model="query" type="search" class="min-h-9 w-full pl-9 text-sm" placeholder="Buscar módulo">
        </label>
      </header>

      <nav class="app-sidebar-nav min-h-0 space-y-4 overflow-x-hidden overflow-y-auto overscroll-y-contain px-2 py-3"
        aria-label="Módulos contables">
        <p v-if="!visibleGroups.length" role="status" class="p-2 text-muted">Sin módulos que coincidan.</p>
        <section v-for="group in visibleGroups" :key="group.label" class="space-y-0.5">
          <p v-if="expanded" class="px-2 pb-1 text-xs font-semibold text-muted">{{ group.label }}</p>
          <button v-for="item in group.items" :key="item.module" type="button"
            class="flex w-full items-center gap-2.5 rounded-md px-2.5 py-2 text-left"
            :class="expanded ? 'justify-start' : 'justify-center'"
            :aria-label="item.label"
            :title="item.module === 'payroll' && !canManagePayroll ? 'Gestión de planillas · Acceso restringido' : item.label"
            :aria-current="currentModule === item.module ? 'page' : undefined"
            @click="navigate(item.module)">
            <component :is="item.icon" class="size-4 shrink-0" aria-hidden="true" />
            <span v-if="expanded" class="truncate">{{ item.label }}</span>
            <LockKeyhole v-if="expanded && item.module === 'payroll' && !canManagePayroll"
              class="ml-auto size-3.5 shrink-0 text-muted" aria-label="Acceso restringido" />
          </button>
        </section>
      </nav>

      <footer class="app-sidebar-footer space-y-1 border-t border-line p-2.5">
        <button
          type="button"
          class="w-full inline-flex items-center justify-center gap-2 rounded-md border border-line bg-surface px-3 py-2 text-xs font-medium text-foreground hover:bg-hover transition-colors cursor-pointer"
          :aria-label="isDarkMode ? 'Cambiar a modo claro' : 'Activar modo oscuro'"
          :title="isDarkMode ? 'Cambiar a modo claro' : 'Activar modo oscuro'"
          @click="emit('toggle-dark')"
        >
          <Sun v-if="isDarkMode" class="w-4 h-4 text-brand shrink-0" />
          <Moon v-else class="w-4 h-4 text-brand shrink-0" />
          <span v-if="!isCollapsed || isOpenMobile">
            {{ isDarkMode ? 'Modo Claro' : 'Modo Oscuro' }}
          </span>
        </button>

        <button
          type="button"
          class="w-full inline-flex items-center justify-center gap-2 rounded-md border border-danger/30 bg-danger/10 px-3 py-2 text-xs font-medium text-danger hover:bg-danger/15 hover:text-foreground transition-colors cursor-pointer mt-1"
          title="Cerrar sesión"
          aria-label="Cerrar sesión"
          @click="emit('logout')"
        >
          <LogOut class="w-4 h-4 shrink-0 text-danger" />
          <span v-if="!isCollapsed || isOpenMobile">
            Cerrar Sesión
          </span>
        </button>
      </footer>
    </aside>
  </div>
</template>

