<script setup lang="ts">
import { computed } from 'vue';
import {
  ArrowLeftRight,
  BookOpen,
  CalendarRange,
  Building2,
  CirclePlus,
  FileBarChart2,
  FolderTree,
  Users,
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
</script>

<template>
  <div>
    <!-- Backdrop para Drawer Móvil (< lg) -->
    <div
      v-if="isOpenMobile"
      class="fixed inset-0 z-40 bg-slate-950/65 backdrop-blur-xs lg:hidden"
      @click="emit('close-mobile')"
    />

    <!-- Barra Lateral Corporativa (Fija en Desktop, Drawer en Móvil/Tablet) -->
    <aside
      :class="[
        'app-sidebar flex flex-col justify-between overflow-y-auto overscroll-contain border-r border-line transition-[width] duration-200 z-50 shrink-0 font-sans',
        isOpenMobile
          ? 'fixed inset-y-0 left-0 w-72 shadow-2xl'
          : 'hidden lg:flex lg:sticky lg:top-0 lg:h-screen',
        !isOpenMobile && (isCollapsed ? 'lg:w-16' : 'lg:w-64'),
      ]"
      aria-label="Menú principal del ERP Contable"
    >
      <!-- Cabecera de Marca + Selector Multi-Tenant -->
      <div class="p-3.5 space-y-4">
        <div class="flex items-center justify-between gap-2">
          <div class="flex items-center gap-2.5 min-w-0">
            <div
              class="w-9 h-9 rounded-lg bg-accent flex items-center justify-center font-mono font-bold text-sm text-white shadow-xs shrink-0"
            >
              LM
            </div>
            <div v-if="!isCollapsed || isOpenMobile" class="min-w-0">
              <div class="flex items-center gap-1.5">
                <h1 class="text-sm font-semibold tracking-tight text-foreground truncate">
                  Libro Mayor Perú
                </h1>
                <span
                  class="px-1.5 py-0.2 rounded text-[9px] font-mono font-medium bg-soft text-foreground"
                >
                  v2026
                </span>
              </div>
              <p class="text-[11px] text-muted truncate">
                ERP Financiero Bimoneda · NIIF
              </p>
            </div>
          </div>

          <button
            type="button"
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
            <span class="text-[10px] font-medium text-pos">Seleccionada</span>
          </div>

          <UiSelect
            id="tenant-selector"
            v-model="activeCompanyIdString"
            :disabled="isSwitchingCompany"
            :options="companyOptions"
            variant="default"
            size="sm"
          />

          <div class="flex items-center justify-between text-[11px] text-muted font-mono pt-0.5">
            <span>ID: {{ activeCompany.id }}</span>
            <span>{{ activeCompany.fiscalRegime }}</span>
          </div>
        </div>

        <!-- Navegación Modular del ERP -->
        <nav class="app-sidebar-nav space-y-4 pt-1" aria-label="Módulos contables">
          <div class="space-y-1">
            <p
              v-if="!isCollapsed || isOpenMobile"
              class="px-2 text-[10px] font-semibold uppercase tracking-wider text-muted"
            >
              Contabilidad General
            </p>

            <!-- Directorio multiempresa de terceros / anexos -->
            <button
              type="button"
              :class="[
                'w-full flex items-center gap-2.5 rounded-md px-3 py-2 text-left text-xs transition-all',
                currentModule === 'entities'
                  ? 'bg-brand/10 text-foreground font-semibold shadow-[inset_3px_0_0_var(--brand)]'
                  : 'text-muted hover:bg-hover hover:text-foreground',
              ]"
              title="Directorio de clientes, proveedores, trabajadores y otros anexos"
              aria-label="Terceros y anexos"
              :aria-current="currentModule === 'entities' ? 'page' : undefined"
              @click="emit('navigate', 'entities')"
            >
              <Users class="h-4 w-4 shrink-0" />
              <span v-if="!isCollapsed || isOpenMobile" class="truncate">Terceros y Anexos</span>
            </button>

            <button type="button"
              :class="[
                'w-full flex items-center gap-2.5 rounded-md px-3 py-2 text-left text-xs transition-all',
                currentModule === 'fiscal-history'
                  ? 'bg-brand/10 text-foreground font-semibold shadow-[inset_3px_0_0_var(--brand)]'
                  : 'text-muted hover:bg-hover hover:text-foreground',
              ]"
              title="Historial de regímenes y parámetros fiscales por ejercicio"
              aria-label="Historial fiscal"
              :aria-current="currentModule === 'fiscal-history' ? 'page' : undefined"
              @click="emit('navigate', 'fiscal-history')"
            >
              <FileClock class="h-4 w-4 shrink-0" />
              <span v-if="!isCollapsed || isOpenMobile" class="truncate">Historial fiscal</span>
            </button>

            <button type="button"
              :class="['w-full flex items-center gap-2.5 rounded-md px-3 py-2 text-left text-xs transition-all',
                currentModule === 'subdiaries' ? 'bg-brand/10 text-foreground font-semibold' : 'text-muted hover:bg-hover hover:text-foreground']"
              title="Crear, editar y desactivar los subdiarios de la empresa"
              aria-label="Maestro de subdiarios"
              :aria-current="currentModule === 'subdiaries' ? 'page' : undefined"
              @click="emit('navigate', 'subdiaries')">
              <BookOpen class="h-4 w-4 shrink-0" />
              <span v-if="!isCollapsed || isOpenMobile" class="truncate">Maestro de subdiarios</span>
            </button>

            <!-- Opción 1: Plan de Cuentas (PCGE) -->
            <button type="button"
              :class="['w-full flex items-center gap-2.5 rounded-md px-3 py-2 text-left text-xs transition-all', currentModule === 'cost-centers' ? 'bg-brand/10 text-foreground font-semibold' : 'text-muted hover:bg-hover hover:text-foreground']"
              title="Centros de costo y destinos contables"
              aria-label="Centros de costo"
              :aria-current="currentModule === 'cost-centers' ? 'page' : undefined"
              @click="emit('navigate', 'cost-centers')"
            >
              <FolderTree class="h-4 w-4 shrink-0" />
              <span v-if="!isCollapsed || isOpenMobile" class="truncate">Centros de costo</span>
            </button>

            <button
              type="button"
              :class="[
                'w-full flex items-center gap-2.5 px-3 py-2 rounded-md text-xs transition-all cursor-pointer text-left',
                currentModule === 'catalog'
                  ? 'bg-brand/10 text-foreground opacity-100 shadow-[inset_3px_0_0_var(--brand)] font-semibold'
                  : 'text-muted hover:bg-hover hover:text-foreground hover:opacity-100 font-medium',
              ]"
              title="Plan Contable General Empresarial (PCGE)"
              aria-label="Plan de cuentas PCGE"
              :aria-current="currentModule === 'catalog' ? 'page' : undefined"
              @click="emit('navigate', 'catalog')"
            >
              <FolderTree class="w-4 h-4 shrink-0" />
              <span v-if="!isCollapsed || isOpenMobile" class="truncate">
                Plan de Cuentas (PCGE)
              </span>
            </button>

            <!-- Opción 2: Libro Diario Bimoneda (Ingreso de Asientos / Voucher) -->
            <button
              type="button"
              :class="[
                'w-full flex items-center justify-between px-3 py-2 rounded-md text-xs transition-all cursor-pointer text-left',
                currentModule === 'journal'
                  ? 'bg-brand/10 text-foreground opacity-100 shadow-[inset_3px_0_0_var(--brand)] font-semibold'
                  : 'text-muted hover:bg-hover hover:text-foreground hover:opacity-100 font-medium',
              ]"
              title="Voucher Contable y Libro Diario Bimoneda (Ingreso de Asientos)"
              aria-label="Libro diario bimoneda"
              :aria-current="currentModule === 'journal' ? 'page' : undefined"
              @click="emit('navigate', 'journal')"
            >
              <span class="flex items-center gap-2.5 truncate">
                <BookOpen class="w-4 h-4 shrink-0" />
                <span v-if="!isCollapsed || isOpenMobile" class="truncate">
                  Libro Diario Bimoneda
                </span>
              </span>
              <span
                v-if="!isCollapsed || isOpenMobile"
                class="tag tag-ok font-mono text-[10px] px-1.5 py-0 bg-soft text-foreground border-line"
              >
                Vouchers
              </span>
            </button>

            <!-- Gestión de períodos contables (F2.3) -->
            <button
              type="button"
              :class="[
                'w-full flex items-center gap-2.5 px-3 py-2 rounded-md text-xs transition-all cursor-pointer text-left',
                currentModule === 'fiscal-periods'
                  ? 'bg-brand/10 text-foreground opacity-100 shadow-[inset_3px_0_0_var(--brand)] font-semibold'
                  : 'text-muted hover:bg-hover hover:text-foreground font-medium',
              ]"
              title="Cierre, reapertura y bloqueo de períodos contables"
              aria-label="Períodos contables"
              :aria-current="currentModule === 'fiscal-periods' ? 'page' : undefined"
              @click="emit('navigate', 'fiscal-periods')"
            >
              <CalendarRange class="w-4 h-4 shrink-0" />
              <span v-if="!isCollapsed || isOpenMobile" class="truncate">Períodos contables</span>
            </button>

            <!-- Opción: Tipos de Cambio Diarios SUNAT / SBS -->
            <button
              type="button"
              :class="[
                'w-full flex items-center justify-between px-3 py-2 rounded-md text-xs transition-all cursor-pointer text-left',
                currentModule === 'exchange-rates'
                  ? 'bg-brand/10 text-foreground opacity-100 shadow-[inset_3px_0_0_var(--brand)] font-semibold'
                  : 'text-muted hover:bg-hover hover:text-foreground hover:opacity-100 font-medium',
              ]"
              title="Registro y Consulta de Tipos de Cambio Diarios Oficiales (SUNAT & SBS)"
              aria-label="Tipos de cambio diarios"
              :aria-current="currentModule === 'exchange-rates' ? 'page' : undefined"
              @click="emit('navigate', 'exchange-rates')"
            >
              <span class="flex items-center gap-2.5 truncate">
                <ArrowLeftRight class="w-4 h-4 shrink-0" />
                <span v-if="!isCollapsed || isOpenMobile" class="truncate">
                  Tipos de Cambio Diarios
                </span>
              </span>
              <span
                v-if="!isCollapsed || isOpenMobile"
                class="tag font-mono text-[10px] px-1.5 py-0 bg-soft text-foreground border-line"
              >
                SUNAT
              </span>
            </button>


          </div>

          <div class="space-y-1">
            <p
              v-if="!isCollapsed || isOpenMobile"
              class="px-2 text-[10px] font-semibold uppercase tracking-wider text-muted"
            >
              Tesorería & Estados Financieros
            </p>

            <div
              class="flex items-center justify-between px-3 py-2 rounded-md text-muted hover:bg-hover hover:text-foreground text-xs transition-colors cursor-default"
              title="Áreas de Flujo de Efectivo Cuenta 10 (NIC 7)"
            >
              <span class="flex items-center gap-2.5 truncate">
                <Wallet class="w-4 h-4 shrink-0 text-muted" />
                <span v-if="!isCollapsed || isOpenMobile" class="truncate">
                  Flujo de Efectivo (Cta. 10)
                </span>
              </span>
              <span
                v-if="!isCollapsed || isOpenMobile"
                class="tag font-mono text-[10px] px-1.5 py-0 border-line text-muted"
              >
                NIC 7
              </span>
            </div>

            <button
              type="button"
              :class="['flex w-full items-center justify-between rounded-md px-3 py-2 text-left text-xs transition-colors',
                currentModule === 'financial-statements' ? 'bg-brand/10 font-semibold text-foreground shadow-[inset_3px_0_0_var(--brand)]' : 'text-muted hover:bg-hover hover:text-foreground']"
              title="Estado de situación financiera preliminar y balance de comprobación"
              aria-label="Estados financieros"
              :aria-current="currentModule === 'financial-statements' ? 'page' : undefined"
              @click="emit('navigate', 'financial-statements')"
            >
              <span class="flex items-center gap-2.5 truncate">
                <FileBarChart2 class="w-4 h-4 shrink-0 text-muted" />
                <span v-if="!isCollapsed || isOpenMobile" class="truncate">
                  Estados financieros
                </span>
              </span>
              <span
                v-if="!isCollapsed || isOpenMobile"
                class="tag font-mono text-[10px] px-1.5 py-0 border-line text-muted"
              >
                Ver
              </span>
            </button>
          </div>

          <!-- Referencias normativas globales, sin edición por empresa -->
          <div class="space-y-1">
            <p v-if="!isCollapsed || isOpenMobile" class="px-2 text-[10px] font-semibold uppercase tracking-wider text-muted">Referencias globales</p>
            <button type="button"
              :class="['w-full flex items-center gap-2.5 rounded-md px-3 py-2 text-left text-xs transition-all',
                currentModule === 'see-references' ? 'bg-brand/10 text-foreground font-semibold shadow-[inset_3px_0_0_var(--brand)]' : 'text-muted hover:bg-hover hover:text-foreground']"
              title="Catálogos SEE de facturación electrónica y ubigeo INEI, solo lectura"
              aria-label="Catálogos SEE y Ubigeo"
              :aria-current="currentModule === 'see-references' ? 'page' : undefined"
              @click="emit('navigate', 'see-references')">
              <Globe2 class="h-4 w-4 shrink-0" />
              <span v-if="!isCollapsed || isOpenMobile" class="truncate">Catálogos SEE y Ubigeo</span>
            </button>
            <button type="button"
              :class="['w-full flex items-center gap-2.5 rounded-md px-3 py-2 text-left text-xs transition-all',
                currentModule === 'sunat-references' ? 'bg-brand/10 text-foreground font-semibold shadow-[inset_3px_0_0_var(--brand)]' : 'text-muted hover:bg-hover hover:text-foreground']"
              title="Tablas SUNAT PLE/SIRE y anexos RUC, solo lectura"
              aria-label="Tablas SUNAT PLE/SIRE y RUC"
              :aria-current="currentModule === 'sunat-references' ? 'page' : undefined"
              @click="emit('navigate', 'sunat-references')">
              <BookOpen class="h-4 w-4 shrink-0" />
              <span v-if="!isCollapsed || isOpenMobile" class="truncate">Tablas SUNAT PLE/SIRE y RUC</span>
            </button>
          </div>

          <!-- Módulo: Configuración & Régimen de Empresa -->
          <div class="space-y-1">
            <p
              v-if="!isCollapsed || isOpenMobile"
              class="px-2 text-[10px] font-semibold uppercase tracking-wider text-muted"
            >
              Configuración & Empresa
            </p>

            <button
              type="button"
              :class="[
                'w-full flex items-center gap-2.5 rounded-md px-3 py-2 text-left text-xs transition-all',
                currentModule === 'company-create'
                  ? 'bg-brand/10 text-foreground font-semibold shadow-[inset_3px_0_0_var(--brand)]'
                  : 'text-muted hover:bg-hover hover:text-foreground'
              ]"
              title="Asistente para crear una nueva empresa"
              aria-label="Nueva empresa"
              :aria-current="currentModule === 'company-create' ? 'page' : undefined"
              @click="emit('navigate', 'company-create')"
            >
              <CirclePlus class="h-4 w-4 shrink-0" />
              <span v-if="!isCollapsed || isOpenMobile" class="truncate">Nueva empresa</span>
            </button>

            <button
              type="button"
              :class="[
                'w-full flex items-center justify-between px-3 py-2 rounded-md text-xs transition-all cursor-pointer text-left',
                currentModule === 'company'
                  ? 'bg-brand/10 text-foreground opacity-100 shadow-[inset_3px_0_0_var(--brand)] font-semibold'
                  : 'text-muted hover:bg-hover hover:text-foreground hover:opacity-100 font-medium',
              ]"
              title="Configuración de la Empresa, Monedas de Trabajo y Tipos de Cambio"
              aria-label="Datos de empresa"
              :aria-current="currentModule === 'company' ? 'page' : undefined"
              @click="emit('navigate', 'company')"
            >
              <span class="flex items-center gap-2.5 truncate">
                <Building2 class="w-4 h-4 shrink-0" />
                <span v-if="!isCollapsed || isOpenMobile" class="truncate">
                  Datos de Empresa
                </span>
              </span>
              <span
                v-if="!isCollapsed || isOpenMobile"
                class="tag font-mono text-[10px] px-1.5 py-0 bg-soft text-foreground border-line"
              >
                Monedas
              </span>
            </button>
          </div>
        </nav>
      </div>

      <!-- Pie de Barra Lateral: Motor Decimal C# + Modo Oscuro + Logout -->
      <div class="p-3.5 border-t border-line space-y-2.5">
        <div
          v-if="!isCollapsed || isOpenMobile"
          class="rounded-lg bg-surface border border-line p-2.5 text-[11px] space-y-1"
        >
          <div class="flex items-center justify-between text-muted">
            <span>Cálculo contable</span>
            <span class="font-mono text-pos font-semibold">Alta precisión</span>
          </div>
          <div class="font-mono text-[10px] text-muted">
            Importes con precisión decimal
          </div>
        </div>

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
      </div>
    </aside>
  </div>
</template>

