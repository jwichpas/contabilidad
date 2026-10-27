<script setup lang="ts">
import { computed } from 'vue';
import {
  ArrowLeftRight,
  Calendar,
  ChevronRight,
  LogOut,
  Menu,
  PanelLeftClose,
  PanelLeftOpen,
  ShieldCheck,
} from 'lucide-vue-next';
import UiSelect from '@/shared/ui/UiSelect.vue';
import type { UiSelectOption } from '@/shared/ui/types';
import type { CompanyId } from '@/modules/accounting/types/accountingAccount.types';
import type { UserCompany } from '@/shared/auth/useAuthSession';
import type { SystemDecimal } from '@/shared/types/numeric.types';
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
  readonly sunatBuyRate: SystemDecimal;
  readonly sunatSellRate: SystemDecimal;
  readonly sunatSpread: SystemDecimal;
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
    class="sticky top-0 z-30 bg-surface/90 backdrop-blur-md border-b border-border px-4 lg:px-6 h-14 flex items-center justify-between gap-3"
  >
    <!-- Izquierda: Botón Menú / Colapsar + Breadcrumb -->
    <div class="flex items-center gap-2.5 min-w-0">
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
        @click="emit('toggle-collapse')"
      >
        <PanelLeftOpen v-if="isSidebarCollapsed" class="w-4 h-4" />
        <PanelLeftClose v-else class="w-4 h-4" />
      </button>

      <nav class="flex items-center gap-1.5 text-xs sm:text-sm min-w-0" aria-label="Ruta de navegación">
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
    <div class="flex items-center gap-2 sm:gap-3">
      <!-- Selector rápido de empresa en móvil/tablet -->
      <div class="lg:hidden w-44">
        <UiSelect
          v-model="activeCompanyIdString"
          :options="companyOptions"
          :disabled="isSwitchingCompany"
          size="sm"
        />
      </div>

      <!-- Indicador de Seguridad ISO/IEC 27001:2023 -->
      <div
        class="hidden xl:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border text-xs font-medium transition-colors"
        :class="isLiveConnected ? 'tag ok' : 'tag wa'"
        :title="isLiveConnected ? 'Sesión auditada y cifrada bajo controles ISO/IEC 27001:2023 (Control 8.5, 8.15)' : 'Conectando con el servidor seguro...'"
      >
        <ShieldCheck class="w-3.5 h-3.5 shrink-0" />
        <span class="font-semibold">ISO 27001</span>
        <span class="text-[10px] font-mono opacity-80">· {{ isLiveConnected ? 'SGSI Activo' : 'Conectando...' }}</span>
      </div>

      <!-- Usuario Autenticado en Servidor -->
      <div
        v-if="currentUser"
        class="hidden lg:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-surf border border-line text-xs font-medium text-foreground"
        :title="`Usuario auditado: ${currentUser.email}`"
      >
        <span class="w-2 h-2 rounded-full bg-pos animate-pulse" />
        <span class="truncate max-w-[140px]">{{ currentUser.name }}</span>
      </div>

      <!-- Botón de Cerrar Sesión en Header Superior -->
      <button
        type="button"
        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border border-line bg-surf text-xs font-medium text-muted hover:text-neg hover:border-neg transition-colors cursor-pointer"
        title="Cerrar sesión de forma segura (ISO 27001)"
        @click="emit('logout')"
      >
        <LogOut class="w-3.5 h-3.5 shrink-0 text-neg" />
        <span class="hidden sm:inline">Cerrar Sesión</span>
      </button>

      <!-- Periodo Contable Activo -->
      <div
        class="hidden sm:inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-surf border border-line text-xs font-medium text-foreground"
      >
        <Calendar class="w-3.5 h-3.5 text-brand" />
        <span>Ejercicio:</span>
        <strong class="num">2026-10</strong>
        <span class="w-2 h-2 rounded-full bg-pos" title="Periodo Contable Abierto" />
      </div>

      <!-- Ticker Tipo de Cambio SUNAT (System.Decimal 14,6) -->
      <div
        class="hidden md:inline-flex items-center gap-2 px-3 py-1 rounded-md bg-surf border border-line text-xs num"
        :title="`Spread cambiario exacto System.Decimal(14,6): ${sunatSpread.toString()}`"
      >
        <ArrowLeftRight class="w-3.5 h-3.5 text-pos" />
        <span class="text-muted">USD/PEN:</span>
        <span class="text-foreground font-semibold">C: {{ sunatBuyRate.toString() }}</span>
        <span class="text-line">|</span>
        <span class="text-brand font-semibold">V: {{ sunatSellRate.toString() }}</span>
      </div>
    </div>
  </header>
</template>

