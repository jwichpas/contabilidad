<script setup lang="ts">
import { computed, defineAsyncComponent, onMounted, onUnmounted, shallowRef, watch } from 'vue';
import { useQuery, useQueryClient } from '@tanstack/vue-query';
import { Loader2 } from 'lucide-vue-next';
import { Toaster } from 'vue-sonner';













import type { CompanyOnboardingResult } from './modules/company/types/companyOnboarding.types';
import UiViewLoading from './shared/ui/UiViewLoading.vue';
import UiViewLoadError from './shared/ui/UiViewLoadError.vue';
import LoginView from './shared/auth/LoginView.vue';
import AppSidebar from './shared/layout/AppSidebar.vue';
import AppHeader from './shared/layout/AppHeader.vue';
import type { ActiveCompanyInfo, NavigationModule } from './shared/layout/navigation.types';
import { asCompanyId, type CompanyId } from './modules/accounting/types/accountingAccount.types';
import { useAuthSession } from './shared/auth/useAuthSession';
import { selectRecordedUsdPenRate } from './modules/accounting/utils/headerExchangeRate';
import { formatAccountingDate, peruAccountingDate } from './modules/accounting/utils/journalPresentation';

// Pantallas cargadas por módulo, conservando la clave por empresa y los eventos.
 // Vista y autenticación globales permanecen síncronas para evitar un shell vacío.
const AccountingCatalogView = defineAsyncComponent({
  loader: () => import('./modules/accounting/views/AccountingCatalogView.vue'),
  loadingComponent: UiViewLoading,
  errorComponent: UiViewLoadError,
  delay: 120,
});

const SeeReferencesView = defineAsyncComponent({
  loader: () => import('./modules/accounting/views/SeeReferencesView.vue'),
  loadingComponent: UiViewLoading,
  errorComponent: UiViewLoadError,
  delay: 120,
});

const SunatReferencesView = defineAsyncComponent({
  loader: () => import('./modules/accounting/views/SunatReferencesView.vue'),
  loadingComponent: UiViewLoading,
  errorComponent: UiViewLoadError,
  delay: 120,
});

const JournalEntryView = defineAsyncComponent({
  loader: () => import('./modules/accounting/views/JournalEntryView.vue'),
  loadingComponent: UiViewLoading,
  errorComponent: UiViewLoadError,
  delay: 120,
});

const DocumentJournalView = defineAsyncComponent({
  loader: () => import('./modules/accounting/views/DocumentJournalView.vue'),
  loadingComponent: UiViewLoading,
  errorComponent: UiViewLoadError,
  delay: 120,
});

const CompanySubdiariesView = defineAsyncComponent({
  loader: () => import('./modules/accounting/views/CompanySubdiariesView.vue'),
  loadingComponent: UiViewLoading,
  errorComponent: UiViewLoadError,
  delay: 120,
});

const ExchangeRatesView = defineAsyncComponent({
  loader: () => import('./modules/accounting/views/ExchangeRatesView.vue'),
  loadingComponent: UiViewLoading,
  errorComponent: UiViewLoadError,
  delay: 120,
});

const FiscalPeriodsView = defineAsyncComponent({
  loader: () => import('./modules/accounting/views/FiscalPeriodsView.vue'),
  loadingComponent: UiViewLoading,
  errorComponent: UiViewLoadError,
  delay: 120,
});

const FinancialStatementsView = defineAsyncComponent({
  loader: () => import('./modules/accounting/views/FinancialStatementsView.vue'),
  loadingComponent: UiViewLoading,
  errorComponent: UiViewLoadError,
  delay: 120,
});

const CompanySettingsView = defineAsyncComponent({
  loader: () => import('./modules/company/views/CompanySettingsView.vue'),
  loadingComponent: UiViewLoading,
  errorComponent: UiViewLoadError,
  delay: 120,
});

const EntityMasterView = defineAsyncComponent({
  loader: () => import('./modules/company/views/EntityMasterView.vue'),
  loadingComponent: UiViewLoading,
  errorComponent: UiViewLoadError,
  delay: 120,
});

const CompanyFiscalHistoryView = defineAsyncComponent({
  loader: () => import('./modules/company/views/CompanyFiscalHistoryView.vue'),
  loadingComponent: UiViewLoading,
  errorComponent: UiViewLoadError,
  delay: 120,
});

const CostCenterMasterView = defineAsyncComponent({
  loader: () => import('./modules/company/views/CostCenterMasterView.vue'),
  loadingComponent: UiViewLoading,
  errorComponent: UiViewLoadError,
  delay: 120,
});

const CompanyOnboardingWizard = defineAsyncComponent({
  loader: () => import('./modules/company/views/CompanyOnboardingWizard.vue'),
  loadingComponent: UiViewLoading,
  errorComponent: UiViewLoadError,
  delay: 120,
});

const {
  accessToken,
  currentUser,
  userCompanies,
  activeCompanyId,
  isAuthenticated,
  sessionVerification,
  isLiveConnected,
  isSwitchingCompany,
  companySwitchError,
  switchCompany,
  ensureAuthenticated,
  registerCreatedCompany,
  logout,
} = useAuthSession();

// Accounting data is private to the active session and company. Never reuse a
// cached directory after logging out or switching tenants, even for the same user.
const queryClient = useQueryClient();
watch([accessToken, activeCompanyId], ([token, company], [previousToken, previousCompany]) => {
  if (token !== previousToken || company !== previousCompany) queryClient.clear();
}, { flush: 'sync' });

const isDarkMode = shallowRef(false);
const isSidebarCollapsed = shallowRef(false);
const isMobileDrawerOpen = shallowRef(false);
const isSessionVerifiedOnLoad = shallowRef(false);
const isJournalSubmitting = shallowRef(false);
const companySwitchNotice = shallowRef<string | null>(null);

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

const canManageEntities = computed(() => {
  const role = userCompanies.value.find((c) => c.id === activeCompanyId.value)?.role;
  return role === 'ADMIN' || role === 'CHIEF_ACCOUNTANT' || role === 'ACCOUNTANT';
});
const canDeactivateEntities = computed(() => {
  const role = userCompanies.value.find((c) => c.id === activeCompanyId.value)?.role;
  return role === 'ADMIN' || role === 'CHIEF_ACCOUNTANT';
});

const canManagePeriods = computed(() => {
  const company = userCompanies.value.find((c) => c.id === activeCompanyId.value);
  return company?.role === 'ADMIN' || company?.role === 'CHIEF_ACCOUNTANT';
});

// Tasas registradas de la empresa activa. Nunca anunciar tasas sugeridas
// ni aparentar una consulta SUNAT en vivo desde la cabecera.
const headerFxDate = shallowRef(peruAccountingDate());
const headerRateQuery = useQuery({
  queryKey: computed(() => ['header-exchange-rates', activeCompanyId.value, headerFxDate.value] as const),
  enabled: computed(() => isAuthenticated.value && !isSwitchingCompany.value && !!accessToken.value),
  queryFn: async ({ signal }) => {
    const token = accessToken.value;
    const tenant = activeCompanyId.value;
    const date = headerFxDate.value;
    if (!token) throw new Error('Sesión no disponible para consultar tipos de cambio.');
    // Mantener carga diferida del API: el shell no arrastra esquemas del editor.
    const { AccountingApiClient } = await import('./modules/accounting/api/httpClient');
    if (signal.aborted) throw new DOMException('Consulta anterior cancelada.', 'AbortError');
    return new AccountingApiClient('', {
      getAccessToken: () => token,
      getActiveCompanyId: () => tenant,
    }).fetchCompanyExchangeRates(date, signal);
  },
  staleTime: 60_000,
  refetchOnWindowFocus: true,
  retry: false,
});

const headerUsdRate = computed(() => {
  if (!isAuthenticated.value || isSwitchingCompany.value || headerRateQuery.isFetching.value) return null;
  return selectRecordedUsdPenRate(headerRateQuery.data.value, headerFxDate.value);
});

const headerRateStatus = computed<'loading' | 'unavailable' | 'unrecorded' | 'not-applicable' | 'recorded'>(() => {
  if (!isAuthenticated.value || isSwitchingCompany.value ||
      headerRateQuery.isPending.value || headerRateQuery.isFetching.value) return 'loading';
  if (headerRateQuery.isError.value) return 'unavailable';
  if (headerRateQuery.data.value?.base_currency.code !== 'PEN') return 'not-applicable';
  return headerUsdRate.value ? 'recorded' : 'unrecorded';
});

function refreshHeaderDate(): void {
  headerFxDate.value = peruAccountingDate();
}

function resolveModuleFromHash(): NavigationModule {
  if (typeof window === 'undefined') return 'journal';
  const hash = window.location.hash.toLowerCase();
  if (hash.includes('catalogos-see')) return 'see-references';
  if (hash.includes('catalogos-sunat')) return 'sunat-references';
  if (hash.includes('registro-compras')) return 'purchases';
  if (hash.includes('registro-ventas')) return 'sales';
  if (hash.includes('subdiarios') || hash.includes('subdiaries')) return 'subdiaries';
  if (hash.includes('centros-costo') || hash.includes('cost-centers')) return 'cost-centers';
  if (hash.includes('nueva-empresa') || hash.includes('company-create')) return 'company-create';
  if (hash.includes('periodos') || hash.includes('fiscal-periods')) return 'fiscal-periods';
  if (hash.includes('estados-financieros') || hash.includes('financial-statements')) return 'financial-statements';
  if (hash.includes('historial-fiscal') || hash.includes('fiscal-history')) return 'fiscal-history';
  if (hash.includes('anexos') || hash.includes('terceros') || hash.includes('entities')) return 'entities';
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
  if (isSwitchingCompany.value) return;
  currentModule.value = mod;
  if (mod === 'journal') {
    window.location.hash = '#diario';
  } else if (mod === 'purchases') {
    window.location.hash = '#registro-compras';
  } else if (mod === 'sales') {
    window.location.hash = '#registro-ventas';
  } else if (mod === 'catalog') {
    window.location.hash = '#plan-cuentas';
  } else if (mod === 'see-references') {
    window.location.hash = '#catalogos-see';
  } else if (mod === 'sunat-references') {
    window.location.hash = '#catalogos-sunat';
  } else if (mod === 'fiscal-periods') {
    window.location.hash = '#periodos';
  } else if (mod === 'financial-statements') {
    window.location.hash = '#estados-financieros';
  } else if (mod === 'exchange-rates') {
    window.location.hash = '#tipo-cambio';
  } else if (mod === 'fiscal-history') {
    window.location.hash = '#historial-fiscal';
  } else if (mod === 'subdiaries') {
    window.location.hash = '#subdiarios';
  } else if (mod === 'cost-centers') {
    window.location.hash = '#centros-costo';
  } else if (mod === 'entities') {
    window.location.hash = '#anexos';
  } else if (mod === 'company') {
    window.location.hash = '#empresa';
  } else if (mod === 'company-create') {
    window.location.hash = '#nueva-empresa';
  }
  isMobileDrawerOpen.value = false;
}

function handleSwitchCompany(newCompanyId: CompanyId): void {
  if (isSwitchingCompany.value || newCompanyId === activeCompanyId.value) return;
  companySwitchNotice.value = isJournalSubmitting.value
    ? 'Había un asiento enviándose en la empresa anterior. Compruebe su Libro Diario antes de volver a registrarlo.'
    : null;
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
  window.addEventListener('focus', refreshHeaderDate);
  document.addEventListener('visibilitychange', refreshHeaderDate);
  try {
    await ensureAuthenticated();
  } finally {
    isSessionVerifiedOnLoad.value = true;
  }
});

onUnmounted(() => {
  window.removeEventListener('hashchange', syncHashWithModule);
  window.removeEventListener('focus', refreshHeaderDate);
  document.removeEventListener('visibilitychange', refreshHeaderDate);
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
    v-if="!isSessionVerifiedOnLoad || sessionVerification === 'verifying'"
    class="min-h-screen bg-background p-4 text-sm text-muted sm:p-6"
    role="status"
    aria-label="Verificando sesión segura"
  >
    <div class="mx-auto max-w-5xl space-y-6" aria-hidden="true">
      <div class="ui-skeleton h-14 w-full" />
      <div class="ui-skeleton h-8 w-2/3 sm:w-1/3" />
      <div class="ui-skeleton h-40 w-full" />
      <div class="ui-skeleton h-40 w-full" />
    </div>
    <span class="sr-only">Verificando sesión segura…</span>
  </div>
  <div
    v-else-if="sessionVerification === 'unavailable'"
    class="min-h-screen bg-background px-4 py-16 text-foreground sm:px-6"
  >
    <section class="workspace-section mx-auto max-w-md space-y-4 p-6" aria-labelledby="session-unavailable-title">
      <h1 id="session-unavailable-title" class="text-lg font-semibold">No pudimos verificar tu sesión</h1>
      <p class="text-sm text-muted" role="alert">
        Comprueba tu conexión y vuelve a intentarlo. Tus datos permanecerán ocultos hasta confirmar el acceso.
      </p>
      <div class="flex flex-wrap gap-3">
        <button
          type="button"
          class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
          @click="ensureAuthenticated"
        >Reintentar</button>
        <button
          type="button"
          class="rounded-lg border border-border px-4 py-2 text-sm font-semibold focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
          @click="logout"
        >Cerrar sesión en este dispositivo</button>
      </div>
    </section>
  </div>
  <LoginView
    v-else-if="!isAuthenticated"
    :is-dark-mode="isDarkMode"
    @toggle-dark="isDarkMode = !isDarkMode"
  />

  <div v-else class="app-shell min-h-screen flex text-foreground">
    <!-- Componente Modular: Barra Lateral (Sidebar) -->
    <AppSidebar
      :current-module="currentModule"
      :is-collapsed="isSidebarCollapsed"
      :is-open-mobile="isMobileDrawerOpen"
      :active-company-id="activeCompanyId"
      :user-companies="userCompanies"
      :active-company="activeCompany"
      :is-dark-mode="isDarkMode"
      :is-switching-company="isSwitchingCompany"
      @navigate="handleNavigate"
      @switch-company="handleSwitchCompany"
      @close-mobile="isMobileDrawerOpen = false"
      @toggle-dark="isDarkMode = !isDarkMode"
      @logout="logout"
    />

    <!-- Contenedor Principal (Header Superior + Área de Trabajo Modular) -->
    <div class="app-panel flex-1 flex flex-col min-w-0">
      <!-- Componente Modular: Cabecera Superior (Header) -->
      <AppHeader
        :current-module="currentModule"
        :active-company="activeCompany"
        :active-company-id="activeCompanyId"
        :user-companies="userCompanies"
        :current-user="currentUser"
        :is-live-connected="isLiveConnected"
        :is-switching-company="isSwitchingCompany"
        :is-sidebar-collapsed="isSidebarCollapsed"
        :header-usd-rate="headerUsdRate"
        :header-rate-date="formatAccountingDate(headerFxDate)"
        :header-rate-status="headerRateStatus"
        @toggle-collapse="isSidebarCollapsed = !isSidebarCollapsed"
        @open-mobile="isMobileDrawerOpen = true"
        @switch-company="handleSwitchCompany"
        @logout="logout"
      />

      <!-- Contenido Principal Fluido y Responsivo -->
      <main :inert="isSwitchingCompany" class="app-content flex-1 p-3.5 sm:p-5 lg:p-6 w-full mx-auto">
        <div v-if="companySwitchNotice" role="status" class="mb-4 rounded-lg border border-brand bg-surface p-3 text-sm text-foreground">
          {{ companySwitchNotice }}
          <button type="button" class="ml-2 underline" @click="companySwitchNotice = null">Cerrar</button>
        </div>
        <div v-if="companySwitchError" role="alert" class="mb-4 rounded-lg border border-danger bg-surface p-3 text-sm text-danger">
          {{ companySwitchError }} Revise el editor antes de continuar; el intento de cambio pudo descartar el borrador.
        </div>
        <AccountingCatalogView
          v-if="currentModule === 'catalog'"
          :key="`catalog-${activeCompanyId}`"
          :active-company-id="activeCompanyId"
          :get-access-token="() => accessToken"
        />
        <SeeReferencesView
          v-else-if="currentModule === 'see-references'"
          :key="`see-${activeCompanyId}`"
          :active-company-id="activeCompanyId"
          :get-access-token="() => accessToken"
          :can-manage="canDeactivateEntities"
        />
        <SunatReferencesView
          v-else-if="currentModule === 'sunat-references'"
          :get-access-token="() => accessToken"
        />
        <JournalEntryView
          v-else-if="currentModule === 'journal'"
          :key="`journal-${activeCompanyId}`"
          :active-company-id="activeCompanyId"
          :get-access-token="() => accessToken"
          @posting-change="isJournalSubmitting = $event"
        />
        <DocumentJournalView
          v-else-if="currentModule === 'purchases' || currentModule === 'sales'"
          :key="'documents-' + currentModule + '-' + activeCompanyId"
          :module="currentModule === 'purchases' ? 'purchases' : 'sales'"
          :active-company-id="activeCompanyId"
          :get-access-token="() => accessToken"
          :can-configure="canDeactivateEntities"
          :can-post="canManageEntities"
          @posting-change="isJournalSubmitting = $event"
        />
        <FiscalPeriodsView
          v-else-if="currentModule === 'fiscal-periods'"
          :key="`periods-${activeCompanyId}`"
          :active-company-id="activeCompanyId"
          :get-access-token="() => accessToken"
          :can-manage-periods="canManagePeriods"
        />
        <FinancialStatementsView
          v-else-if="currentModule === 'financial-statements'"
          :key="`financial-${activeCompanyId}`"
          :active-company-id="activeCompanyId"
          :get-access-token="() => accessToken"
        />
        <ExchangeRatesView
          v-else-if="currentModule === 'exchange-rates'"
          :key="`rates-${activeCompanyId}`"
          :active-company-id="activeCompanyId"
          :get-access-token="() => accessToken"
        />
        <CompanySubdiariesView
          v-else-if="currentModule === 'subdiaries'"
          :key="'subdiaries-' + activeCompanyId"
          :active-company-id="activeCompanyId"
          :get-access-token="() => accessToken"
          :can-write="canDeactivateEntities"
        />
        <CostCenterMasterView
          v-else-if="currentModule === 'cost-centers'"
          :key="'cost-centers-' + activeCompanyId"
          :active-company-id="activeCompanyId"
          :get-access-token="() => accessToken"
          :can-write="canDeactivateEntities"
        />
        <CompanyFiscalHistoryView
          v-else-if="currentModule === 'fiscal-history'"
          :key="'fiscal-history-' + activeCompanyId"
          :active-company-id="activeCompanyId"
          :get-access-token="() => accessToken"
          :can-write="canDeactivateEntities"
        />
        <EntityMasterView
          v-else-if="currentModule === 'entities'"
          :key="`entities-${activeCompanyId}`"
          :active-company-id="activeCompanyId"
          :get-access-token="() => accessToken"
          :can-write="canManageEntities"
          :can-deactivate="canDeactivateEntities"
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
      <div v-if="isSwitchingCompany" role="status" aria-live="polite"
        class="fixed inset-x-0 bottom-0 top-14 z-40 overflow-y-auto bg-background p-4 text-foreground sm:p-6">
        <div class="mx-auto max-w-3xl space-y-5">
          <div class="rounded-xl border border-border bg-surface p-5 shadow-card">
            <div class="flex items-start gap-3">
              <Loader2 class="mt-0.5 size-5 shrink-0 animate-spin text-brand" />
              <div class="min-w-0">
                <p class="font-semibold">Verificando acceso a {{ activeCompany.businessName }}…</p>
                <p class="mt-1 text-sm text-muted">El editor quedará disponible cuando se confirme la empresa.</p>
                <p v-if="companySwitchNotice" class="mt-3 text-sm text-foreground">{{ companySwitchNotice }}</p>
              </div>
            </div>
          </div>
          <div aria-hidden="true" class="space-y-4 rounded-xl border border-border bg-surface p-5">
            <div class="ui-skeleton h-5 w-1/2" />
            <div class="ui-skeleton h-11 w-full" />
            <div class="ui-skeleton h-11 w-full" />
            <div class="ui-skeleton h-11 w-3/4" />
          </div>
        </div>
      </div>
    </div>
  </div>
  <Toaster :theme="isDarkMode ? 'dark' : 'light'" position="top-right"
    :visible-toasts="4" :duration="4800" close-button rich-colors />
</template>
