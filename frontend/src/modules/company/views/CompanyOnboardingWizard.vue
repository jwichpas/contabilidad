<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { AlertCircle, ArrowLeft, ArrowRight, Building2, Check, CheckCircle2, CircleHelp,
  ClipboardCheck, FileText, FolderTree, Plus, Save, Trash2, X } from 'lucide-vue-next';
import { AccountingApiClient, ApiHttpError } from '@/modules/accounting/api/httpClient';
import type { CompanyId } from '@/modules/accounting/types/accountingAccount.types';
import type { CurrencyItem } from '@/modules/accounting/types/catalogs.types';
import type { EconomicSector, LegalForm } from '../types/companySettings.types';
import {
  COMPANY_ONBOARDING_DRAFT_KEY, companyOnboardingPayload, isValidPeruvianRuc,
  newCompanyOnboardingDraft, onboardingIsComplete, onboardingStepErrors, suggestedSectorForCiiu,
  type CompanyOnboardingDraft, type CompanyOnboardingResult, type PcgeTemplateSummary,
  type TaxRegime, type SunatContributorType, type SunatCiiuClass, type SunatTaxCode,
} from '../types/companyOnboarding.types';

const props = defineProps<{
  readonly activeCompanyId: CompanyId;
  readonly getAccessToken: () => string | null;
}>();
const emit = defineEmits<{
  (event: 'created', result: CompanyOnboardingResult): void;
  (event: 'cancel'): void;
}>();

const steps = [
  { title: 'Identificación', subtitle: 'RUC y forma jurídica' },
  { title: 'Actividad', subtitle: 'Sector y CIIU' },
  { title: 'Tributación', subtitle: 'Régimen e ingresos' },
  { title: 'Contabilidad', subtitle: 'Monedas y PCGE' },
  { title: 'Confirmación', subtitle: 'Revisión y alta' },
] as const;

const sectors: readonly { value: EconomicSector; label: string }[] = [
  { value: 'COMERCIAL', label: 'Comercial / Distribución' },
  { value: 'SERVICIOS', label: 'Servicios' },
  { value: 'INDUSTRIAL', label: 'Industrial / Manufactura' },
  { value: 'AGRICOLA', label: 'Agricultura' },
  { value: 'GANADERIA', label: 'Ganadería' },
  { value: 'PESCA', label: 'Pesca' },
  { value: 'CONSTRUCCION', label: 'Construcción' },
  { value: 'EXTRACTIVA', label: 'Minería / Extractivas' },
  { value: 'TRANSPORTE_LOGISTICA', label: 'Transporte y logística' },
  { value: 'OTROS', label: 'Otras actividades' },
];
const legalForms: readonly { value: LegalForm; label: string }[] = [
  { value: 'PERSONA_NATURAL', label: 'Persona natural con negocio' },
  { value: 'EIRL', label: 'Empresa Individual de Responsabilidad Limitada (EIRL)' },
  { value: 'SAC', label: 'Sociedad Anónima Cerrada (SAC)' },
  { value: 'SRL', label: 'Sociedad Comercial de Responsabilidad Limitada (SRL)' },
  { value: 'SA', label: 'Sociedad Anónima (SA)' },
  { value: 'SAA', label: 'Sociedad Anónima Abierta (SAA)' },
  { value: 'SACS', label: 'Sociedad por Acciones Cerrada Simplificada (SACS)' },
  { value: 'OTRA', label: 'Otra forma jurídica' },
];
const taxRegimes: readonly { value: TaxRegime; label: string }[] = [
  { value: 'GENERAL', label: 'Régimen General' },
  { value: 'MYPE_TRIBUTARIO', label: 'Régimen MYPE Tributario (RMT)' },
  { value: 'RER', label: 'Régimen Especial de Renta (RER)' },
  { value: 'NRUS', label: 'Nuevo RUS (persona natural elegible)' },
];

const draft = reactive<CompanyOnboardingDraft>(newCompanyOnboardingDraft());
const step = ref(0);
const attempted = ref(false);
const saving = ref(false);
const loadingCatalogs = ref(false);
const loadError = ref('');
const error = ref('');
const success = ref<CompanyOnboardingResult | null>(null);
const currencies = ref<readonly CurrencyItem[]>([]);
const templates = ref<readonly PcgeTemplateSummary[]>([]);
const contributorTypes = ref<readonly SunatContributorType[]>([]);
const ciiuClasses = ref<readonly SunatCiiuClass[]>([]);
const sunatTaxCodes = ref<readonly SunatTaxCode[]>([]);
const ciiuQueries = reactive<Record<number,string>>({});
const taxSearch = ref('');
const canCreateOnServer = ref(false);
const serverReason = ref('');

const currentErrors = computed(() => attempted.value ? onboardingStepErrors(step.value, draft) : {});
const pcge2026Early = computed(() => draft.pcge_version === 'PCGE_2026' && draft.fiscal_year < 2028);
const draftHasErrors = computed(() => !onboardingIsComplete(draft));
// No simular monedas ni plantillas: se utilizan solo catálogos reales de Laravel.
const baseCurrencyOptions = computed(() => currencies.value);
const pcgeOptions = computed(() => templates.value);
const officialTypeForLegalForm: Partial<Record<LegalForm,string>> = {
  PERSONA_NATURAL:'02', EIRL:'07', SRL:'28', SA:'26', SAA:'38', SAC:'39',
};
const contributorOptions = computed(() => {
  const corresponding = officialTypeForLegalForm[draft.legal_form as LegalForm];
  return corresponding ? contributorTypes.value.filter(x => x.code === corresponding) : contributorTypes.value;
});

const uiField = 'w-full rounded-lg border border-border bg-surface px-3 py-2.5 text-sm text-foreground outline-none focus:border-brand focus:ring-2 focus:ring-brand/10';
const api = new AccountingApiClient('', {
  getAccessToken: props.getAccessToken,
  getActiveCompanyId: () => props.activeCompanyId,
});

const filteredTaxes = computed(() => sunatTaxCodes.value.filter(x =>
  `${x.code} ${x.short_name} ${x.name}`.toLowerCase().includes(taxSearch.value.toLowerCase().trim()),
));
function matchingCiiu(index: number, selected: string): readonly SunatCiiuClass[] {
  const q = (ciiuQueries[index] ?? '').trim().toLowerCase();
  const matched = ciiuClasses.value.filter(x =>
    `${x.code} ${x.name}`.toLowerCase().includes(q),
  );
  const limited = matched.slice(0, 55);
  const selectedItem = ciiuClasses.value.find(x => x.code === selected);
  return selectedItem && !limited.some(x => x.code === selected) ? [selectedItem, ...limited] : limited;
}
function chooseCiiu(index: number): void {
  const activity = draft.activities[index];
  const official = ciiuClasses.value.find(x => x.code === activity?.ciiu_code);
  if (activity) {
    activity.description = official?.name ?? '';
    const suggested = suggestedSectorForCiiu(activity.ciiu_code);
    if (suggested) activity.sector_code = suggested;
  }
}
function toggleTaxCode(code: string, event: Event): void {
  const checked = (event.target as HTMLInputElement).checked;
  draft.tax_codes = checked
    ? [...draft.tax_codes.filter(x => x !== code), code]
    : draft.tax_codes.filter(x => x !== code);
}
function showError(field: string): string {
  return currentErrors.value[field] ?? '';
}

function next(): void {
  attempted.value = true;
  error.value = '';
  if (Object.keys(onboardingStepErrors(step.value, draft)).length) return;
  if (step.value < steps.length - 1) {
    step.value++;
    attempted.value = false;
  }
}

function previous(): void {
  if (step.value > 0) step.value--;
  attempted.value = false;
  error.value = '';
}

function setPrimary(index: number): void {
  draft.activities.forEach((activity, i) => { activity.is_primary = i === index; });
}

function addActivity(): void {
  if (draft.activities.length >= 10) return;
  draft.activities.push({ sector_code: 'SERVICIOS', ciiu_code: '', description: '', is_primary: false });
}

function removeActivity(index: number): void {
  if (draft.activities.length === 1) return;
  draft.activities.splice(index, 1);
  if (!draft.activities.some(activity => activity.is_primary)) setPrimary(0);
}

function toggleSecondary(code: string, checked: boolean): void {
  const without = draft.secondary_currency_codes.filter(currency => currency !== code);
  draft.secondary_currency_codes = checked ? [...without, code] : without;
}

function saveDraft(): void {
  try {
    sessionStorage.setItem(COMPANY_ONBOARDING_DRAFT_KEY, JSON.stringify(draft));
    error.value = '';
    loadError.value = 'Borrador guardado en esta pestaña. Todavía no se ha creado la empresa.';
  } catch {
    error.value = 'El navegador no permitió guardar el borrador. Los datos permanecen en este formulario.';
  }
}

function resetDraft(): void {
  Object.assign(draft, newCompanyOnboardingDraft());
  sessionStorage.removeItem(COMPANY_ONBOARDING_DRAFT_KEY);
  step.value = 0;
  attempted.value = false;
  error.value = '';
  success.value = null;
}

function confirmResetDraft(): void {
  if (window.confirm('¿Descartar todos los datos no enviados y empezar de nuevo?')) resetDraft();
}

function recoverDraft(): void {
  try {
    const value = sessionStorage.getItem(COMPANY_ONBOARDING_DRAFT_KEY);
    if (!value) return;
    const recovered: unknown = JSON.parse(value);
    if (typeof recovered !== 'object' || recovered === null || Array.isArray(recovered)) return;
    const source = recovered as Partial<CompanyOnboardingDraft>;
    const initial = newCompanyOnboardingDraft();
    Object.assign(draft, {
      ...initial,
      ...source,
      activities: Array.isArray(source.activities) && source.activities.length <= 10 && source.activities.length
        ? source.activities : initial.activities,
      tax_codes: Array.isArray(source.tax_codes) ? source.tax_codes : [],
      sunat_contributor_type_code: typeof source.sunat_contributor_type_code === 'string'
        ? source.sunat_contributor_type_code : '',
      secondary_currency_codes: Array.isArray(source.secondary_currency_codes)
        ? source.secondary_currency_codes : initial.secondary_currency_codes,
      confirm_legal_data: false,
    });
  } catch {
    sessionStorage.removeItem(COMPANY_ONBOARDING_DRAFT_KEY);
  }
}

async function loadCatalogs(): Promise<void> {
  loadingCatalogs.value = true;
  try {
    const [currencyList, pcgeList, capability, types, classes, taxes] = await Promise.all([
      api.fetchCurrencies(), api.fetchPcgeTemplateSummaries(), api.fetchCompanyOnboardingCapability(),
      api.fetchSunatContributorTypes(), api.fetchSunatCiiuRev4(), api.fetchSunatRucTaxCodes(),
    ]);
    contributorTypes.value = types;
    ciiuClasses.value = classes;
    sunatTaxCodes.value = taxes;
    for (const index of draft.activities.keys()) chooseCiiu(index);
    currencies.value = currencyList.filter(c => c.is_active);
    templates.value = pcgeList;
    canCreateOnServer.value = capability.can_create &&
      currencies.value.length > 0 && templates.value.length > 0 &&
      contributorTypes.value.length === 51 && ciiuClasses.value.length === 417 && sunatTaxCodes.value.length === 38;
    serverReason.value = capability.reason ??
      (canCreateOnServer.value ? '' : 'Faltan catálogos contables o monedas activas. No es posible crear la empresa.');
  } catch {
    loadError.value = 'No se pudieron consultar todos los catálogos o el estado de la API. Puede preparar y guardar el borrador.';
    canCreateOnServer.value = false;
    serverReason.value = 'La creación requiere los catálogos reales de Laravel y las migraciones pendientes.';
  } finally {
    loadingCatalogs.value = false;
  }
}

async function createCompany(): Promise<void> {
  attempted.value = true;
  error.value = '';
  if (draftHasErrors.value || saving.value) return;
  if (!contributorTypes.value.some(x => x.code === draft.sunat_contributor_type_code) ||
      !draft.activities.every(a => ciiuClasses.value.some(x => x.code === a.ciiu_code)) ||
      !draft.tax_codes.every(code => sunatTaxCodes.value.some(x => x.code === code))) {
    error.value = 'Seleccione solo códigos SUNAT que existan en los catálogos globales.';
    return;
  }
  if (!canCreateOnServer.value) {
    error.value = serverReason.value || 'El backend de alta aún no está habilitado. Guarde el borrador y aplique primero las migraciones Laravel.';
    return;
  }
  saving.value = true;
  try {
    const result = await api.createCompanyFromWizard(companyOnboardingPayload(draft));
    success.value = result;
    sessionStorage.removeItem(COMPANY_ONBOARDING_DRAFT_KEY);
    emit('created', result);
  } catch (exception) {
    if (exception instanceof ApiHttpError) {
      if (exception.status >= 500) {
        error.value = 'El servidor no confirmó el alta. Antes de volver a intentarlo, comprueba si la empresa ya aparece en el selector por su RUC. Si no existe, revisa el diagnóstico de Laravel.';
      } else {
        error.value = exception.validationErrors.tax_id?.[0] ?? exception.message;
      }
    } else {
      error.value = exception instanceof Error
        ? 'No se pudo confirmar el alta por un error de conexión. Comprueba primero el listado de empresas antes de reintentar.'
        : 'No se pudo confirmar el alta. Verifica primero el listado de empresas.';
    }
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  recoverDraft();
  void loadCatalogs();
});
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-5">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-muted">
          <Building2 class="h-4 w-4" /> Fase 3 · Alta multiempresa
        </div>
        <h1 class="mt-1 text-2xl font-bold tracking-tight text-foreground">Asistente de creación de empresa</h1>
        <p class="mt-1 text-sm text-muted">Registre la entidad, sus actividades y los parámetros iniciales en cinco pasos.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="rounded-lg border border-border bg-surface px-4 py-2.5 text-xs font-semibold text-foreground hover:bg-soft" @click="confirmResetDraft">Reiniciar</button>
        <button type="button" class="inline-flex items-center justify-center gap-2 rounded-lg border border-border bg-surface px-4 py-2.5 text-xs font-semibold text-foreground hover:bg-soft" @click="emit('cancel')">
          <X class="h-4 w-4" /> Volver
        </button>
      </div>
    </div>

    <div v-if="success" class="rounded-xl border border-emerald-300 bg-emerald-50 p-7 text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100">
      <CheckCircle2 class="mb-3 h-9 w-9" />
      <h2 class="text-lg font-bold">Empresa creada correctamente</h2>
      <p class="mt-2 text-sm">{{ success.business_name }} (RUC {{ success.tax_id }}) · {{ success.plan_accounts_created }} cuentas inicializadas.</p>
      <button type="button" class="mt-5 rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white" @click="emit('cancel')">Ir a configuración</button>
    </div>

    <template v-else>
      <div class="rounded-xl border border-border bg-surface p-4 shadow-xs sm:p-5">
        <div class="mb-4 flex items-center justify-between text-xs text-muted">
          <span>Progreso de configuración</span><span class="font-semibold">{{ step + 1 }} de {{ steps.length }}</span>
        </div>
        <div class="mb-5 h-1.5 overflow-hidden rounded-full bg-soft">
          <div class="h-full rounded-full bg-brand transition-all" :style="{ width: `${((step + 1) / steps.length) * 100}%` }" />
        </div>
        <ol class="grid grid-cols-2 gap-3 sm:grid-cols-5" aria-label="Pasos del asistente">
          <li v-for="(item, index) in steps" :key="item.title" class="flex items-center gap-2">
            <span :class="['flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold',
              index === step ? 'bg-brand text-white' : index < step ? 'bg-emerald-100 text-emerald-800' : 'bg-soft text-muted']">
              <Check v-if="index < step" class="h-4 w-4" /><template v-else>{{ index + 1 }}</template>
            </span>
            <span class="min-w-0">
              <strong :class="['block text-xs', index === step ? 'text-foreground' : 'text-muted']">{{ item.title }}</strong>
              <small class="hidden text-[11px] text-muted lg:block">{{ item.subtitle }}</small>
            </span>
          </li>
        </ol>
      </div>

      <div v-if="loadError" class="flex gap-2 rounded-lg border border-border bg-soft px-4 py-3 text-xs text-muted">
        <CircleHelp class="h-4 w-4 shrink-0" /><p>{{ loadError }}</p>
      </div>
      <div v-if="error" role="alert" class="flex gap-2 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800 dark:bg-red-950/30 dark:text-red-200">
        <AlertCircle class="h-4 w-4 shrink-0" />{{ error }}
      </div>

      <form class="rounded-xl border border-border bg-surface p-5 shadow-xs sm:p-7" novalidate @submit.prevent="step === 4 ? createCompany() : next()">
        <div class="mb-6">
          <p class="text-xs font-semibold uppercase tracking-wide text-muted">Paso {{ step + 1 }}</p>
          <h2 class="mt-1 text-lg font-semibold text-foreground">{{ steps[step]?.title }}</h2>
          <p class="text-sm text-muted">{{ steps[step]?.subtitle }}</p>
        </div>

        <section v-if="step === 0" class="grid grid-cols-1 gap-5 md:grid-cols-2">
          <label class="block text-sm font-medium text-foreground">
            RUC de la empresa <span class="text-red-500">*</span>
            <input v-model.trim="draft.tax_id" :class="uiField" class="mt-1" inputmode="numeric" maxlength="11" autocomplete="off" placeholder="11 dígitos" aria-label="RUC" />
            <small v-if="showError('tax_id')" class="mt-1 block text-red-600">{{ showError('tax_id') }}</small>
            <small v-else-if="draft.tax_id && isValidPeruvianRuc(draft.tax_id)" class="mt-1 block text-emerald-700">Dígito verificador correcto</small>
          </label>
          <label class="block text-sm font-medium text-foreground">
            Forma jurídica <span class="text-red-500">*</span>
            <select v-model="draft.legal_form" :class="uiField" class="mt-1" @change="draft.sunat_contributor_type_code = ''">
              <option value="">Seleccionar…</option>
              <option v-for="item in legalForms" :key="item.value" :value="item.value">{{ item.label }}</option>
            </select>
            <small v-if="showError('legal_form')" class="mt-1 block text-red-600">{{ showError('legal_form') }}</small>
          </label>
          <label class="block text-sm font-medium text-foreground md:col-span-2">
            Tipo de contribuyente SUNAT · Anexo 2 <span class="text-red-500">*</span>
            <select v-model="draft.sunat_contributor_type_code" :class="uiField" class="mt-1" :disabled="!draft.legal_form">
              <option value="">Seleccione el tipo declarado en SUNAT…</option>
              <option v-for="item in contributorOptions" :key="item.code" :value="item.code">{{ item.code }} · {{ item.name }}</option>
            </select>
            <small class="mt-1 block text-muted">Código SUNAT RUC; distinto de la forma jurídica interna.</small>
            <small v-if="showError('sunat_contributor_type_code')" class="block text-red-600">{{ showError('sunat_contributor_type_code') }}</small>
          </label>
          <label class="block text-sm font-medium text-foreground md:col-span-2">
            Razón social o nombre del negocio <span class="text-red-500">*</span>
            <input v-model="draft.business_name" :class="uiField" class="mt-1" maxlength="200" placeholder="Ej. Comercial Andina S.A.C." />
            <small v-if="showError('business_name')" class="mt-1 block text-red-600">{{ showError('business_name') }}</small>
          </label>
          <label class="block text-sm font-medium text-foreground md:col-span-2">
            Domicilio fiscal <span class="text-red-500">*</span>
            <input v-model="draft.legal_address" :class="uiField" class="mt-1" maxlength="300" placeholder="Dirección declarada ante SUNAT" />
            <small v-if="showError('legal_address')" class="mt-1 block text-red-600">{{ showError('legal_address') }}</small>
          </label>
        </section>

        <section v-else-if="step === 1" class="space-y-4">
          <p class="text-sm text-muted">Seleccione una actividad principal y hasta nueve secundarias del catálogo CIIU Rev. 4. La descripción se completa desde la referencia oficial.</p>
          <div v-for="(activity, index) in draft.activities" :key="index" class="rounded-lg border border-border bg-soft/40 p-4">
            <div class="mb-4 flex items-center justify-between">
              <label class="flex items-center gap-2 text-sm font-medium text-foreground">
                <input type="radio" name="main-activity" :checked="activity.is_primary" @change="setPrimary(index)" />
                {{ activity.is_primary ? 'Actividad principal' : `Actividad secundaria ${index}` }}
              </label>
              <button type="button" :disabled="draft.activities.length <= 1" class="flex items-center gap-1 text-xs text-red-600 disabled:opacity-40" @click="removeActivity(index)">
                <Trash2 class="h-4 w-4" /> Quitar
              </button>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
              <label class="block text-xs font-medium text-foreground">Sector económico
                <select v-model="activity.sector_code" :class="uiField" class="mt-1"
                  :disabled="suggestedSectorForCiiu(activity.ciiu_code) !== null">
                  <option v-for="sector in sectors" :key="sector.value" :value="sector.value">{{ sector.label }}</option>
                </select>
                <small v-if="suggestedSectorForCiiu(activity.ciiu_code)" class="mt-1 block text-muted">
                  Sector interno asignado automáticamente según el CIIU seleccionado.
                </small>
                <small v-if="showError(`sector_${index}`)" class="mt-1 block text-red-600">
                  {{ showError(`sector_${index}`) }}
                </small>
              </label>
              <label class="block text-xs font-medium text-foreground">CIIU Rev. 4 (cuatro dígitos)
                <input v-model="ciiuQueries[index]" type="search" :class="uiField" class="mt-1 mb-1" placeholder="Buscar actividad por código o nombre…" />
                <select v-model="activity.ciiu_code" :class="uiField" @change="chooseCiiu(index)">
                  <option value="">Seleccione actividad CIIU…</option>
                  <option v-for="item in matchingCiiu(index, activity.ciiu_code)" :key="item.code" :value="item.code">{{ item.code }} · {{ item.name }}</option>
                </select>
                <small v-if="showError(`ciiu_${index}`)" class="text-red-600">{{ showError(`ciiu_${index}`) }}</small>
              </label>
              <label class="block text-xs font-medium text-foreground sm:col-span-2">Descripción
                <input :value="activity.description" :class="uiField" class="mt-1" readonly placeholder="Se completa al seleccionar un CIIU oficial" />
                <small class="mt-1 block text-muted">Descripción obtenida del catálogo. No admite texto libre.</small>
                <small v-if="showError(`description_${index}`)" class="text-red-600">{{ showError(`description_${index}`) }}</small>
              </label>
            </div>
          </div>
          <small v-if="showError('activities')" class="block text-red-600">{{ showError('activities') }}</small>
          <button type="button" :disabled="draft.activities.length >= 10" class="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm text-foreground hover:bg-soft disabled:opacity-40" @click="addActivity">
            <Plus class="h-4 w-4" /> Agregar actividad secundaria
          </button>
        </section>

        <section v-else-if="step === 2" class="space-y-5">
          <div class="grid gap-5 sm:grid-cols-2">
            <label class="block text-sm font-medium text-foreground">Régimen tributario <span class="text-red-500">*</span>
              <select v-model="draft.tax_regime" :class="uiField" class="mt-1">
                <option v-for="regime in taxRegimes" :key="regime.value" :value="regime.value">{{ regime.label }}</option>
              </select>
              <small v-if="showError('tax_regime')" class="block text-red-600">{{ showError('tax_regime') }}</small>
            </label>
            <label class="block text-sm font-medium text-foreground">Ejercicio fiscal inicial
              <input v-model.number="draft.fiscal_year" type="number" min="2000" max="2100" :class="uiField" class="mt-1" />
              <small v-if="showError('fiscal_year')" class="block text-red-600">{{ showError('fiscal_year') }}</small>
            </label>
            <label class="block text-sm font-medium text-foreground">Ejercicio de referencia para ingresos
              <input v-model.number="draft.income_reference_year" type="number" min="2000" :max="draft.fiscal_year" :class="uiField" class="mt-1" />
              <small v-if="showError('income_reference_year')" class="block text-red-600">{{ showError('income_reference_year') }}</small>
            </label>
            <label class="block text-sm font-medium text-foreground">Ingresos referenciales (S/)
              <input v-model.trim="draft.annual_income" :class="uiField" class="mt-1" inputmode="decimal" placeholder="Ej. 150000.00" />
              <small v-if="showError('annual_income')" class="block text-red-600">{{ showError('annual_income') }}</small>
            </label>
          </div>
          <div class="rounded-lg border border-border bg-surface p-4">
            <label class="block text-sm font-semibold text-foreground">Códigos de tributos SUNAT · Anexo 10</label>
            <p class="mt-1 text-xs text-muted">Seleccione los códigos que correspondan a la empresa; no se atribuyen automáticamente tasas, afectaciones ni obligaciones tributarias.</p>
            <input v-model="taxSearch" :class="uiField" type="search" class="mt-3" placeholder="Buscar por código, denominación o descripción…" />
            <div class="mt-3 max-h-52 space-y-1 overflow-y-auto rounded-md border border-border p-2">
              <label v-for="item in filteredTaxes" :key="item.code" class="flex cursor-pointer items-start gap-2 rounded-md px-2 py-2 text-xs text-foreground hover:bg-soft">
                <input type="checkbox" class="mt-0.5" :checked="draft.tax_codes.includes(item.code)" @change="toggleTaxCode(item.code, $event)" />
                <span><strong>{{ item.code }} · {{ item.short_name }}</strong> — {{ item.name }}</span>
              </label>
              <p v-if="filteredTaxes.length === 0" class="p-2 text-xs text-muted">No hay códigos coincidentes.</p>
            </div>
            <p class="mt-2 text-xs text-muted">{{ draft.tax_codes.length }} códigos seleccionados. Esta selección es informativa y revisable.</p>
          </div>
          <div class="flex items-start gap-2 rounded-lg border border-border bg-soft p-3 text-xs text-muted">
            <CircleHelp class="mt-0.5 h-4 w-4 shrink-0" />
            <span>La selección del régimen es declarativa, no una validación automática de elegibilidad. La determinación de libros SUNAT, SIRE/PLE y tramos UIT se incorporará con reglas normativas versionadas.</span>
          </div>
        </section>

        <section v-else-if="step === 3" class="space-y-6">
          <div class="grid gap-5 sm:grid-cols-2">
            <label class="block text-sm font-medium text-foreground">Moneda funcional
              <select v-model="draft.base_currency_code" :class="uiField" class="mt-1" @change="draft.secondary_currency_codes = draft.secondary_currency_codes.filter(c => c !== draft.base_currency_code)">
                <option v-for="currency in baseCurrencyOptions" :key="currency.code" :value="currency.code">{{ currency.code }} · {{ currency.name }}</option>
              </select>
              <small v-if="showError('base_currency_code')" class="block text-red-600">{{ showError('base_currency_code') }}</small>
            </label>
            <div class="space-y-2">
              <p class="text-sm font-medium text-foreground">Monedas secundarias (opcional)</p>
              <label v-for="currency in baseCurrencyOptions.filter(c => c.code !== draft.base_currency_code)" :key="currency.code" class="flex items-center gap-2 text-sm text-foreground">
                <input type="checkbox" :checked="draft.secondary_currency_codes.includes(currency.code)" @change="toggleSecondary(currency.code, ($event.target as HTMLInputElement).checked)" />
                {{ currency.code }} · {{ currency.name }}
              </label>
              <small v-if="showError('secondary_currency_codes')" class="block text-red-600">{{ showError('secondary_currency_codes') }}</small>
            </div>
          </div>
          <div class="border-t border-border pt-5">
            <p class="mb-3 flex items-center gap-2 text-sm font-semibold text-foreground"><FolderTree class="h-4 w-4" /> Plan de cuentas inicial</p>
            <div class="grid gap-3 sm:grid-cols-2">
              <label v-for="version in pcgeOptions" :key="version.plan_version" :class="[
                'flex cursor-pointer items-start gap-3 rounded-lg border p-4',
                draft.pcge_version === version.plan_version ? 'border-brand bg-soft' : 'border-border'
              ]">
                <input v-model="draft.pcge_version" type="radio" name="pcge-version" :value="version.plan_version" />
                <span><strong class="block text-sm text-foreground">{{ version.plan_version.replace('_', ' ') }}</strong>
                  <small class="text-xs text-muted">{{ version.total_accounts ? `${version.total_accounts} cuentas de plantilla` : 'Plantilla disponible después de cargar catálogos' }}</small>
                </span>
              </label>
            </div>
            <label v-if="pcge2026Early" class="mt-3 flex items-start gap-2 text-xs text-muted">
              <input v-model="draft.confirm_early_adoption" type="checkbox" class="mt-0.5" />
              Confirmo la elección anticipada del PCGE 2026 para el ejercicio {{ draft.fiscal_year }}, sujeto a los requisitos y vigencia aplicables.
            </label>
            <small v-if="showError('confirm_early_adoption')" class="mt-1 block text-red-600">{{ showError('confirm_early_adoption') }}</small>
          </div>
        </section>

        <section v-else-if="step === 4" class="space-y-5">
          <div class="grid gap-4 md:grid-cols-2">
            <div class="rounded-lg border border-border p-4">
              <p class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase text-muted"><Building2 class="h-4 w-4" /> Empresa</p>
              <p class="text-sm font-semibold text-foreground">{{ draft.business_name }}</p>
              <p class="text-xs text-muted">RUC {{ draft.tax_id }} · {{ legalForms.find(x => x.value === draft.legal_form)?.label }}</p>
              <p class="mt-1 text-xs text-muted">Tipo SUNAT {{ draft.sunat_contributor_type_code }} · {{ contributorTypes.find(x => x.code === draft.sunat_contributor_type_code)?.name }}</p>
              <p class="mt-2 text-xs text-muted">{{ draft.legal_address }}</p>
            </div>
            <div class="rounded-lg border border-border p-4">
              <p class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase text-muted"><FileText class="h-4 w-4" /> Tributación</p>
              <p class="text-sm font-semibold text-foreground">{{ taxRegimes.find(x => x.value === draft.tax_regime)?.label }}</p>
              <p class="text-xs text-muted">Ejercicio {{ draft.fiscal_year }} · Ingresos {{ draft.income_reference_year }}: S/ {{ draft.annual_income }}</p>
              <p class="mt-1 text-xs text-muted">{{ draft.tax_codes.length }} códigos SUNAT Anexo 10 elegidos</p>
            </div>
            <div class="rounded-lg border border-border p-4">
              <p class="mb-2 text-xs font-semibold uppercase text-muted">Actividad económica</p>
              <p v-for="activity in draft.activities" :key="activity.ciiu_code" class="text-xs text-foreground">
                {{ activity.is_primary ? 'Principal' : 'Secundaria' }} · {{ activity.sector_code }} · CIIU {{ activity.ciiu_code }}
              </p>
            </div>
            <div class="rounded-lg border border-border p-4">
              <p class="mb-2 flex items-center gap-2 text-xs font-semibold uppercase text-muted"><FolderTree class="h-4 w-4" /> Configuración contable</p>
              <p class="text-sm font-semibold text-foreground">{{ draft.pcge_version.replace('_', ' ') }}</p>
              <p class="text-xs text-muted">Moneda funcional: {{ draft.base_currency_code }}</p>
              <p class="text-xs text-muted">Secundarias: {{ draft.secondary_currency_codes.join(', ') || 'Ninguna' }}</p>
            </div>
          </div>
          <label class="flex items-start gap-2 rounded-lg border border-border bg-soft px-4 py-3 text-sm text-foreground">
            <input v-model="draft.confirm_legal_data" type="checkbox" class="mt-1" />
            Confirmo que revisé los datos declarados. La creación puede generar una copia privada del plan de cuentas y no debe repetirse por error.
          </label>
          <small v-if="showError('confirm_legal_data')" class="block text-red-600">{{ showError('confirm_legal_data') }}</small>
          <div v-if="!canCreateOnServer" class="flex gap-2 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-xs text-amber-950 dark:bg-amber-950/30 dark:text-amber-100">
            <AlertCircle class="h-4 w-4 shrink-0" />
            {{ serverReason || 'Alta aún no habilitada en Laravel. Puede guardar su borrador hasta completar las migraciones.' }}
          </div>
        </section>

        <p v-if="saving" role="status" class="mt-5 rounded-lg border border-brand/30 bg-brand/5 px-4 py-3 text-xs text-foreground">
          Creando la empresa y copiando el PCGE de seis dígitos en Neon. La operación es transaccional; no vuelvas a enviar el formulario.
        </p>
        <div class="mt-8 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-5">
          <button v-if="step > 0" type="button" class="inline-flex items-center gap-2 rounded-lg border border-border px-4 py-2.5 text-sm text-foreground hover:bg-soft" @click="previous">
            <ArrowLeft class="h-4 w-4" /> Anterior
          </button>
          <span v-else class="text-xs text-muted">* Datos obligatorios</span>
          <div class="flex flex-wrap gap-2">
            <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-border px-4 py-2.5 text-sm text-foreground hover:bg-soft" @click="saveDraft">
              <Save class="h-4 w-4" /> Guardar borrador
            </button>
            <button v-if="step < 4" type="submit" class="inline-flex items-center gap-2 rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white hover:opacity-90">
              Continuar <ArrowRight class="h-4 w-4" />
            </button>
            <button v-else type="submit" :disabled="saving || loadingCatalogs || draftHasErrors || !canCreateOnServer" class="inline-flex items-center gap-2 rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-45">
              <ClipboardCheck class="h-4 w-4" /> {{ saving ? 'Creando empresa…' : 'Crear empresa' }}
            </button>
          </div>
        </div>
      </form>
      <p class="text-center text-xs text-muted">Los borradores solo se guardan en esta pestaña. No se han modificado empresas existentes ni datos de Supabase.</p>
    </template>
  </div>
</template>
