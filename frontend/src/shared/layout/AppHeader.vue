<script setup lang="ts">
import { computed } from 'vue';
import {
  ArrowLeftRight,
  ChevronRight,
  LogOut,
  Menu,
  PanelLeftClose,
  PanelLeftOpen,
  Wifi,
  WifiOff,
} from 'lucide-vue-next';
import UiSelect from '@/shared/ui/UiSelect.vue';
import type { UiSelectOption } from '@/shared/ui/types';
import type { CompanyId } from '@/modules/accounting/types/accountingAccount.types';
import type { UserCompany } from '@/shared/auth/useAuthSession';
import type { ActiveCompanyInfo, NavigationModule } from './navigation.types';

interface AuthenticatedUser {
  readonly id: number;
  readonly name: string;
  readonly email: string;
}

const props = defineProps<{
  readonly currentModule: NavigationModule;
  readonly activeCompany: ActiveCompanyInfo;
  readonly activeCompanyId: CompanyId;
  readonly userCompanies: readonly UserCompany[];
  readonly currentUser: AuthenticatedUser | null;
  readonly isLiveConnected: boolean;
  readonly isSwitchingCompany: boolean;
  readonly isSidebarCollapsed: boolean;
  readonly headerUsdRate: { readonly buy_rate: string; readonly sell_rate: string } | null;
  readonly headerRateDate: string;
  readonly headerRateStatus: 'loading' | 'unavailable' | 'unrecorded' | 'not-applicable' | 'recorded';
}>();

const emit = defineEmits<{
  (e: 'toggle-collapse'): void;
  (e: 'open-mobile'): void;
  (e: 'switch-company', companyId: CompanyId): void;
  (e: 'logout'): void;
}>();

const companyOptions = computed<readonly UiSelectOption<string>[]>(() => {
  if (props.userCompanies.length === 0) {
    return [
      {
        value: String(props.activeCompanyId),
        label: props.activeCompany.businessName,
        hint: `RUC ${props.activeCompany.taxId}`,
      },
    ];
  }

  return props.userCompanies.map((c) => ({
    value: String(c.id),
    label: c.business_name,
    hint: `RUC ${c.tax_id}`,
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
  <header
    class="app-header sticky top-0 z-30 border-b px-3 sm:px-4 lg:px-6 h-14 flex items-center justify-between gap-3"
  >
    <!-- Izquierda: Botón Menú / Colapsar + Breadcrumb -->
    <div class="flex flex-1 items-center gap-2.5 min-w-0">
      <button
        type="button"
        class="lg:hidden inline-flex items-center justify-center w-8 h-8 rounded-md border border-line bg-surf text-foreground hover:bg-soft cursor-pointer"
        aria-label="Abrir navegación"
        @click="emit('open-mobile')"
      >
        <Menu class="w-4 h-4" />
      </button>

      <button
        type="button"
        class="hidden lg:inline-flex items-center justify-center w-8 h-8 rounded-md border border-line bg-surf text-muted hover:text-foreground transition-colors cursor-pointer"
        :title="isSidebarCollapsed ? 'Expandir panel lateral' : 'Contraer panel lateral'"
        :aria-label="isSidebarCollapsed ? 'Expandir panel lateral' : 'Contraer panel lateral'"
        :aria-expanded="!isSidebarCollapsed"
        @click="emit('toggle-collapse')"
      >
        <PanelLeftOpen v-if="isSidebarCollapsed" class="w-4 h-4" />
        <PanelLeftClose v-else class="w-4 h-4" />
      </button>

      <nav class="flex flex-1 items-center gap-1.5 text-xs sm:text-sm min-w-0" aria-label="Ruta de navegación">
        <span class="hidden md:inline text-muted font-medium truncate">
          {{ activeCompany.businessName }}
        </span>
        <ChevronRight class="w-3.5 h-3.5 text-muted hidden md:inline shrink-0" />
        <span class="font-semibold text-foreground truncate">
          {{
            currentModule === 'see-references'
              ? 'Catálogos SEE y Ubigeo · Referencias globales'
              : currentModule === 'sunat-references'
              ? 'Tablas SUNAT PLE/SIRE y anexos RUC · Referencias globales'
              : currentModule === 'subdiaries'
              ? 'Maestro de subdiarios'
              : currentModule === 'purchases'
              ? 'Registro de Compras · Asientos contables'
              : currentModule === 'sales'
              ? 'Registro de Ventas · Asientos contables'
              : currentModule === 'journal'
              ? 'Libro Diario Bimoneda (Vouchers Contables)'
              : currentModule === 'fiscal-periods'
                ? 'Períodos contables · Cierre y auditoría'
              : currentModule === 'financial-statements'
                ? 'Estado de situación financiera · Preliminar'
              : currentModule === 'cost-centers'
              ? 'Centros de costo'
              : currentModule === 'fiscal-history'
                ? 'Historial fiscal · Revisiones por ejercicio'
              : currentModule === 'entities'
                ? 'Terceros y Anexos · Directorio de Empresa'
              : currentModule === 'company-create'
                ? 'Nueva empresa · Asistente de creación'
                : currentModule === 'company'
                ? 'Configuración de Empresa & Monedas'
                : currentModule === 'exchange-rates'
                  ? 'Tipos de Cambio Diarios (SUNAT & SBS)'
                  : 'Maestro del Plan Contable (PCGE)'
          }}
        </span>
      </nav>
    </div>

    <!-- Derecha: Indicadores Financieros Bimoneda (TC SUNAT), Periodo y Selector Móvil -->
    <div class="flex shrink-0 items-center gap-2 sm:gap-3">
      <!-- Selector rápido de empresa en móvil/tablet -->
      <div class="hidden w-40 sm:block lg:hidden">
        <UiSelect
          v-model="activeCompanyIdString"
          :options="companyOptions"
          :disabled="isSwitchingCompany"
          size="sm"
        />
      </div>

      <!-- Resultado de la última comprobación de API; no representa certificación. -->
      <div
        class="hidden xl:inline-flex items-center gap-1.5 rounded-md border border-line bg-soft px-2.5 py-1 text-xs font-medium"
        :class="isLiveConnected ? 'text-pos' : 'text-warn'"
        :title="isLiveConnected
          ? 'La última comprobación con la API fue satisfactoria'
          : 'La última comprobación con la API no fue satisfactoria'"
        role="status"
      >
        <Wifi v-if="isLiveConnected" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        <WifiOff v-else class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
        <span>{{ isLiveConnected ? 'API verificada' : 'API sin verificar' }}</span>
      </div>

      <!-- Usuario Autenticado en Servidor -->
      <div
        v-if="currentUser"
        class="hidden lg:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-surf border border-line text-xs font-medium text-foreground"
        :title="`Usuario de sesión: ${currentUser.email}`"
      >
        <span class="w-2 h-2 rounded-full bg-pos" />
        <span class="truncate max-w-[140px]">{{ currentUser.name }}</span>
      </div>

      <!-- Botón de Cerrar Sesión en Header Superior -->
      <button
        type="button"
        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border border-line bg-surf text-xs font-medium text-muted hover:text-neg hover:border-neg transition-colors cursor-pointer"
        title="Cerrar sesión"
        aria-label="Cerrar sesión"
        @click="emit('logout')"
      >
        <LogOut class="w-3.5 h-3.5 shrink-0 text-neg" />
        <span class="hidden sm:inline">Cerrar Sesión</span>
      </button>

      <!-- Solo tipos de cambio diarios registrados para la empresa activa. -->
      <div
        class="hidden 2xl:inline-flex items-center gap-2 px-3 py-1 rounded-md bg-surf border border-line text-xs num"
        :title="`Tipo de cambio de la empresa para ${headerRateDate}; no es una cotización SUNAT en vivo.`"
        role="status"
        aria-live="polite"
      >
        <ArrowLeftRight class="w-3.5 h-3.5 shrink-0" :class="headerRateStatus === 'recorded' ? 'text-pos' : 'text-muted'" aria-hidden="true" />
        <span class="text-muted whitespace-nowrap">USD/PEN · {{ headerRateDate }}</span>
        <template v-if="headerRateStatus === 'recorded' && headerUsdRate">
          <span class="text-foreground font-semibold whitespace-nowrap">C: {{ headerUsdRate.buy_rate }}</span>
          <span class="text-line" aria-hidden="true">|</span>
          <span class="text-brand font-semibold whitespace-nowrap">V: {{ headerUsdRate.sell_rate }}</span>
        </template>
        <span v-else class="text-muted whitespace-nowrap">
          {{ headerRateStatus === 'loading' ? 'Consultando…' : headerRateStatus === 'unavailable' ? 'No disponible' : headerRateStatus === 'not-applicable' ? 'Base distinta de PEN' : 'Sin registro diario' }}
        </span>
      </div>
    </div>
  </header>
</template>

