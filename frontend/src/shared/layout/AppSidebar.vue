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
  LogOut,
  Moon,
  ShieldCheck,
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
        'bg-brand text-brandtx flex flex-col justify-between border-r border-white/15 transition-all duration-200 z-50 shrink-0 font-sans',
        isOpenMobile
          ? 'fixed inset-y-0 left-0 w-72 shadow-2xl'
          : 'hidden lg:flex lg:sticky lg:top-0 lg:h-screen',
        !isOpenMobile && (isCollapsed ? 'lg:w-[74px]' : 'lg:w-[272px]'),
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
                <h1 class="text-sm font-semibold tracking-tight text-white truncate">
                  Libro Mayor Perú
                </h1>
                <span
                  class="px-1.5 py-0.2 rounded text-[9px] font-mono font-medium bg-white/20 text-white"
                >
                  v2026
                </span>
              </div>
              <p class="text-[11px] text-white/75 truncate">
                ERP Financiero Bimoneda · NIIF
              </p>
            </div>
          </div>

          <button
            type="button"
            class="lg:hidden rounded-md p-1.5 text-white/80 hover:bg-white/15 hover:text-white cursor-pointer"
            aria-label="Cerrar menú lateral"
            @click="emit('close-mobile')"
          >
            <X class="w-4 h-4" />
          </button>
        </div>

        <!-- Selector Multi-Tenant con Aislamiento RLS -->
        <div
          v-if="!isCollapsed || isOpenMobile"
          class="rounded-lg bg-white/10 border border-white/15 p-3 space-y-2"
        >
          <div class="flex items-center justify-between text-[10px] font-semibold uppercase tracking-wider text-white/80">
            <span class="inline-flex items-center gap-1">
              <Building2 class="w-3.5 h-3.5" />
              Entidad Multi-Tenant
            </span>
            <span class="inline-flex items-center gap-1 text-emerald-300 font-mono text-[10px]">
              <ShieldCheck class="w-3 h-3" />
              RLS FORCE
            </span>
          </div>

          <UiSelect
            id="tenant-selector"
            v-model="activeCompanyIdString"
            :options="companyOptions"
            variant="dark"
            size="sm"
          />

          <div class="flex items-center justify-between text-[11px] text-white/70 font-mono pt-0.5">
            <span>ID: {{ activeCompany.id }}</span>
            <span>{{ activeCompany.fiscalRegime }}</span>
          </div>
        </div>

        <!-- Navegación Modular del ERP -->
        <nav class="space-y-4 pt-1" aria-label="Módulos Contables">
          <div class="space-y-1">
            <p
              v-if="!isCollapsed || isOpenMobile"
              class="px-2 text-[10px] font-semibold uppercase tracking-wider text-white/60"
            >
              Contabilidad General
            </p>

            <!-- Directorio multiempresa de terceros / anexos -->
            <button
              type="button"
              :class="[
                'w-full flex items-center gap-2.5 rounded-md px-3 py-2 text-left text-xs transition-all',
                currentModule === 'entities'
                  ? 'bg-white/15 text-white font-semibold shadow-[inset_3px_0_0_var(--accent)]'
                  : 'text-white/80 hover:bg-white/10 hover:text-white',
              ]"
              title="Directorio de clientes, proveedores, trabajadores y otros anexos"
              @click="emit('navigate', 'entities')"
            >
              <Users class="h-4 w-4 shrink-0" />
              <span v-if="!isCollapsed || isOpenMobile" class="truncate">Terceros y Anexos</span>
            </button>

            <button type="button"
              :class="[
                'w-full flex items-center gap-2.5 rounded-md px-3 py-2 text-left text-xs transition-all',
                currentModule === 'fiscal-history'
                  ? 'bg-white/15 text-white font-semibold shadow-[inset_3px_0_0_var(--accent)]'
                  : 'text-white/80 hover:bg-white/10 hover:text-white',
              ]"
              title="Historial de regímenes y parámetros fiscales por ejercicio"
              @click="emit('navigate', 'fiscal-history')"
            >
              <FileClock class="h-4 w-4 shrink-0" />
              <span v-if="!isCollapsed || isOpenMobile" class="truncate">Historial fiscal</span>
            </button>

            <!-- Opción 1: Plan de Cuentas (PCGE) -->
            <button type="button"
              :class="['w-full flex items-center gap-2.5 rounded-md px-3 py-2 text-left text-xs transition-all', currentModule === 'cost-centers' ? 'bg-white/15 text-white font-semibold' : 'text-white/80 hover:bg-white/10 hover:text-white']"
              title="Centros de costo y destinos contables"
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
                  ? 'bg-white/15 text-white opacity-100 shadow-[inset_3px_0_0_var(--accent)] font-semibold'
                  : 'text-white/80 hover:bg-white/10 hover:text-white hover:opacity-100 font-medium',
              ]"
              title="Plan Contable General Empresarial (PCGE)"
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
                  ? 'bg-white/15 text-white opacity-100 shadow-[inset_3px_0_0_var(--accent)] font-semibold'
                  : 'text-white/80 hover:bg-white/10 hover:text-white hover:opacity-100 font-medium',
              ]"
              title="Voucher Contable y Libro Diario Bimoneda (Ingreso de Asientos)"
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
                class="tag tag-ok font-mono text-[10px] px-1.5 py-0 bg-white/10 text-white border-white/30"
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
                  ? 'bg-white/15 text-white opacity-100 shadow-[inset_3px_0_0_var(--accent)] font-semibold'
                  : 'text-white/80 hover:bg-white/10 hover:text-white font-medium',
              ]"
              title="Cierre, reapertura y bloqueo de períodos contables"
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
                  ? 'bg-white/15 text-white opacity-100 shadow-[inset_3px_0_0_var(--accent)] font-semibold'
                  : 'text-white/80 hover:bg-white/10 hover:text-white hover:opacity-100 font-medium',
              ]"
              title="Registro y Consulta de Tipos de Cambio Diarios Oficiales (SUNAT & SBS)"
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
                class="tag font-mono text-[10px] px-1.5 py-0 bg-white/10 text-white border-white/30"
              >
                SUNAT
              </span>
            </button>


          </div>

          <div class="space-y-1">
            <p
              v-if="!isCollapsed || isOpenMobile"
              class="px-2 text-[10px] font-semibold uppercase tracking-wider text-white/60"
            >
              Tesorería & Estados Financieros
            </p>

            <div
              class="flex items-center justify-between px-3 py-2 rounded-md text-white/70 hover:bg-white/5 hover:text-white text-xs transition-colors cursor-default"
              title="Áreas de Flujo de Efectivo Cuenta 10 (NIC 7)"
            >
              <span class="flex items-center gap-2.5 truncate">
                <Wallet class="w-4 h-4 shrink-0 text-white/50" />
                <span v-if="!isCollapsed || isOpenMobile" class="truncate">
                  Flujo de Efectivo (Cta. 10)
                </span>
              </span>
              <span
                v-if="!isCollapsed || isOpenMobile"
                class="tag font-mono text-[10px] px-1.5 py-0 border-white/30 text-white/80"
              >
                NIC 7
              </span>
            </div>

            <div
              class="flex items-center justify-between px-3 py-2 rounded-md text-white/70 hover:bg-white/5 hover:text-white text-xs transition-colors cursor-default"
              title="Estado de Situación Financiera y Estado de Resultados"
            >
              <span class="flex items-center gap-2.5 truncate">
                <FileBarChart2 class="w-4 h-4 shrink-0 text-white/50" />
                <span v-if="!isCollapsed || isOpenMobile" class="truncate">
                  Estados Financieros EEFF
                </span>
              </span>
              <span
                v-if="!isCollapsed || isOpenMobile"
                class="tag font-mono text-[10px] px-1.5 py-0 border-white/30 text-white/80"
              >
                NIIF
              </span>
            </div>
          </div>

          <!-- Módulo: Configuración & Régimen de Empresa -->
          <div class="space-y-1">
            <p
              v-if="!isCollapsed || isOpenMobile"
              class="px-2 text-[10px] font-semibold uppercase tracking-wider text-white/60"
            >
              Configuración & Empresa
            </p>

            <button
              type="button"
              :class="[
                'w-full flex items-center gap-2.5 rounded-md px-3 py-2 text-left text-xs transition-all',
                currentModule === 'company-create'
                  ? 'bg-white/15 text-white font-semibold shadow-[inset_3px_0_0_var(--accent)]'
                  : 'text-white/80 hover:bg-white/10 hover:text-white'
              ]"
              title="Asistente para crear una nueva empresa"
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
                  ? 'bg-white/15 text-white opacity-100 shadow-[inset_3px_0_0_var(--accent)] font-semibold'
                  : 'text-white/80 hover:bg-white/10 hover:text-white hover:opacity-100 font-medium',
              ]"
              title="Configuración de la Empresa, Monedas de Trabajo y Tipos de Cambio"
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
                class="tag font-mono text-[10px] px-1.5 py-0 bg-white/10 text-white border-white/30"
              >
                Monedas
              </span>
            </button>
          </div>
        </nav>
      </div>

      <!-- Pie de Barra Lateral: Motor Decimal C# + Modo Oscuro + Logout -->
      <div class="p-3.5 border-t border-white/15 space-y-2.5">
        <div
          v-if="!isCollapsed || isOpenMobile"
          class="rounded-lg bg-black/20 border border-white/10 p-2.5 text-[11px] space-y-1"
        >
          <div class="flex items-center justify-between text-white/70">
            <span>Motor Numérico C#</span>
            <span class="font-mono text-emerald-300 font-semibold">Strict Exact</span>
          </div>
          <div class="font-mono text-[10px] text-white/60">
            Int16 · Int64 · Decimal(14,4)
          </div>
        </div>

        <button
          type="button"
          class="w-full inline-flex items-center justify-center gap-2 rounded-md border border-accent bg-white/5 px-3 py-2 text-xs font-medium text-white hover:bg-white/10 transition-colors cursor-pointer"
          :title="isDarkMode ? 'Cambiar a modo claro' : 'Activar modo oscuro'"
          @click="emit('toggle-dark')"
        >
          <Sun v-if="isDarkMode" class="w-4 h-4 text-accent shrink-0" />
          <Moon v-else class="w-4 h-4 text-accent shrink-0" />
          <span v-if="!isCollapsed || isOpenMobile">
            {{ isDarkMode ? 'Modo Claro' : 'Modo Oscuro' }}
          </span>
        </button>

        <button
          type="button"
          class="w-full inline-flex items-center justify-center gap-2 rounded-md border border-red-400/30 bg-red-500/10 px-3 py-2 text-xs font-medium text-red-200 hover:bg-red-500/20 hover:text-white transition-colors cursor-pointer mt-1"
          title="Cerrar sesión de forma segura y revocar credenciales (ISO 27001)"
          @click="emit('logout')"
        >
          <LogOut class="w-4 h-4 shrink-0 text-red-300" />
          <span v-if="!isCollapsed || isOpenMobile">
            Cerrar Sesión
          </span>
        </button>
      </div>
    </aside>
  </div>
</template>

