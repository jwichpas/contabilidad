<script setup lang="ts">
import { computed, onMounted, onUnmounted, shallowRef, watch } from 'vue';
import AccountingCatalogView from './modules/accounting/views/AccountingCatalogView.vue';
import JournalEntryView from './modules/accounting/views/JournalEntryView.vue';
import ExchangeRatesView from './modules/accounting/views/ExchangeRatesView.vue';
import CompanySettingsView from './modules/company/views/CompanySettingsView.vue';
import CompanyOnboardingWizard from './modules/company/views/CompanyOnboardingWizard.vue';
import type { CompanyOnboardingResult } from './modules/company/types/companyOnboarding.types';
import LoginView from './shared/auth/LoginView.vue';
import AppSidebar from './shared/layout/AppSidebar.vue';
import AppHeader from './shared/layout/AppHeader.vue';
import type { ActiveCompanyInfo, NavigationModule } from './shared/layout/navigation.types';
import { asCompanyId, type CompanyId } from './modules/accounting/types/accountingAccount.types';
import { useAuthSession } from './shared/auth/useAuthSession';
import { SystemDecimal } from './shared/types/numeric.types';

const {
  accessToken,
  currentUser,
  userCompanies,
  activeCompanyId,
  isAuthenticated,
  isLiveConnected,
  switchCompany,
  ensureAuthenticated,
  registerCreatedCompany,
  logout,
} = useAuthSession();

const isDarkMode = shallowRef(false);
const isSidebarCollapsed = shallowRef(false);
const isMobileDrawerOpen = shallowRef(false);
const isSessionVerifiedOnLoad = shallowRef(false);

const activeCompany = computed<ActiveCompanyInfo>(() => {
  const found = userCompanies.value.find((c) => c.id === activeCompanyId.value);
  if (found) {
    return {
      id: found.id,
      taxId: found.tax_id,
      businessName: found.business_name,
      fiscalRegime: found.fiscal_regime ?? 'Régimen General · PRICO',
    };
  }

  return {
    id: activeCompanyId.value,
    taxId: '20601234567',
    businessName: 'Corporación Peruana de Distribución S.A.C.',
    fiscalRegime: 'Régimen General · PRICO',
  };
});

// Demostración en vivo de System.Decimal (NUMERIC(14,6)) para Tipo de Cambio SUNAT
const sunatBuyRate = SystemDecimal.fromExchangeRate('3.742000');
const sunatSellRate = SystemDecimal.fromExchangeRate('3.758000');
const sunatSpread = sunatSellRate.subtract(sunatBuyRate);

function resolveModuleFromHash(): NavigationModule {
  if (typeof window === 'undefined') return 'journal';
  const hash = window.location.hash.toLowerCase();
  if (hash.includes('nueva-empresa') || hash.includes('company-create')) return 'company-create';
  if (hash.includes('diario') || hash.includes('asiento') || hash.includes('voucher')) {
    return 'journal';
  } else if (hash.includes('plan') || hash.includes('cuentas') || hash.includes('catalogo')) {
    return 'catalog';
  } else if (hash.includes('cambio') || hash.includes('exchange') || hash.includes('tasa') || hash.includes('tc')) {
    return 'exchange-rates';
  } else if (hash.includes('empresa') || hash.includes('company') || hash.includes('configuracion')) {
    return 'company';
  }
  return 'journal';
}

// Navegación Modular Reactiva inicializada de inmediato según la URL activa
const currentModule = shallowRef<NavigationModule>(resolveModuleFromHash());

function syncHashWithModule(): void {
  currentModule.value = resolveModuleFromHash();
}

function handleNavigate(mod: NavigationModule): void {
  currentModule.value = mod;
  if (mod === 'journal') {
    window.location.hash = '#diario';
  } else if (mod === 'catalog') {
    window.location.hash = '#plan-cuentas';
  } else if (mod === 'exchange-rates') {
    window.location.hash = '#tipo-cambio';
  } else if (mod === 'company') {
    window.location.hash = '#empresa';
  } else if (mod === 'company-create') {
    window.location.hash = '#nueva-empresa';
  }
  isMobileDrawerOpen.value = false;
}

function handleSwitchCompany(newCompanyId: CompanyId): void {
  void switchCompany(newCompanyId);
}

async function handleCompanyCreated(result: CompanyOnboardingResult): Promise<void> {
  registerCreatedCompany({
    id: asCompanyId(result.id),
    uuid: result.uuid,
    tax_id: result.tax_id,
    business_name: result.business_name,
    legal_address: result.legal_address,
    role: 'ADMIN',
  });
  const switched = await switchCompany(asCompanyId(result.id));
  if (switched) handleNavigate('company');
}

onMounted(async () => {
  syncHashWithModule();
  window.addEventListener('hashchange', syncHashWithModule);
  try {
    await ensureAuthenticated();
  } finally {
    isSessionVerifiedOnLoad.value = true;
  }
});

onUnmounted(() => {
  window.removeEventListener('hashchange', syncHashWithModule);
});

watch(
  isDarkMode,
  (dark) => {
    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
  },
  { immediate: true },
);
</script>

<template>
  <!-- No mostrar datos en caché hasta validar la sesión restaurada. -->
  <div
    v-if="!isSessionVerifiedOnLoad"
    class="flex min-h-screen items-center justify-center bg-background p-6 text-sm text-muted"
    role="status"
  >
    Verificando sesión segura…
  </div>
  <LoginView
    v-else-if="!isAuthenticated"
    :is-dark-mode="isDarkMode"
    @toggle-dark="isDarkMode = !isDarkMode"
  />

  <div v-else class="min-h-screen flex bg-background text-foreground">
    <!-- Componente Modular: Barra Lateral (Sidebar) -->
    <AppSidebar
      :current-module="currentModule"
      :is-collapsed="isSidebarCollapsed"
      :is-open-mobile="isMobileDrawerOpen"
      :active-company-id="activeCompanyId"
      :user-companies="userCompanies"
      :active-company="activeCompany"
      :is-dark-mode="isDarkMode"
      @navigate="handleNavigate"
      @switch-company="handleSwitchCompany"
      @close-mobile="isMobileDrawerOpen = false"
      @toggle-dark="isDarkMode = !isDarkMode"
      @logout="logout"
    />

    <!-- Contenedor Principal (Header Superior + Área de Trabajo Modular) -->
    <div class="flex-1 flex flex-col min-w-0">
      <!-- Componente Modular: Cabecera Superior (Header) -->
      <AppHeader
        :current-module="currentModule"
        :active-company="activeCompany"
        :active-company-id="activeCompanyId"
        :user-companies="userCompanies"
        :current-user="currentUser"
        :is-live-connected="isLiveConnected"
        :is-sidebar-collapsed="isSidebarCollapsed"
        :sunat-buy-rate="sunatBuyRate"
        :sunat-sell-rate="sunatSellRate"
        :sunat-spread="sunatSpread"
        @toggle-collapse="isSidebarCollapsed = !isSidebarCollapsed"
        @open-mobile="isMobileDrawerOpen = true"
        @switch-company="handleSwitchCompany"
        @logout="logout"
      />

      <!-- Contenido Principal Fluido y Responsivo -->
      <main class="flex-1 p-3.5 sm:p-5 lg:p-6 max-w-[1680px] w-full mx-auto">
        <AccountingCatalogView
          v-if="currentModule === 'catalog'"
          :key="`catalog-${activeCompanyId}`"
          :active-company-id="activeCompanyId"
          :get-access-token="() => accessToken"
        />
        <JournalEntryView
          v-else-if="currentModule === 'journal'"
          :key="`journal-${activeCompanyId}`"
          :active-company-id="activeCompanyId"
          :get-access-token="() => accessToken"
        />
        <ExchangeRatesView
          v-else-if="currentModule === 'exchange-rates'"
          :key="`rates-${activeCompanyId}`"
          :active-company-id="activeCompanyId"
          :get-access-token="() => accessToken"
        />
        <CompanyOnboardingWizard
          v-else-if="currentModule === 'company-create'"
          :active-company-id="activeCompanyId"
          :get-access-token="() => accessToken"
          @cancel="handleNavigate('company')"
          @created="handleCompanyCreated"
        />
        <CompanySettingsView
          v-else-if="currentModule === 'company'"
          :key="`company-${activeCompanyId}`"
          :active-company-id="activeCompanyId"
          :get-access-token="() => accessToken"
          @company-updated="ensureAuthenticated"
        />
      </main>
    </div>
  </div>
</template>
