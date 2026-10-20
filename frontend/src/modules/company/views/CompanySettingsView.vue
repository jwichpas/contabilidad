<script setup lang="ts">
import { computed, onMounted, ref, shallowRef, watch } from 'vue';
import {
  AlertTriangle,
  Building2,
  CheckCircle2,
  Coins,
  FileSpreadsheet,
  Globe,
  Loader2,
  Save,
  ShieldCheck,
  UserCheck,
} from 'lucide-vue-next';
import { AccountingApiClient } from '@/modules/accounting/api/httpClient';
import type { CompanyId } from '@/modules/accounting/types/accountingAccount.types';
import type { CurrencyItem } from '@/modules/accounting/types/catalogs.types';
import type {
  CompanyCurrencyItem,
  CompanyInfo,
  CompanySettingDetails,
  EconomicSector,
  LegalForm,
} from '../types/companySettings.types';

const props = defineProps<{
  readonly activeCompanyId: CompanyId;
  readonly getAccessToken: () => string | null;
}>();

const emit = defineEmits<{
  (e: 'company-updated', company: CompanyInfo): void;
}>();

const apiClient = computed(
  () =>
    new AccountingApiClient('', {
      getAccessToken: props.getAccessToken,
      getActiveCompanyId: () => props.activeCompanyId,
    }),
);

// Pestañas
type ActiveTab = 'currencies' | 'general' | 'tax' | 'staff';
const activeTab = shallowRef<ActiveTab>('currencies');

// Estados generales
const isLoading = shallowRef(false);
const isSaving = shallowRef(false);
const successMessage = shallowRef<string | null>(null);
const errorMessage = shallowRef<string | null>(null);

// Datos del formulario
const companyData = ref<CompanyInfo | null>(null);
const settingsData = ref<CompanySettingDetails | null>(null);
const allCurrencies = shallowRef<readonly CurrencyItem[]>([]);
const companyCurrencies = shallowRef<readonly CompanyCurrencyItem[]>([]);

// Campos editables
const formBusinessName = ref('');
const formLegalAddress = ref('');
const formTaxId = ref('');
const formBaseCurrencyId = ref<number>(1);
const formSecondaryCurrencyIds = ref<number[]>([2]);

// Parámetros tributarios
const formTaxRegime = ref<'GENERAL' | 'MYPE_TRIBUTARIO' | 'RER' | 'ESPECIAL' | 'NRUS'>('GENERAL');
const economicProfileReady = ref(false);
const formLegalForm = ref<LegalForm | ''>('');
interface EditingEconomicActivity {
  sector_code: EconomicSector;
  ciiu_code: string;
  description: string;
  is_primary: boolean;
}
const formEconomicActivities = ref<EditingEconomicActivity[]>([]);
const economicSectorOptions: readonly { code: EconomicSector; label: string }[] = [
  { code: 'COMERCIAL', label: 'Comercial / Distribución' },
  { code: 'SERVICIOS', label: 'Servicios' },
  { code: 'INDUSTRIAL', label: 'Industrial / Manufactura' },
  { code: 'AGRICOLA', label: 'Agricultura' },
  { code: 'GANADERIA', label: 'Ganadería' },
  { code: 'PESCA', label: 'Pesca' },
  { code: 'CONSTRUCCION', label: 'Construcción' },
  { code: 'EXTRACTIVA', label: 'Extractivas / Minería' },
  { code: 'TRANSPORTE_LOGISTICA', label: 'Transporte y Logística' },
  { code: 'OTROS', label: 'Otras actividades' },
];
const legalFormOptions: readonly { code: LegalForm; label: string }[] = [
  { code: 'PERSONA_NATURAL', label: 'Persona natural con negocio' },
  { code: 'EIRL', label: 'E.I.R.L.' },
  { code: 'SAC', label: 'S.A.C.' },
  { code: 'SRL', label: 'S.R.L.' },
  { code: 'SA', label: 'S.A.' },
  { code: 'SAA', label: 'S.A.A.' },
  { code: 'SACS', label: 'S.A.C.S.' },
  { code: 'OTRA', label: 'Otra forma jurídica' },
];

function addEconomicActivity(): void {
  if (formEconomicActivities.value.length >= 10) return;
  formEconomicActivities.value.push({
    sector_code: 'COMERCIAL',
    ciiu_code: '',
    description: '',
    is_primary: formEconomicActivities.value.length === 0,
  });
}

function setPrimaryEconomicActivity(index: number): void {
  formEconomicActivities.value.forEach((activity, i) => {
    activity.is_primary = index === i;
  });
}

function removeEconomicActivity(index: number): void {
  // El perfil configurado requiere como mínimo una actividad principal.
  if (formEconomicActivities.value.length <= 1) return;
  formEconomicActivities.value.splice(index, 1);
  if (formEconomicActivities.value.length > 0 &&
      !formEconomicActivities.value.some((activity) => activity.is_primary)) {
    formEconomicActivities.value[0]!.is_primary = true;
  }
}
const formDefaultVatRate = ref<number>(18.0);
const formIsRetentionAgent = ref(false);
const formIsPerceptionAgent = ref(false);
const formIsGoodTaxpayer = ref(false);
const formIsSireObliged = ref(true);
const formIsPleObliged = ref(false);

// Representantes
const formLegalRepName = ref('');
const formLegalRepDocCode = ref('1');
const formLegalRepIdNumber = ref('');
const formChiefAccountantName = ref('');
const formChiefAccountantCpc = ref('');

const baseCurrency = computed<CurrencyItem | undefined>(() =>
  allCurrencies.value.find((c) => c.id === formBaseCurrencyId.value),
);

const secondaryCurrencies = computed<readonly CurrencyItem[]>(() =>
  allCurrencies.value.filter((c) => formSecondaryCurrencyIds.value.includes(c.id)),
);

const availableSecondaryCurrencies = computed<readonly CurrencyItem[]>(() =>
  allCurrencies.value.filter((c) => c.id !== formBaseCurrencyId.value),
);

let latestLoadId = 0;

async function loadCompanyData(): Promise<void> {
  const loadId = ++latestLoadId;
  isLoading.value = true;
  errorMessage.value = null;

  try {
    const data = await apiClient.value.fetchCompanySettings();
    if (loadId !== latestLoadId) return;
    companyData.value = data.company;
    settingsData.value = data.settings;
    allCurrencies.value = data.all_currencies;
    companyCurrencies.value = data.company_currencies;

    // Llenar formulario
    formBusinessName.value = data.company.business_name;
    formLegalAddress.value = data.company.legal_address;
    formTaxId.value = data.company.tax_id;

    formBaseCurrencyId.value = data.settings.base_currency_id ?? 1;

    const secondaryIds = data.company_currencies
      .filter((cc) => !cc.is_base)
      .map((cc) => cc.currency_id);

    formSecondaryCurrencyIds.value =
      secondaryIds.length > 0 ? secondaryIds : [2]; // USD por defecto

    formTaxRegime.value = data.settings.tax_regime ?? 'GENERAL';
    economicProfileReady.value = data.economic_profile_ready ?? false;
    formLegalForm.value = data.settings.legal_form ?? '';
    formEconomicActivities.value = (data.economic_activities ?? []).map((activity) => ({
      sector_code: activity.sector_code,
      ciiu_code: activity.ciiu_code,
      description: activity.description,
      is_primary: activity.is_primary,
    }));
    formDefaultVatRate.value = parseFloat(data.settings.default_vat_rate) || 18.0;
    formIsRetentionAgent.value = data.settings.is_retention_agent ?? false;
    formIsPerceptionAgent.value = data.settings.is_perception_agent ?? false;
    formIsGoodTaxpayer.value = data.settings.is_good_taxpayer ?? false;
    formIsSireObliged.value = data.settings.is_sire_obliged ?? true;
    formIsPleObliged.value = data.settings.is_ple_obliged ?? false;

    formLegalRepName.value = data.settings.legal_representative_name ?? '';
    formLegalRepDocCode.value = data.settings.legal_rep_identity_doc_code ?? '1';
    formLegalRepIdNumber.value = data.settings.legal_representative_id_number ?? '';
    formChiefAccountantName.value = data.settings.chief_accountant_name ?? '';
    formChiefAccountantCpc.value = data.settings.chief_accountant_cpc_number ?? '';
  } catch (e) {
    if (loadId === latestLoadId) {
      errorMessage.value =
        e instanceof Error ? e.message : 'Error al cargar la configuración de la empresa.';
    }
  } finally {
    if (loadId === latestLoadId) isLoading.value = false;
  }
}

function toggleSecondaryCurrency(currencyId: number): void {
  const current = [...formSecondaryCurrencyIds.value];
  const idx = current.indexOf(currencyId);

  if (idx >= 0) {
    // Si ya está seleccionada y tiene más de 1 moneda, permitir desmarcar
    if (current.length > 1) {
      current.splice(idx, 1);
      formSecondaryCurrencyIds.value = current;
    } else {
      errorMessage.value =
        'La empresa debe trabajar con al menos una moneda secundaria además de la moneda base.';
    }
  } else {
    current.push(currencyId);
    formSecondaryCurrencyIds.value = current;
  }
}

function handleBaseCurrencyChange(newBaseId: number): void {
  formBaseCurrencyId.value = newBaseId;
  // Si la nueva moneda base estaba en las secundarias, quitarla
  formSecondaryCurrencyIds.value = formSecondaryCurrencyIds.value.filter((id) => id !== newBaseId);
  // Asegurar que al menos una moneda secundaria quede seleccionada
  if (formSecondaryCurrencyIds.value.length === 0) {
    const fallback = allCurrencies.value.find((c) => c.id !== newBaseId);
    if (fallback) {
      formSecondaryCurrencyIds.value = [fallback.id];
    }
  }
}

async function handleSaveSettings(): Promise<void> {
  errorMessage.value = null;
  successMessage.value = null;

  if (!formBusinessName.value.trim()) {
    errorMessage.value = 'Por favor ingrese la Razón Social de la empresa.';
    return;
  }

  if (!formTaxId.value.trim() || formTaxId.value.trim().length < 8) {
    errorMessage.value = 'El RUC debe tener al menos 8 caracteres válidos.';
    return;
  }

  if (formSecondaryCurrencyIds.value.length === 0) {
    errorMessage.value = 'Debe seleccionar al menos una moneda secundaria de trabajo.';
    return;
  }

  if (formTaxRegime.value === 'NRUS' && formLegalForm.value !== 'PERSONA_NATURAL') {
    errorMessage.value = 'El Nuevo RUS exige identificar una persona natural elegible.';
    return;
  }

  if (economicProfileReady.value && formEconomicActivities.value.length > 0) {
    const activities = formEconomicActivities.value;
    if (activities.filter((activity) => activity.is_primary).length !== 1 ||
        activities.some((activity) => !/^\d{4}$/.test(activity.ciiu_code) || !activity.description.trim()) ||
        new Set(activities.map((activity) => activity.ciiu_code)).size !== activities.length) {
      errorMessage.value = 'Registre CIIU de 4 dígitos, descripción y exactamente una actividad principal, sin duplicados.';
      return;
    }
  }

  isSaving.value = true;
  try {
    const payload = {
      business_name: formBusinessName.value.trim(),
      legal_address: formLegalAddress.value.trim(),
      tax_id: formTaxId.value.trim(),
      base_currency_id: formBaseCurrencyId.value,
      secondary_currency_ids: formSecondaryCurrencyIds.value,
      tax_regime: formTaxRegime.value,
      ...(economicProfileReady.value && activeTab.value === 'tax' ? {
        legal_form: formLegalForm.value || null,
        ...(formEconomicActivities.value.length > 0 ? {
          economic_activities: formEconomicActivities.value.map((activity) => ({
            sector_code: activity.sector_code,
            ciiu_code: activity.ciiu_code.trim(),
            description: activity.description.trim(),
            is_primary: activity.is_primary,
          })),
        } : {}),
      } : {}),
      default_vat_rate: formDefaultVatRate.value,
      is_retention_agent: formIsRetentionAgent.value,
      is_perception_agent: formIsPerceptionAgent.value,
      is_good_taxpayer: formIsGoodTaxpayer.value,
      is_sire_obliged: formIsSireObliged.value,
      is_ple_obliged: formIsPleObliged.value,
      legal_representative_name: formLegalRepName.value.trim() || undefined,
      legal_rep_identity_doc_code: formLegalRepDocCode.value || undefined,
      legal_representative_id_number: formLegalRepIdNumber.value.trim() || undefined,
      chief_accountant_name: formChiefAccountantName.value.trim() || undefined,
      chief_accountant_cpc_number: formChiefAccountantCpc.value.trim() || undefined,
    };

    const savingCompanyId = props.activeCompanyId;
    const response = await apiClient.value.updateCompanySettings(payload);
    if (savingCompanyId !== props.activeCompanyId) return;
    successMessage.value = '¡Parámetros de empresa y régimen de divisas actualizados con éxito!';
    companyData.value = response.data.company;
    settingsData.value = response.data.settings;
    companyCurrencies.value = response.data.company_currencies;
    if (response.data.economic_profile_ready) {
      economicProfileReady.value = true;
      formEconomicActivities.value = (response.data.economic_activities ?? []).map((activity) => ({
        sector_code: activity.sector_code,
        ciiu_code: activity.ciiu_code,
        description: activity.description,
        is_primary: activity.is_primary,
      }));
    }

    emit('company-updated', response.data.company);
  } catch (e) {
    errorMessage.value =
      e instanceof Error ? e.message : 'Error al guardar los cambios en la empresa.';
  } finally {
    isSaving.value = false;
  }
}

onMounted(() => {
  void loadCompanyData();
});

watch(() => props.activeCompanyId, () => {
  // Evita mostrar datos de otra empresa tras cambiar de tenant en el sidebar.
  companyData.value = null;
  settingsData.value = null;
  economicProfileReady.value = false;
  formEconomicActivities.value = [];
  formLegalForm.value = '';
  void loadCompanyData();
});
</script>

<template>
  <div class="space-y-6">
    <!-- Encabezado de Sección -->
    <div
      class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-line"
    >
      <div>
        <div class="flex items-center gap-2">
          <span
            class="w-8 h-8 rounded-md bg-brand/10 text-brand flex items-center justify-center shrink-0"
          >
            <Building2 class="w-4 h-4 text-brand" />
          </span>
          <h1 class="text-xl font-semibold tracking-tight text-foreground">
            Configuración de la Empresa · Parámetros y Régimen de Divisas
          </h1>
          <span class="tag ok font-mono text-[11px]">
            <ShieldCheck class="w-3 h-3 inline mr-1" />
            ISO 27001 Multi-Tenant
          </span>
        </div>
        <p class="text-xs text-muted mt-1">
          Régimen bimoneda/multimoneda, moneda funcional base, tipos de cambio obligatorios por fecha y parámetros fiscales SUNAT 2026.
        </p>
      </div>

      <!-- Conmutador de Pestañas -->
      <div class="inline-flex p-1 bg-soft rounded-md border border-line overflow-x-auto">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer whitespace-nowrap"
          :class="
            activeTab === 'currencies'
              ? 'bg-brand text-white shadow-xs font-semibold'
              : 'text-muted hover:text-foreground'
          "
          @click="activeTab = 'currencies'"
        >
          <Coins class="w-3.5 h-3.5" />
          <span>Régimen de Divisas ({{ secondaryCurrencies.length + 1 }})</span>
        </button>

        <button
          type="button"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer whitespace-nowrap"
          :class="
            activeTab === 'general'
              ? 'bg-brand text-white shadow-xs font-semibold'
              : 'text-muted hover:text-foreground'
          "
          @click="activeTab = 'general'"
        >
          <Building2 class="w-3.5 h-3.5" />
          <span>Datos Generales</span>
        </button>

        <button
          type="button"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer whitespace-nowrap"
          :class="
            activeTab === 'tax'
              ? 'bg-brand text-white shadow-xs font-semibold'
              : 'text-muted hover:text-foreground'
          "
          @click="activeTab = 'tax'"
        >
          <FileSpreadsheet class="w-3.5 h-3.5" />
          <span>Tributario & SIRE</span>
        </button>

        <button
          type="button"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer whitespace-nowrap"
          :class="
            activeTab === 'staff'
              ? 'bg-brand text-white shadow-xs font-semibold'
              : 'text-muted hover:text-foreground'
          "
          @click="activeTab = 'staff'"
        >
          <UserCheck class="w-3.5 h-3.5" />
          <span>Representante & CPC</span>
        </button>
      </div>
    </div>

    <!-- Alertas Generales -->
    <div
      v-if="successMessage"
      class="p-4 rounded-md bg-emerald-500/10 border border-emerald-500/30 text-emerald-700 dark:text-emerald-300 flex items-center justify-between text-xs font-medium"
      role="alert"
    >
      <div class="flex items-center gap-2">
        <CheckCircle2 class="w-4 h-4 text-emerald-500 shrink-0" />
        <span>{{ successMessage }}</span>
      </div>
      <button
        type="button"
        class="text-emerald-600 hover:text-emerald-800 font-bold"
        @click="successMessage = null"
      >
        ✕
      </button>
    </div>

    <div
      v-if="errorMessage"
      class="p-4 rounded-md bg-rose-500/10 border border-rose-500/30 text-rose-700 dark:text-rose-300 flex items-center justify-between text-xs font-medium"
      role="alert"
    >
      <div class="flex items-center gap-2">
        <AlertTriangle class="w-4 h-4 text-rose-500 shrink-0" />
        <span>{{ errorMessage }}</span>
      </div>
      <button
        type="button"
        class="text-rose-600 hover:text-rose-800 font-bold"
        @click="errorMessage = null"
      >
        ✕
      </button>
    </div>

    <div v-if="isLoading" class="p-12 text-center">
      <Loader2 class="w-8 h-8 text-brand animate-spin mx-auto mb-2" />
      <p class="text-xs text-muted">Cargando parámetros de la empresa...</p>
    </div>

    <div v-else class="space-y-6">
      <!-- ======================================================== -->
      <!-- PESTAÑA 1: CONFIGURACIÓN DE DIVISAS / MULTIMONEDA        -->
      <!-- ======================================================== -->
      <div v-if="activeTab === 'currencies'" class="space-y-5">
        <!-- Tarjeta Informativa de la Regla de Negocio -->
        <div class="panel bg-surf border border-line p-5 space-y-3">
          <div class="flex items-start gap-3">
            <span class="w-8 h-8 rounded-md bg-brand/10 text-brand flex items-center justify-center shrink-0 mt-0.5">
              <Coins class="w-4 h-4 text-brand" />
            </span>
            <div class="space-y-1">
              <h2 class="text-sm font-semibold text-foreground">
                Régimen Bimoneda y Multimoneda de la Empresa
              </h2>
              <p class="text-xs text-muted leading-relaxed">
                Toda empresa inicia por defecto con <strong>PEN (Soles Peruanos)</strong> como moneda base y debe seleccionar
                la segunda moneda con la que va a trabajar (ej. USD). Si la empresa escoge más de 2 monedas (ej. USD y EUR),
                el sistema exigirá <strong>obligatoriamente el tipo de cambio diario para cada una de las monedas secundarias</strong> en la fecha en que se registre cada asiento contable.
              </p>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
          <!-- 1. Selección de Moneda Base / Principal -->
          <div class="panel space-y-4">
            <div class="border-b border-line pb-3 flex items-center justify-between">
              <span class="text-xs font-semibold uppercase tracking-wider text-muted flex items-center gap-2">
                <Globe class="w-4 h-4 text-brand" />
                1. Moneda Base Principal (Funcional)
              </span>
              <span class="tag ok font-mono text-[10px]">
                Moneda Primaria
              </span>
            </div>

            <p class="text-xs text-muted">
              Moneda en la que se formulan los Estados Financieros oficiales. Por defecto es <strong>PEN</strong>, pero si la entidad opera formalmente en otra moneda, puede seleccionarla aquí:
            </p>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5 pt-1">
              <div
                v-for="curr in allCurrencies"
                :key="curr.id"
                class="rounded-md border p-3 cursor-pointer transition-all flex flex-col justify-between"
                :class="
                  formBaseCurrencyId === curr.id
                    ? 'border-brand bg-brand/5 shadow-[inset_3px_0_0_var(--brand)] font-semibold'
                    : 'border-line bg-surf hover:bg-soft/70'
                "
                @click="handleBaseCurrencyChange(curr.id)"
              >
                <div class="flex items-center justify-between">
                  <span class="font-mono text-sm font-bold text-foreground">{{ curr.code }}</span>
                  <span class="tag font-mono text-[10px]">{{ curr.symbol }}</span>
                </div>
                <div class="mt-2">
                  <p class="text-xs text-foreground truncate">{{ curr.name }}</p>
                  <p class="text-[10px] text-muted font-mono mt-0.5">SUNAT: Tabla 4 ({{ curr.sunat_table_04_code ?? '—' }})</p>
                </div>
                <div class="mt-2 pt-2 border-t border-line text-[10px]">
                  <span v-if="formBaseCurrencyId === curr.id" class="text-pos font-bold">
                    ✓ Moneda Base Activa
                  </span>
                  <span v-else class="text-muted">
                    Clic para seleccionar
                  </span>
                </div>
              </div>
            </div>
          </div>

          <!-- 2. Selección de Monedas Secundarias / De Trabajo -->
          <div class="panel space-y-4">
            <div class="border-b border-line pb-3 flex items-center justify-between">
              <span class="text-xs font-semibold uppercase tracking-wider text-muted flex items-center gap-2">
                <Coins class="w-4 h-4 text-accent" />
                2. Monedas Secundarias de Trabajo (Mínimo 1)
              </span>
              <span
                class="tag font-mono text-[10px]"
                :class="formSecondaryCurrencyIds.length > 1 ? 'wa' : 'ok'"
              >
                {{ formSecondaryCurrencyIds.length > 1 ? 'Multimoneda (>2)' : 'Bimoneda' }}
              </span>
            </div>

            <p class="text-xs text-muted">
              Seleccione las monedas extranjeras o complementarias con las que transacciona la empresa.
              Cada una exigirá tipo de cambio obligatorio:
            </p>

            <div class="space-y-2 pt-1">
              <div
                v-for="curr in availableSecondaryCurrencies"
                :key="curr.id"
                class="rounded-md border p-3 transition-all cursor-pointer flex items-center justify-between"
                :class="
                  formSecondaryCurrencyIds.includes(curr.id)
                    ? 'border-accent bg-accent/5 shadow-[inset_3px_0_0_var(--accent)]'
                    : 'border-line bg-surf hover:bg-soft/70'
                "
                @click="toggleSecondaryCurrency(curr.id)"
              >
                <div class="flex items-center gap-3">
                  <input
                    type="checkbox"
                    :checked="formSecondaryCurrencyIds.includes(curr.id)"
                    class="w-4 h-4 rounded text-accent border-line focus:ring-accent"
                    @click.stop
                    @change="toggleSecondaryCurrency(curr.id)"
                  />
                  <div>
                    <div class="flex items-center gap-2">
                      <strong class="font-mono text-sm text-foreground">{{ curr.code }}</strong>
                      <span class="text-xs text-muted">({{ curr.symbol }})</span>
                      <span class="text-xs text-foreground">· {{ curr.name }}</span>
                    </div>
                    <p class="text-[11px] text-muted mt-0.5">
                      Requiere TC compra/venta obligatorio al contabilizar asientos.
                    </p>
                  </div>
                </div>

                <span
                  class="tag font-mono text-[10px]"
                  :class="formSecondaryCurrencyIds.includes(curr.id) ? 'wa font-bold' : 'text-muted border-line'"
                >
                  {{ formSecondaryCurrencyIds.includes(curr.id) ? 'Obligatorio en Asientos' : 'Inactivo' }}
                </span>
              </div>
            </div>

            <!-- Resumen de Monedas Operativas -->
            <div class="rounded-md bg-soft p-3 border border-line text-xs space-y-1">
              <div class="flex items-center justify-between text-muted">
                <span>Régimen configurado:</span>
                <strong class="text-foreground font-mono">
                  {{ baseCurrency?.code }} + [{{ secondaryCurrencies.map((c) => c.code).join(', ') }}]
                </strong>
              </div>
              <p class="text-[11px] text-muted">
                Total de divisas activas: <strong>{{ secondaryCurrencies.length + 1 }}</strong>.
                {{
                  secondaryCurrencies.length > 1
                    ? 'Al registrar un asiento, el sistema requerirá los tipos de cambio de ' +
                      secondaryCurrencies.map((c) => c.code).join(' y ') +
                      '.'
                    : 'Se requerirá el tipo de cambio de ' + secondaryCurrencies[0]?.code + '.'
                }}
              </p>
            </div>
          </div>
        </div>

        <!-- Enlace al módulo de Tipos de Cambio Diarios -->
        <div class="p-3.5 rounded-lg bg-soft border border-line flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs">
          <div class="flex items-center gap-2 text-muted">
            <Coins class="w-4 h-4 text-brand shrink-0" />
            <span>
              Los <strong>Tipos de Cambio Diarios</strong> oficiales (compra y venta por fecha) se gestionan en su módulo operativo dedicado.
            </span>
          </div>
          <a
            href="#tipo-cambio"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md font-semibold text-brand bg-surf border border-line hover:bg-soft transition-colors shrink-0"
          >
            <span>Ir a Tipos de Cambio Diarios →</span>
          </a>
        </div>

        <!-- Botón Guardar Monedas -->
        <div class="flex justify-end pt-2">
          <button
            type="button"
            :disabled="isSaving"
            class="inline-flex items-center gap-2 px-5 py-2.5 rounded-md text-xs font-semibold text-white bg-brand hover:opacity-95 disabled:opacity-50 transition-all cursor-pointer"
            @click="handleSaveSettings"
          >
            <Loader2 v-if="isSaving" class="w-4 h-4 animate-spin" />
            <Save v-else class="w-4 h-4" />
            <span>{{ isSaving ? 'Guardando Parámetros...' : 'Guardar Régimen de Divisas' }}</span>
          </button>
        </div>
      </div>

      <!-- ======================================================== -->
      <!-- PESTAÑA 2: DATOS GENERALES DE LA EMPRESA                 -->
      <!-- ======================================================== -->
      <div v-else-if="activeTab === 'general'" class="space-y-4">
        <div class="panel space-y-4">
          <div class="border-b border-line pb-3">
            <span class="text-xs font-semibold uppercase tracking-wider text-muted flex items-center gap-2">
              <Building2 class="w-4 h-4 text-brand" />
              Datos de Identificación de la Entidad
            </span>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label class="block text-xs font-semibold text-muted mb-1">
                Número de RUC (Tax ID)
              </label>
              <input
                v-model="formTaxId"
                type="text"
                maxlength="20"
                class="w-full px-3 py-2 rounded-md text-xs num font-semibold bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
              />
            </div>

            <div class="md:col-span-2">
              <label class="block text-xs font-semibold text-muted mb-1">
                Razón Social / Denominación Comercial
              </label>
              <input
                v-model="formBusinessName"
                type="text"
                maxlength="200"
                class="w-full px-3 py-2 rounded-md text-xs bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand font-medium"
              />
            </div>
          </div>

          <div>
            <label class="block text-xs font-semibold text-muted mb-1">
              Dirección Legal / Domicilio Fiscal
            </label>
            <input
              v-model="formLegalAddress"
              type="text"
              maxlength="300"
              class="w-full px-3 py-2 rounded-md text-xs bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
            />
          </div>

          <div class="flex justify-end pt-2">
            <button
              type="button"
              :disabled="isSaving"
              class="inline-flex items-center gap-2 px-5 py-2.5 rounded-md text-xs font-semibold text-white bg-brand hover:opacity-95 disabled:opacity-50 transition-all cursor-pointer"
              @click="handleSaveSettings"
            >
              <Loader2 v-if="isSaving" class="w-4 h-4 animate-spin" />
              <Save v-else class="w-4 h-4" />
              <span>{{ isSaving ? 'Guardando...' : 'Guardar Datos Generales' }}</span>
            </button>
          </div>
        </div>
      </div>

      <!-- ======================================================== -->
      <!-- PESTAÑA 4: PARÁMETROS TRIBUTARIOS Y SIRE                -->
      <!-- ======================================================== -->
      <div v-else-if="activeTab === 'tax'" class="space-y-4">
        <div class="panel space-y-4">
          <div class="border-b border-line pb-3">
            <span class="text-xs font-semibold uppercase tracking-wider text-muted flex items-center gap-2">
              <FileSpreadsheet class="w-4 h-4 text-brand" />
              Parámetros Fiscales SUNAT & Régimen del IGV
            </span>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-semibold text-muted mb-1">
                Régimen Tributario SUNAT
              </label>
              <select
                v-model="formTaxRegime"
                class="w-full px-3 py-2 rounded-md text-xs bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand font-medium"
              >
                <option value="GENERAL">Régimen General (PRICO / MEPECO)</option>
                <option value="NRUS">Nuevo RUS — solo persona natural elegible</option>
                <option value="MYPE_TRIBUTARIO">Régimen MYPE Tributario</option>
                <option value="RER">Régimen Especial de Renta (RER)</option>
                <option value="ESPECIAL">Otros Regímenes Especiales</option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-semibold text-muted mb-1">
                Tasa de IGV Predeterminada (%)
              </label>
              <input
                v-model.number="formDefaultVatRate"
                type="number"
                step="0.01"
                min="0"
                max="100"
                class="w-full px-3 py-2 rounded-md text-xs num font-semibold bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
              />
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3 pt-2">
            <label class="flex items-center gap-2.5 p-3 rounded-md border border-line bg-surf cursor-pointer hover:bg-soft/50">
              <input v-model="formIsRetentionAgent" type="checkbox" class="w-4 h-4 rounded text-brand border-line" />
              <span class="text-xs text-foreground font-medium">Agente de Retención IGV</span>
            </label>

            <label class="flex items-center gap-2.5 p-3 rounded-md border border-line bg-surf cursor-pointer hover:bg-soft/50">
              <input v-model="formIsPerceptionAgent" type="checkbox" class="w-4 h-4 rounded text-brand border-line" />
              <span class="text-xs text-foreground font-medium">Agente de Percepción IGV</span>
            </label>

            <label class="flex items-center gap-2.5 p-3 rounded-md border border-line bg-surf cursor-pointer hover:bg-soft/50">
              <input v-model="formIsGoodTaxpayer" type="checkbox" class="w-4 h-4 rounded text-brand border-line" />
              <span class="text-xs text-foreground font-medium">Buen Contribuyente SUNAT</span>
            </label>

            <label class="flex items-center gap-2.5 p-3 rounded-md border border-line bg-surf cursor-pointer hover:bg-soft/50">
              <input v-model="formIsSireObliged" type="checkbox" class="w-4 h-4 rounded text-brand border-line" />
              <span class="text-xs text-foreground font-medium">Obligado SIRE (RVIE / RCE)</span>
            </label>

            <label class="flex items-center gap-2.5 p-3 rounded-md border border-line bg-surf cursor-pointer hover:bg-soft/50">
              <input v-model="formIsPleObliged" type="checkbox" class="w-4 h-4 rounded text-brand border-line" />
              <span class="text-xs text-foreground font-medium">Libros Electrónicos PLE</span>
            </label>
          </div>

          <!-- Perfil económico: habilitado únicamente tras migración Laravel F3. -->
          <section class="rounded-lg border border-line p-4 space-y-4" aria-label="Perfil económico empresarial">
            <div class="flex flex-col gap-1">
              <h3 class="text-sm font-semibold text-foreground">Naturaleza jurídica y actividades económicas</h3>
              <p class="text-xs text-muted">
                Clasificación independiente del régimen tributario. La actividad principal se identifica
                con su código CIIU de cuatro dígitos y puede tener actividades secundarias.
              </p>
            </div>

            <div v-if="!economicProfileReady" class="flex items-start gap-2 text-xs text-muted border border-line rounded-md p-3 bg-soft">
              <AlertTriangle class="w-4 h-4 shrink-0" />
              <span>
                Perfil pendiente de habilitación. Después de ejecutar las migraciones Laravel,
                esta sección permitirá guardar actividades y sectores. Los demás parámetros
                pueden seguir modificándose.
              </span>
            </div>

            <template v-else>
              <div>
                <label class="block text-xs font-semibold text-muted mb-1" for="legal-form">
                  Forma jurídica de la empresa
                </label>
                <select
                  id="legal-form"
                  v-model="formLegalForm"
                  class="w-full px-3 py-2 rounded-md text-xs bg-surf border border-line text-foreground"
                >
                  <option value="">Seleccionar forma jurídica</option>
                  <option v-for="form in legalFormOptions" :key="form.code" :value="form.code">
                    {{ form.label }}
                  </option>
                </select>
              </div>

              <div v-for="(activity, index) in formEconomicActivities" :key="index"
                class="rounded-md border border-line bg-surf p-3 space-y-3">
                <div class="flex justify-between items-center gap-2">
                  <label class="flex items-center gap-2 text-xs font-semibold text-foreground">
                    <input
                      type="radio"
                      name="economic-activity-primary"
                      :checked="activity.is_primary"
                      @change="setPrimaryEconomicActivity(index)"
                    />
                    Actividad {{ index + 1 }} {{ activity.is_primary ? '(principal)' : '(secundaria)' }}
                  </label>
                  <button
                    type="button"
                    :disabled="formEconomicActivities.length <= 1"
                    class="text-xs text-muted underline hover:text-foreground disabled:opacity-40 disabled:cursor-not-allowed"
                    @click="removeEconomicActivity(index)"
                  >
                    Quitar
                  </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <div>
                    <label class="block text-xs text-muted mb-1">Sector económico</label>
                    <select
                      v-model="activity.sector_code"
                      class="w-full px-3 py-2 rounded-md text-xs bg-soft border border-line text-foreground"
                    >
                      <option v-for="sector in economicSectorOptions" :key="sector.code" :value="sector.code">
                        {{ sector.label }}
                      </option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-xs text-muted mb-1">CIIU Rev. 4 (cuatro dígitos)</label>
                    <input
                      v-model="activity.ciiu_code"
                      inputmode="numeric"
                      maxlength="4"
                      placeholder="Ej. 4752"
                      class="w-full px-3 py-2 rounded-md text-xs bg-soft border border-line text-foreground"
                    />
                  </div>
                </div>
                <div>
                  <label class="block text-xs text-muted mb-1">Descripción de la actividad</label>
                  <input
                    v-model="activity.description"
                    maxlength="200"
                    placeholder="Ej. Venta minorista de productos para ferretería y pintura"
                    class="w-full px-3 py-2 rounded-md text-xs bg-soft border border-line text-foreground"
                  />
                </div>
              </div>

              <button
                type="button"
                :disabled="formEconomicActivities.length >= 10"
                class="inline-flex gap-2 items-center rounded-md border border-line px-3 py-2 text-xs text-foreground hover:bg-soft disabled:opacity-50"
                @click="addEconomicActivity"
              >
                <span class="font-bold text-base">+</span> Agregar actividad económica
              </button>
              <p class="text-xs text-muted">
                Esta clasificación no determina automáticamente libros ni beneficios tributarios.
                La evaluación de ingresos, UIT y obligatoriedad SUNAT está programada para fases 5 y 6.
              </p>
            </template>
          </section>

          <div class="flex justify-end pt-2">
            <button
              type="button"
              :disabled="isSaving"
              class="inline-flex items-center gap-2 px-5 py-2.5 rounded-md text-xs font-semibold text-white bg-brand hover:opacity-95 disabled:opacity-50 transition-all cursor-pointer"
              @click="handleSaveSettings"
            >
              <Loader2 v-if="isSaving" class="w-4 h-4 animate-spin" />
              <Save v-else class="w-4 h-4" />
              <span>{{ isSaving ? 'Guardando...' : 'Guardar Parámetros Fiscales' }}</span>
            </button>
          </div>
        </div>
      </div>

      <!-- ======================================================== -->
      <!-- PESTAÑA 5: REPRESENTANTE LEGAL Y CONTADOR CPC           -->
      <!-- ======================================================== -->
      <div v-else-if="activeTab === 'staff'" class="space-y-4">
        <div class="panel space-y-4">
          <div class="border-b border-line pb-3">
            <span class="text-xs font-semibold uppercase tracking-wider text-muted flex items-center gap-2">
              <UserCheck class="w-4 h-4 text-brand" />
              Responsables y Firmantes de los Libros Contables
            </span>
          </div>

          <!-- Representante Legal -->
          <div class="space-y-3">
            <h3 class="text-xs font-bold text-foreground uppercase tracking-wide">
              Representante Legal
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
              <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-muted mb-1">Nombre y Apellidos</label>
                <input
                  v-model="formLegalRepName"
                  type="text"
                  placeholder="Ej: Carlos Mendoza Alarcón"
                  class="w-full px-3 py-2 rounded-md text-xs bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
                />
              </div>
              <div>
                <label class="block text-xs font-semibold text-muted mb-1">N.° Documento (DNI/CE)</label>
                <input
                  v-model="formLegalRepIdNumber"
                  type="text"
                  placeholder="40123456"
                  class="w-full px-3 py-2 rounded-md text-xs num bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
                />
              </div>
            </div>
          </div>

          <div class="border-t border-line pt-3 space-y-3">
            <h3 class="text-xs font-bold text-foreground uppercase tracking-wide">
              Contador General Responsable
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
              <div class="md:col-span-2">
                <label class="block text-xs font-semibold text-muted mb-1">Nombre Completo del Contador</label>
                <input
                  v-model="formChiefAccountantName"
                  type="text"
                  placeholder="Ej: María Elena Ramos Morales"
                  class="w-full px-3 py-2 rounded-md text-xs bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
                />
              </div>
              <div>
                <label class="block text-xs font-semibold text-muted mb-1">Matrícula CPC</label>
                <input
                  v-model="formChiefAccountantCpc"
                  type="text"
                  placeholder="CPC-45892"
                  class="w-full px-3 py-2 rounded-md text-xs font-mono font-semibold bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
                />
              </div>
            </div>
          </div>

          <div class="flex justify-end pt-2">
            <button
              type="button"
              :disabled="isSaving"
              class="inline-flex items-center gap-2 px-5 py-2.5 rounded-md text-xs font-semibold text-white bg-brand hover:opacity-95 disabled:opacity-50 transition-all cursor-pointer"
              @click="handleSaveSettings"
            >
              <Loader2 v-if="isSaving" class="w-4 h-4 animate-spin" />
              <Save v-else class="w-4 h-4" />
              <span>{{ isSaving ? 'Guardando...' : 'Guardar Representantes' }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
