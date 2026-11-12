<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { AlertTriangle, ArrowLeftRight, BookOpenCheck, FilePlus2, RefreshCw, Settings2 } from 'lucide-vue-next';
import { toast } from 'vue-sonner';
import { useForm } from 'vee-validate';
import { toTypedSchema } from '@vee-validate/zod';
import DocumentPostingConfirmDialog from '../components/DocumentPostingConfirmDialog.vue';
import { createDocumentJournalDraftSchema, type DocumentJournalDraftValues } from '../schemas/documentJournalDraft.schema';
import UiSelect from '@/shared/ui/UiSelect.vue';
import { AccountingApiClient } from '../api/httpClient';
import { isDocumentFxReady } from '../api/documentJournalFx';
import type { CompanyId } from '../types/accountingAccount.types';
import type { JournalEditorAccount } from '../types/journalEntry.types';
import type { CashFlowAreaItem, CompanyAreaItem, CostCenterItem } from '../types/catalogs.types';
import { TAX_CATEGORY_LABELS } from '../types/documentJournal.types';
import type { CompanyExchangeRatesResponse, RequiredExchangeRateItem } from '@/modules/company/types/companySettings.types';
import type {
  DocumentJournalModule, DocumentJournalSettings,
  DocumentJournalEntity, DocumentJournalPage,
  TaxCategory, TaxAllocationInput, TaxRegisterPage, TaxRegisterSummary,
} from '../types/documentJournal.types';

const props = defineProps<{
  readonly module: DocumentJournalModule;
  readonly activeCompanyId: CompanyId;
  readonly getAccessToken: () => string | null;
  readonly canConfigure: boolean;
  readonly canPost: boolean;
}>();
const emit = defineEmits<{ (e: 'posting-change', value: boolean): void }>();

const api = new AccountingApiClient('', {
  getAccessToken: props.getAccessToken,
  getActiveCompanyId: () => props.activeCompanyId,
});
const isPurchase = computed(() => props.module === 'purchases');
const title = computed(() => isPurchase.value ? 'Registro de Compras' : 'Registro de Ventas');
const tab = ref<'register' | 'tax' | 'settings' | 'history'>('register');
const loading = ref(true);
const saving = ref(false);
const posting = ref(false);
const error = ref('');
const success = ref('');
const confirmOpen = ref(false);
const fxLoading = ref(false);
const fxError = ref('');
const fxSnapshot = ref<CompanyExchangeRatesResponse | null>(null);
let fxRequestId = 0;
const settings = ref<DocumentJournalSettings | null>(null);
const accounts = ref<readonly JournalEditorAccount[]>([]);
const subdiaries = ref<readonly { code: string; name: string }[]>([]);
const costCenters = ref<readonly CostCenterItem[]>([]);
const companyAreas = ref<readonly CompanyAreaItem[]>([]);
const cashFlowAreas = ref<readonly CashFlowAreaItem[]>([]);
const entities = ref<readonly DocumentJournalEntity[]>([]);
const currencies = ref<readonly string[]>(['PEN']);
const history = ref<DocumentJournalPage | null>(null);
const entityQuery = ref('');
const sections = ['register', 'tax', 'history', 'settings'] as const;
const taxRegister = ref<TaxRegisterPage | null>(null);
const taxSummary = ref<TaxRegisterSummary | null>(null);
const taxLoading = ref(false);
const taxPeriodFilter = ref(todayLocalMonth());
const purchaseTaxCategories = [
  'P_TAXED_EXCLUSIVE', 'P_TAXED_MIXED', 'P_TAXED_NON_TAXED',
  'P_TAXED_NO_CREDIT', 'P_NON_TAXED',
] as const;
const saleTaxCategories = [
  'S_TAXED', 'S_EXONERATED', 'S_UNAFFECTED', 'S_EXPORT',
] as const;
const categoryOptions = computed(() => (isPurchase.value ? purchaseTaxCategories : saleTaxCategories)
  .map(category => ({ value: category as TaxCategory, label: TAX_CATEGORY_LABELS[category] })));
const categoryAccountMap = reactive<Record<string, string>>({});
const categoryAccountOptions = computed(() => [
  { value: '', label: 'Usar cuenta principal por defecto' },
  ...accounts.value.map(a => ({ value: String(a.id), label: a.code + ' · ' + a.name })),
]);
interface TaxAllocationDraft {
  category: TaxCategory;
  base_amount: string;
  tax_amount: string;
  creditable_tax_amount: string;
}
const taxAllocations = ref<TaxAllocationDraft[]>([{
  category: props.module === 'purchases' ? 'P_TAXED_EXCLUSIVE' : 'S_TAXED',
  base_amount: '', tax_amount: '0.00', creditable_tax_amount: '0.00',
}]);
function todayLocalMonth(): string {
  return todayLocal().slice(0, 7);
}


function todayLocal(): string {
  const date = new Date();
  const n = (value: number) => String(value).padStart(2, '0');
  return date.getFullYear() + '-' + n(date.getMonth() + 1) + '-' + n(date.getDate());
}

const form = reactive({
  subdiary_code: '',
  main_account_id: '',
  tax_account_id: '',
  settlement_account_id: '',
  default_tax_rate: '18.0000',
});
const draft = reactive({
  document_type: '01' as '01' | '03',
  series: '',
  number: '',
  entity_id: '',
  issue_date: todayLocal(),
  entry_date: todayLocal(),
  currency: 'PEN',
  tax_period: todayLocalMonth(),
  due_date: '',
  cost_center_id: '',
  company_area_id: '',
  cash_flow_area_id: '',
  description: '',
});

// VeeValidate v4 + Zod validan el comprobante y cada clasificación antes del POST.
// El backend conserva el control final de tipo de cambio, periodo, RLS y partida doble.
const { validate, setValues, errors: validationErrors } = useForm<DocumentJournalDraftValues>({
  validationSchema: toTypedSchema(createDocumentJournalDraftSchema(props.module)),
});
function validationSnapshot(): DocumentJournalDraftValues {
  return {
    ...draft,
    tax_allocations: taxAllocations.value.map(row => ({
      category: row.category,
      base_amount: String(row.base_amount ?? ''),
      tax_amount: String(row.tax_amount ?? ''),
      creditable_tax_amount: String(row.creditable_tax_amount ?? '0'),
    })),
  };
}

async function refreshFx(): Promise<void> {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(draft.entry_date)) {
    fxSnapshot.value = null;
    fxError.value = 'Seleccione una fecha contable válida para consultar el tipo de cambio.';
    return;
  }
  const requestId = ++fxRequestId;
  const expectedDate = draft.entry_date;
  const expectedCompany = props.activeCompanyId;
  fxLoading.value = true;
  fxError.value = '';
  fxSnapshot.value = null;
  try {
    const fresh = await api.fetchCompanyExchangeRates(expectedDate);
    if (requestId !== fxRequestId || expectedDate !== draft.entry_date ||
      expectedCompany !== props.activeCompanyId) return;
    if (fresh.rate_date !== expectedDate) throw new Error('La fecha del tipo de cambio no coincide con la fecha contable.');
    fxSnapshot.value = fresh;
  } catch (e) {
    if (requestId === fxRequestId) {
      fxSnapshot.value = null;
      fxError.value = e instanceof Error ? e.message : 'No se pudo consultar el tipo de cambio.';
    }
  } finally {
    if (requestId === fxRequestId) fxLoading.value = false;
  }
}
watch(() => [draft.entry_date, props.activeCompanyId], () => {
  void refreshFx();
}, { flush: 'sync' });



const accountOptions = computed(() => [
  { value: '', label: 'Seleccione una cuenta contable' },
  ...accounts.value.map(a => ({ value: String(a.id), label: a.code + ' · ' + a.name })),
]);
const taxAccountOptions = computed(() => [
  { value: '', label: 'Sin cuenta tributaria (operaciones no gravadas)' },
  ...accounts.value.map(a => ({ value: String(a.id), label: a.code + ' · ' + a.name })),
]);
const subdiaryOptions = computed(() => subdiaries.value.map(s => ({
  value: s.code, label: s.code + ' · ' + s.name,
})));
const entityOptions = computed(() => [
  { value: '', label: 'Seleccione un tercero' },
  ...entities.value.map(e => ({ value: String(e.id), label: e.tax_id + ' · ' + e.legal_name })),
]);
const currencyOptions = computed(() => currencies.value.map(code => ({ value: code, label: code })));
const centersOptions = computed(() => [
  { value: '', label: 'Sin centro de costo' },
  ...costCenters.value.map(c => ({ value: String(c.id), label: c.code + ' · ' + c.name })),
]);
const companyAreaOptions = computed(() => [
  { value: '', label: 'Sin área de empresa' },
  ...companyAreas.value.map(c => ({ value: String(c.id), label: c.code + ' · ' + c.name })),
]);
const cashFlowOptions = computed(() => [
  { value: '', label: 'Sin área de flujo' },
  ...cashFlowAreas.value.map(c => ({ value: String(c.id), label: c.code + ' · ' + c.name })),
]);
const configured = computed(() => settings.value?.main_account_id != null &&
  settings.value.settlement_account_id != null);
const selectedMainAccounts = computed(() => taxAllocations.value.map(item => {
  const categoryId = settings.value?.category_account_map?.[item.category];
  const accountId = categoryId ?? settings.value?.main_account_id;
  return accounts.value.find(a => a.id === accountId);
}).filter((a): a is JournalEditorAccount => a !== undefined));
const needsCostCenter = computed(() => selectedMainAccounts.value.some(a => a.requires_cost_center || a.has_automatic_destination));
const needsCompanyArea = computed(() => selectedMainAccounts.value.some(a => a.requires_company_area));
const needsCashFlowArea = computed(() => selectedMainAccounts.value.some(a => a.requires_cash_flow_area));
const amountBase = computed(() => taxAllocations.value.reduce((sum, row) => sum + Number(row.base_amount || 0), 0));
const amountTax = computed(() => taxAllocations.value.reduce((sum, row) => sum + Number(row.tax_amount || 0), 0));
const amountTotal = computed(() => amountBase.value + amountTax.value);
const secondaryRates = computed(() => fxSnapshot.value?.required_currencies ?? []);
const fxReady = computed(() => !fxLoading.value && !fxError.value &&
  isDocumentFxReady(fxSnapshot.value, draft.entry_date));
const fxSourceLabel = (rate: RequiredExchangeRateItem): string =>
  rate.is_recorded_for_date ? 'Registrado para esta fecha'
    : rate.source === 'LATEST_HISTORICAL' ? 'Referencia histórica; no registrada para el día'
      : 'Valor predeterminado; no registrado para el día';
const formatRate = (rate: string) => Number.isFinite(Number(rate)) ? Number(rate).toFixed(6) : '—';

const isTaxedCategory = (category: TaxCategory): boolean =>
  ['P_TAXED_EXCLUSIVE', 'P_TAXED_MIXED', 'P_TAXED_NON_TAXED', 'P_TAXED_NO_CREDIT', 'S_TAXED'].includes(category);
const isPurchaseMixed = (category: TaxCategory): boolean => category === 'P_TAXED_MIXED';
const isPurchaseExclusive = (category: TaxCategory): boolean => category === 'P_TAXED_EXCLUSIVE';
const isPurchaseCreditCategory = (category: TaxCategory): boolean =>
  category === 'P_TAXED_MIXED' || category === 'P_TAXED_EXCLUSIVE';
const availableCategories = computed(() => categoryOptions.value
  .filter(option => !taxAllocations.value.some(row => row.category === option.value)));
function addTaxAllocation(): void {
  const category = availableCategories.value[0]?.value;
  if (!category) return;
  taxAllocations.value.push({ category, base_amount: '', tax_amount: '0.00', creditable_tax_amount: '0.00' });
}
function removeTaxAllocation(index: number): void {
  if (taxAllocations.value.length > 1) taxAllocations.value.splice(index, 1);
}
function taxCategoryChanged(row: TaxAllocationDraft): void {
  if (!isTaxedCategory(row.category)) row.tax_amount = '0.00';
  if (!isPurchaseMixed(row.category)) row.creditable_tax_amount = '0.00';
}

const money = (value: number) => Number.isFinite(value)
  ? new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)
  : '—';

function setForm(data: DocumentJournalSettings): void {
  settings.value = data;
  form.subdiary_code = data.subdiary_code;
  form.main_account_id = data.main_account_id == null ? '' : String(data.main_account_id);
  form.tax_account_id = data.tax_account_id == null ? '' : String(data.tax_account_id);
  form.settlement_account_id = data.settlement_account_id == null ? '' : String(data.settlement_account_id);
  form.default_tax_rate = data.default_tax_rate;
  for (const category of categoryOptions.value) {
    categoryAccountMap[category.value] = data.category_account_map?.[category.value] == null
      ? '' : String(data.category_account_map[category.value]);
  }
}

async function load(): Promise<void> {
  loading.value = true;
  error.value = '';
  try {
    const [cfg, bootstrap, company, documents, directory] = await Promise.all([
      api.fetchDocumentJournalSettings(props.module),
      api.fetchJournalEditorBootstrap(draft.entry_date),
      api.fetchCompanySettings(),
      api.fetchDocumentJournalRecords(props.module),
      api.findDocumentJournalEntities(props.module),
    ]);
    setForm(cfg);
    accounts.value = bootstrap.accounts;
    subdiaries.value = bootstrap.subdiaries;
    costCenters.value = bootstrap.cost_centers;
    companyAreas.value = bootstrap.company_areas;
    cashFlowAreas.value = bootstrap.cash_flow_areas;
    fxSnapshot.value = bootstrap.exchange_rates;
    fxError.value = '';
    history.value = documents;
    entities.value = directory.data;
    const codes = company.company_currencies
      .filter(c => c.is_active)
      .map(c => c.currency.code);
    currencies.value = codes.length ? codes : ['PEN'];
    if (!currencies.value.includes(draft.currency)) draft.currency = currencies.value[0] ?? 'PEN';
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'No se pudo cargar el módulo.';
  } finally {
    loading.value = false;
  }
}

async function searchEntities(): Promise<void> {
  error.value = '';
  try {
    entities.value = (await api.findDocumentJournalEntities(props.module, entityQuery.value.trim())).data;
    if (!entities.value.some(e => String(e.id) === draft.entity_id)) draft.entity_id = '';
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'No se pudieron consultar los terceros.';
  }
}

async function saveSettings(): Promise<void> {
  if (!props.canConfigure || saving.value) return;
  saving.value = true;
  error.value = '';
  success.value = '';
  try {
    if (!form.main_account_id || !form.settlement_account_id || !form.subdiary_code) {
      throw new Error('Configure subdiario, cuenta principal y contrapartida.');
    }
    const saved = await api.saveDocumentJournalSettings(props.module, {
      subdiary_code: form.subdiary_code,
      main_account_id: Number(form.main_account_id),
      tax_account_id: form.tax_account_id ? Number(form.tax_account_id) : null,
      settlement_account_id: Number(form.settlement_account_id),
      default_tax_rate: form.default_tax_rate,
      category_account_map: Object.fromEntries(
        Object.entries(categoryAccountMap).filter(([, value]) => value !== '')
          .map(([category, value]) => [category, Number(value)]),
      ),
    });
    setForm(saved);
    success.value = 'Parámetros guardados para esta empresa y este módulo.';
    toast.success(success.value);
    tab.value = 'register';
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'No se pudieron guardar los parámetros.';
  } finally {
    saving.value = false;
  }
}

function calculateTax(row: TaxAllocationDraft): void {
  if (!isTaxedCategory(row.category)) return;
  const base = Number(row.base_amount);
  const rate = Number(settings.value?.default_tax_rate ?? '0');
  if (Number.isFinite(base) && base > 0 && Number.isFinite(rate)) {
    row.tax_amount = (Math.round((base * rate / 100 + Number.EPSILON) * 100) / 100).toFixed(2);
  }
}

async function refreshTaxRegister(page = 1): Promise<void> {
  taxLoading.value = true;
  error.value = '';
  const period = taxPeriodFilter.value.replace('-', '');
  try {
    const [records, summary] = await Promise.all([
      api.fetchTaxRegister(props.module, period, page),
      api.fetchTaxRegisterSummary(props.module, period),
    ]);
    taxRegister.value = records;
    taxSummary.value = summary;
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'No se pudo cargar el registro tributario.';
  } finally {
    taxLoading.value = false;
  }
}
function selectTab(next: typeof sections[number]): void {
  tab.value = next;
  error.value = '';
  success.value = '';
  if (next === 'tax') void refreshTaxRegister();
}

async function refreshHistory(page = 1): Promise<void> {
  try {
    history.value = await api.fetchDocumentJournalRecords(props.module, page);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'No se pudo actualizar el registro.';
  }
}

async function preparePosting(): Promise<void> {
  if (!props.canPost || posting.value || !configured.value) return;
  error.value = '';
  setValues(validationSnapshot());
  const result = await validate();
  if (!result.valid) {
    toast.error('Revise los datos del comprobante.', {
      description: Object.values(result.errors)[0] ?? 'Complete los campos obligatorios.',
    });
    return;
  }
  if (needsCostCenter.value && !draft.cost_center_id) {
    toast.error('La cuenta de origen requiere un centro de costo.');
    return;
  }
  if (needsCompanyArea.value && !draft.company_area_id) {
    toast.error('La cuenta de origen requiere un área de empresa.');
    return;
  }
  if (needsCashFlowArea.value && !draft.cash_flow_area_id) {
    toast.error('La cuenta de origen requiere un área de flujo de efectivo.');
    return;
  }
  if (!Number.isFinite(amountTotal.value) || amountTotal.value <= 0) {
    toast.error('El total del comprobante debe ser mayor a cero.');
    return;
  }
  // La validación aquí es una vista previa: backend vuelve a comprobar la tasa
  // exacta en su transacción; nunca aceptar valores históricos ni presets.
  await refreshFx();
  if (!fxReady.value) {
    toast.warning('Falta registrar el tipo de cambio diario.', {
      description: 'El Libro Diario es bimoneda incluso cuando el comprobante está en PEN. Registre la tasa de la fecha antes de contabilizar.',
    });
    return;
  }
  confirmOpen.value = true;
}

async function postDocument(): Promise<void> {
  if (!confirmOpen.value || !props.canPost || posting.value || !configured.value) return;
  if (!fxReady.value) {
    confirmOpen.value = false;
    toast.error('La cotización diaria no está confirmada.');
    return;
  }
  confirmOpen.value = false;
  posting.value = true;
  emit('posting-change', true);
  error.value = '';
  success.value = '';
  try {
    const record = await api.postDocumentJournal(props.module, {
      document_type: draft.document_type,
      series: draft.series.trim().toUpperCase(),
      number: draft.number.trim().toUpperCase(),
      entity_id: Number(draft.entity_id),
      issue_date: draft.issue_date,
      entry_date: draft.entry_date,
      currency: draft.currency,
      tax_period: draft.tax_period.replace('-', ''),
      ...(draft.due_date ? { due_date: draft.due_date } : {}),
      tax_allocations: taxAllocations.value.map((row): TaxAllocationInput => ({
        category: row.category,
        base_amount: String(row.base_amount),
        tax_amount: String(row.tax_amount),
        creditable_tax_amount: isPurchaseExclusive(row.category)
          ? String(row.tax_amount)
          : isPurchaseMixed(row.category) ? String(row.creditable_tax_amount) : '0',
      })),
      ...(draft.cost_center_id ? { cost_center_id: Number(draft.cost_center_id) } : {}),
      ...(draft.company_area_id ? { company_area_id: Number(draft.company_area_id) } : {}),
      ...(draft.cash_flow_area_id ? { cash_flow_area_id: Number(draft.cash_flow_area_id) } : {}),
      ...(draft.description.trim() ? { description: draft.description.trim() } : {}),
    });
    success.value = 'Comprobante contabilizado. Voucher ' + (record.journal?.entry_number ?? record.journal_entry_id);
    toast.success('Comprobante registrado correctamente', { description: success.value });
    draft.series = '';
    draft.number = '';
    taxAllocations.value = [{ category: isPurchase.value ? 'P_TAXED_EXCLUSIVE' : 'S_TAXED',
      base_amount: '', tax_amount: '0.00', creditable_tax_amount: '0.00' }];
    draft.description = '';
    await refreshHistory();
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'No se pudo contabilizar el comprobante.';
    toast.error('No fue posible contabilizar.', { description: error.value });
    if (/tipo de cambio/i.test(error.value)) {
      // Puede haber cambiado el valor de la fecha en otro módulo.
      await refreshFx();
    }
  } finally {
    posting.value = false;
    emit('posting-change', false);
  }
}

onMounted(() => { void load(); });
</script>

<template>
  <div class="mx-auto max-w-7xl space-y-5 text-foreground">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <p class="text-xs font-semibold uppercase tracking-widest text-muted">Contabilidad / Comprobantes</p>
        <h2 class="mt-1 text-2xl font-semibold tracking-tight">{{ title }}</h2>
        <p class="mt-1 text-sm text-muted">Libro Diario contable + Registro tributario interno RCE/RVIE, diferenciados por clasificación SUNAT.</p>
      </div>
      <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-line bg-surface px-3 py-2 text-sm hover:bg-hover"
        :disabled="loading" @click="load">
        <RefreshCw class="size-4" :class="{ 'animate-spin': loading }" /> Actualizar
      </button>
    </header>

    <div v-if="error" role="alert" class="rounded-lg border border-danger/40 bg-surface p-3 text-sm text-danger">{{ error }}</div>
    <div v-if="loading" role="status" class="rounded-xl border border-line bg-surface p-8 text-sm text-muted">Cargando cuentas, subdiarios y comprobantes de la empresa…</div>
    <template v-else>
      <div class="flex flex-wrap gap-2 border-b border-line pb-3" role="tablist" :aria-label="title">
        <button v-for="t in sections" :key="t" type="button"
          role="tab" :aria-selected="tab === t"
          :class="['inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm',
            tab === t ? 'bg-brand/10 font-semibold text-foreground' : 'text-muted hover:bg-hover']"
          @click="selectTab(t)">
          <FilePlus2 v-if="t === 'register'" class="size-4" />
          <BookOpenCheck v-else-if="t === 'history' || t === 'tax'" class="size-4" />
          <Settings2 v-else class="size-4" />
          {{ t === 'register' ? 'Nuevo comprobante' : t === 'tax' ? 'Registro SUNAT interno' : t === 'history' ? 'Asientos generados' : 'Parámetros del módulo' }}
        </button>
      </div>

      <section v-if="tab === 'settings'" class="rounded-xl border border-line bg-surface p-4 sm:p-6">
        <h3 class="font-semibold">Configuración de {{ isPurchase ? 'Compras' : 'Ventas' }}</h3>
        <p class="mt-1 text-sm text-muted">Seleccione cuentas operativas del plan de cuentas activo. No se crean cuentas ni subdiarios automáticamente.</p>
        <div class="mt-5 grid gap-4 md:grid-cols-2">
          <label class="space-y-1.5 text-sm">
            <span class="font-medium">Subdiario</span>
            <UiSelect v-model="form.subdiary_code" :options="subdiaryOptions" :disabled="!canConfigure" searchable />
          </label>
          <label class="space-y-1.5 text-sm">
            <span class="font-medium">{{ isPurchase ? 'Cuenta de compra / gasto' : 'Cuenta de ingreso' }}</span>
            <UiSelect v-model="form.main_account_id" :options="accountOptions" :disabled="!canConfigure" searchable />
          </label>
          <label class="space-y-1.5 text-sm">
            <span class="font-medium">{{ isPurchase ? 'Cuenta de IGV crédito fiscal' : 'Cuenta de IGV por pagar' }}</span>
            <UiSelect v-model="form.tax_account_id" :options="taxAccountOptions" :disabled="!canConfigure" searchable />
          </label>
          <label class="space-y-1.5 text-sm">
            <span class="font-medium">{{ isPurchase ? 'Cuenta por pagar al proveedor' : 'Cuenta por cobrar al cliente' }}</span>
            <UiSelect v-model="form.settlement_account_id" :options="accountOptions" :disabled="!canConfigure" searchable />
          </label>
          <label class="space-y-1.5 text-sm">
            <span class="font-medium">Tasa referencial de IGV (%)</span>
            <input v-model="form.default_tax_rate" type="number" min="0" max="100" step="0.01"
              :disabled="!canConfigure" class="w-full rounded-lg border border-line bg-background px-3 py-2 text-foreground" />
          </label>
        </div>
        <div class="mt-6 border-t border-line pt-5">
          <h4 class="font-semibold">Cuenta principal por clasificación tributaria</h4>
          <p class="mt-1 text-xs text-muted">
            Opcional: asigne cuentas distintas a compras gravadas/no gravadas, ventas exoneradas, inafectas o exportaciones.
            Las categorías sin cuenta específica usarán la cuenta principal.
          </p>
          <div class="mt-4 grid gap-3 md:grid-cols-2">
            <label v-for="category in categoryOptions" :key="category.value" class="space-y-1.5 text-sm">
              <span class="font-medium">{{ category.label }}</span>
              <UiSelect :model-value="categoryAccountMap[category.value] ?? ''"
                @update:model-value="categoryAccountMap[category.value] = $event" :options="categoryAccountOptions"
                :disabled="!canConfigure" searchable />
            </label>
          </div>
        </div>
        <p class="mt-4 text-xs text-muted">La tasa es referencial para calcular un borrador. El usuario verifica el IGV del comprobante; la deducibilidad tributaria y notas de crédito requieren sus propias reglas.</p>
        <button type="button" class="mt-5 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
          :disabled="!canConfigure || saving" @click="saveSettings">{{ saving ? 'Guardando…' : 'Guardar parámetros' }}</button>
      </section>

      <section v-else-if="tab === 'register'" class="rounded-xl border border-line bg-surface p-4 sm:p-6">
        <div v-if="!configured" class="mb-5 rounded-lg border border-line bg-soft p-4 text-sm">
          Las cuentas del módulo aún no están configuradas.
          <button type="button" class="ml-2 font-semibold text-brand underline" @click="tab = 'settings'">Configurar ahora</button>
        </div>
        <h3 class="font-semibold">Datos del comprobante</h3>
        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <label class="space-y-1.5 text-sm"><span class="font-medium">Fecha de emisión</span>
            <input v-model="draft.issue_date" type="date" class="w-full rounded-lg border border-line bg-background px-3 py-2" />
          </label>
          <label class="space-y-1.5 text-sm"><span class="font-medium">Fecha contable</span>
            <input v-model="draft.entry_date" type="date" class="w-full rounded-lg border border-line bg-background px-3 py-2" />
          </label>
          <label class="space-y-1.5 text-sm"><span class="font-medium">Comprobante</span>
            <UiSelect v-model="draft.document_type" :options="[{ value: '01', label: '01 · Factura' }, { value: '03', label: '03 · Boleta de venta' }]" />
          </label>
          <label class="space-y-1.5 text-sm"><span class="font-medium">Moneda</span>
            <UiSelect v-model="draft.currency" :options="currencyOptions" />
          </label>
          <label class="space-y-1.5 text-sm"><span class="font-medium">Período tributario SUNAT</span>
            <input v-model="draft.tax_period" type="month" class="w-full rounded-lg border border-line bg-background px-3 py-2" />
          </label>
          <label class="space-y-1.5 text-sm"><span class="font-medium">Fecha de vencimiento (opcional)</span>
            <input v-model="draft.due_date" type="date" class="w-full rounded-lg border border-line bg-background px-3 py-2" />
          </label>
          <label class="space-y-1.5 text-sm"><span class="font-medium">Serie</span>
            <input v-model="draft.series" maxlength="20" placeholder="F001"
              class="w-full rounded-lg border border-line bg-background px-3 py-2 uppercase" />
          </label>
          <label class="space-y-1.5 text-sm"><span class="font-medium">Número</span>
            <input v-model="draft.number" maxlength="20" placeholder="00012345"
              class="w-full rounded-lg border border-line bg-background px-3 py-2 uppercase" />
          </label>
          <div class="space-y-1.5 text-sm sm:col-span-2">
            <label class="font-medium" for="journal-entity-search">{{ isPurchase ? 'Buscar proveedor' : 'Buscar cliente' }}</label>
            <div class="flex gap-2">
              <input id="journal-entity-search" v-model="entityQuery" class="min-w-0 flex-1 rounded-lg border border-line bg-background px-3 py-2"
                placeholder="RUC o razón social" @keyup.enter="searchEntities" />
              <button type="button" class="rounded-lg border border-line px-3 py-2 hover:bg-hover" @click="searchEntities">Buscar</button>
            </div>
          </div>
          <label class="space-y-1.5 text-sm sm:col-span-2 lg:col-span-4">
            <span class="font-medium">{{ isPurchase ? 'Proveedor' : 'Cliente' }}</span>
            <UiSelect v-model="draft.entity_id" :options="entityOptions" searchable />
          </label>
        </div>

        <!-- La vista previa usa el mismo endpoint autorizado de tipos de cambio del ERP.
             La fuente histórica y el valor sugerido NO equivalen a tasa registrada. -->
        <section class="mt-5 rounded-xl border border-line bg-soft p-4" aria-label="Tipo de cambio de la fecha contable">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex items-start gap-2">
              <ArrowLeftRight class="mt-0.5 size-4 shrink-0 text-violet-600 dark:text-violet-200" />
              <div>
                <h3 class="text-sm font-semibold">Tipo de cambio del {{ draft.entry_date }} · Libro Diario bimoneda</h3>
                <p class="mt-1 text-xs text-muted">
                  Moneda del documento: {{ draft.currency }} · Moneda base: {{ fxSnapshot?.base_currency.code ?? 'PEN' }}.
                  También se requiere la tasa de la moneda secundaria cuando el comprobante está en PEN.
                </p>
              </div>
            </div>
            <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-line bg-surface px-3 py-1.5 text-xs hover:bg-hover disabled:opacity-50"
              :disabled="fxLoading" @click="refreshFx">
              <RefreshCw class="size-3.5" :class="{ 'animate-spin': fxLoading }" /> Consultar tasa
            </button>
          </div>
          <p v-if="fxLoading" class="mt-3 text-xs text-muted" role="status">Consultando cotización registrada para la fecha…</p>
          <p v-else-if="fxError" class="mt-3 text-xs text-danger" role="alert">{{ fxError }}</p>
          <template v-else-if="fxSnapshot">
            <p v-if="!secondaryRates.length" class="mt-3 text-xs text-pos">
              No existe una moneda secundaria activa; el registro no necesita conversión.
            </p>
            <div v-for="rate in secondaryRates" :key="rate.code" class="mt-3 rounded-lg border border-line bg-surface p-3">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="font-semibold text-sm">{{ rate.code }}/{{ fxSnapshot.base_currency.code }}</p>
                <span :class="['text-xs font-medium', rate.is_recorded_for_date ? 'text-pos' : 'text-danger']">
                  {{ fxSourceLabel(rate) }}
                </span>
              </div>
              <div class="mt-2 grid grid-cols-2 gap-3">
                <div>
                  <p class="text-xs text-muted">Compra · informativa</p>
                  <p class="font-mono text-base tabular-nums">{{ formatRate(rate.buy_rate) }}</p>
                </div>
                <div>
                  <p class="text-xs text-muted">Venta · requerida para contabilizar</p>
                  <p class="font-mono text-base tabular-nums">{{ formatRate(rate.sell_rate) }}</p>
                </div>
              </div>
              <p v-if="!rate.is_recorded_for_date" class="mt-2 flex items-start gap-2 text-xs text-danger" role="alert">
                <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                Debe registrar y guardar este tipo de cambio en el módulo «Tipos de Cambio» para la fecha contable.
                Las cifras mostradas son únicamente una referencia y no pueden utilizarse para contabilizar.
              </p>
            </div>
          </template>
        </section>

        <div class="mt-7 flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 class="font-semibold">Clasificación tributaria SUNAT</h3>
            <p class="mt-1 text-xs text-muted">El comprobante puede desglosarse en distintas categorías. La sumatoria se registrará también en el Libro Diario.</p>
          </div>
          <button type="button" class="rounded-lg border border-line bg-background px-3 py-2 text-sm hover:bg-hover disabled:opacity-50"
            :disabled="availableCategories.length === 0" @click="addTaxAllocation">
            Agregar clasificación
          </button>
        </div>
        <div class="mt-4 space-y-3">
          <div v-for="(row, index) in taxAllocations" :key="index" class="rounded-lg border border-line bg-soft p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <span class="text-xs font-semibold uppercase tracking-wide text-muted">Clasificación {{ index + 1 }}</span>
              <button v-if="taxAllocations.length > 1" type="button" class="text-xs text-danger underline"
                @click="removeTaxAllocation(index)">Quitar</button>
            </div>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <label class="space-y-1.5 text-sm sm:col-span-2">
                <span class="font-medium">Tipo de operación</span>
                <UiSelect v-model="row.category" :options="categoryOptions" searchable
                  @update:model-value="taxCategoryChanged(row)" />
              </label>
              <label class="space-y-1.5 text-sm">
                <span class="font-medium">Base de la operación</span>
                <input v-model="row.base_amount" type="number" inputmode="decimal" step="0.01" min="0.01"
                  class="w-full rounded-lg border border-line bg-background px-3 py-2" placeholder="100.00" />
              </label>
              <div class="space-y-1.5 text-sm">
                <label class="font-medium" :for="'tax-amount-' + index">IGV del comprobante</label>
                <div class="flex gap-2">
                  <input :id="'tax-amount-' + index" v-model="row.tax_amount" type="number" inputmode="decimal"
                    step="0.01" min="0" :disabled="!isTaxedCategory(row.category)"
                    class="min-w-0 w-full rounded-lg border border-line bg-background px-3 py-2 disabled:opacity-60" />
                  <button type="button" :disabled="!isTaxedCategory(row.category)"
                    class="shrink-0 rounded-lg border border-line bg-background px-2 text-xs hover:bg-hover disabled:opacity-50"
                    title="Calcular con tasa IGV referencial" @click="calculateTax(row)">Calcular</button>
                </div>
              </div>
              <label v-if="isPurchaseCreditCategory(row.category)" class="space-y-1.5 text-sm sm:col-span-2">
                <span class="font-medium">IGV considerado crédito fiscal</span>
                <input v-if="isPurchaseMixed(row.category)" v-model="row.creditable_tax_amount" type="number"
                  inputmode="decimal" min="0" step="0.01"
                  class="w-full rounded-lg border border-line bg-background px-3 py-2" />
                <div v-else class="rounded-lg border border-line bg-background px-3 py-2 tabular-nums">
                  {{ money(Number(row.tax_amount)) }} <span class="text-xs text-muted">· potencial, sujeto a validación</span>
                </div>
              </label>
            </div>
            <p v-if="isPurchaseMixed(row.category)" class="mt-2 text-xs text-muted">
              Indique el crédito fiscal calculado por prorrata. No se supone el derecho al 100 % del IGV.
            </p>
            <p v-if="isPurchase && draft.document_type === '03' && isPurchaseExclusive(row.category)"
              class="mt-2 text-xs text-danger">
              Las boletas de compra no admiten crédito fiscal automático. Use la categoría gravada sin crédito.
            </p>
            <p v-if="row.category === 'S_EXPORT'" class="mt-2 text-xs text-muted">
              Exportación: operación sin IGV trasladado. Para este flujo se requiere factura (01).
            </p>
          </div>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-3">
          <div class="rounded-lg border border-line bg-soft p-3">
            <p class="text-xs text-muted">Total bases {{ draft.currency }}</p>
            <p class="mt-1 text-lg font-semibold tabular-nums">{{ money(amountBase) }}</p>
          </div>
          <div class="rounded-lg border border-line bg-soft p-3">
            <p class="text-xs text-muted">Total IGV {{ draft.currency }}</p>
            <p class="mt-1 text-lg font-semibold tabular-nums">{{ money(amountTax) }}</p>
          </div>
          <div class="rounded-lg border border-line bg-soft p-3">
            <p class="text-xs text-muted">Total comprobante {{ draft.currency }}</p>
            <p class="mt-1 text-xl font-semibold tabular-nums">{{ money(amountTotal) }}</p>
          </div>
        </div>
        <div class="mt-4 grid gap-4 sm:grid-cols-3">
          <label v-if="needsCostCenter" class="space-y-1.5 text-sm"><span class="font-medium">Centro de costo obligatorio</span>
            <UiSelect v-model="draft.cost_center_id" :options="centersOptions" searchable />
          </label>
          <label v-if="needsCompanyArea" class="space-y-1.5 text-sm"><span class="font-medium">Área de empresa</span>
            <UiSelect v-model="draft.company_area_id" :options="companyAreaOptions" searchable />
          </label>
          <label v-if="needsCashFlowArea" class="space-y-1.5 text-sm"><span class="font-medium">Área de flujo de efectivo</span>
            <UiSelect v-model="draft.cash_flow_area_id" :options="cashFlowOptions" searchable />
          </label>
          <label class="space-y-1.5 text-sm sm:col-span-3"><span class="font-medium">Glosa adicional (opcional)</span>
            <input v-model="draft.description" maxlength="180" class="w-full rounded-lg border border-line bg-background px-3 py-2"
              placeholder="Si se deja vacío se genera con comprobante y tercero" />
          </label>
        </div>
        <div v-if="Object.keys(validationErrors).length" class="mt-4 rounded-lg border border-danger/40 bg-surface p-3" role="alert">
          <p class="text-sm font-semibold text-danger">Revise los datos antes de continuar:</p>
          <ul class="mt-1 list-inside list-disc text-xs text-danger">
            <li v-for="(message, field) in validationErrors" :key="field">{{ message }}</li>
          </ul>
        </div>
        <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-line pt-5">
          <p class="text-xs text-muted">Se crea un asiento contable y una anotación tributaria interna pendientes de conciliación SIRE. El asiento es inmutable.</p>
          <button type="button" class="inline-flex items-center gap-2 rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
            :disabled="!canPost || !configured || posting || fxLoading" @click="preparePosting">
            <FilePlus2 class="size-4" /> {{ posting ? 'Contabilizando…' : 'Registrar en Diario y Registro Tributario' }}
          </button>
        </div>
      </section>

      <section v-else-if="tab === 'tax'" class="space-y-4">
        <div class="rounded-xl border border-line bg-surface p-4">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
              <h3 class="font-semibold">{{ isPurchase ? 'Registro de Compras · RCE' : 'Registro de Ventas e Ingresos · RVIE' }}</h3>
              <p class="mt-1 text-xs text-muted">Libro tributario interno. Pendiente de comparación con la propuesta SUNAT/SIRE.</p>
            </div>
            <div class="flex items-center gap-2">
              <input v-model="taxPeriodFilter" type="month" aria-label="Filtrar período tributario"
                class="rounded-lg border border-line bg-background px-3 py-2 text-sm" />
              <button type="button" class="rounded-lg border border-line px-3 py-2 text-sm hover:bg-hover"
                :disabled="taxLoading || !taxPeriodFilter" @click="refreshTaxRegister()">Consultar</button>
            </div>
          </div>
          <div v-if="taxLoading" role="status" class="py-8 text-center text-sm text-muted">Consultando registro tributario…</div>
          <template v-else>
            <h4 class="mt-6 text-sm font-semibold">Resumen por clasificación y moneda</h4>
            <div class="mt-3 overflow-x-auto">
              <table class="w-full min-w-[600px] text-left text-sm">
                <thead class="bg-soft text-xs text-muted">
                  <tr><th class="p-3">Operación</th><th class="p-3">Moneda</th>
                    <th class="p-3 text-right">Base</th><th class="p-3 text-right">IGV</th>
                    <th v-if="isPurchase" class="p-3 text-right">IGV potencial / prorrata</th>
                    <th class="p-3 text-right">Documentos</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="item in taxSummary?.breakdown ?? []" :key="item.category + item.currency" class="border-t border-line">
                    <td class="p-3">{{ TAX_CATEGORY_LABELS[item.category] }}</td>
                    <td class="p-3 font-mono">{{ item.currency }}</td>
                    <td class="p-3 text-right tabular-nums">{{ money(Number(item.base_amount)) }}</td>
                    <td class="p-3 text-right tabular-nums">{{ money(Number(item.tax_amount)) }}</td>
                    <td v-if="isPurchase" class="p-3 text-right tabular-nums">{{ money(Number(item.creditable_tax_amount)) }}</td>
                    <td class="p-3 text-right tabular-nums">{{ item.document_count }}</td>
                  </tr>
                  <tr v-if="!taxSummary?.breakdown.length">
                    <td :colspan="isPurchase ? 6 : 5" class="p-6 text-center text-muted">Sin información tributaria en el período.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </div>
        <div v-if="!taxLoading" class="overflow-hidden rounded-xl border border-line bg-surface">
          <div class="flex items-center justify-between border-b border-line p-4">
            <h4 class="font-semibold">Detalle de anotaciones tributarias</h4>
            <span class="text-xs text-muted">{{ taxRegister?.total ?? 0 }} comprobantes</span>
          </div>
          <div class="overflow-x-auto">
            <table class="w-full min-w-[950px] text-left text-sm">
              <thead class="bg-soft text-xs text-muted">
                <tr>
                  <th class="p-3">Emisión</th><th class="p-3">Comprobante</th>
                  <th class="p-3">Cliente / proveedor (snapshot)</th><th class="p-3">Clasificación</th>
                  <th class="p-3 text-right">Base</th><th class="p-3 text-right">IGV</th>
                  <th class="p-3 text-right">Crédito</th><th class="p-3">Voucher</th>
                </tr>
              </thead>
              <tbody>
                <template v-for="record in taxRegister?.data ?? []" :key="record.id">
                  <tr v-for="allocation in record.allocations" :key="allocation.id" class="border-t border-line">
                    <td class="p-3 tabular-nums">{{ record.document.issue_date }}</td>
                    <td class="p-3 whitespace-nowrap">{{ record.document.document_type }} {{ record.document.series }}-{{ record.document.number }}</td>
                    <td class="p-3">{{ record.counterparty_tax_id }} · {{ record.counterparty_name }}</td>
                    <td class="p-3">{{ TAX_CATEGORY_LABELS[allocation.category] }}</td>
                    <td class="p-3 text-right tabular-nums">{{ record.document.currency }} {{ money(Number(allocation.base_amount)) }}</td>
                    <td class="p-3 text-right tabular-nums">{{ money(Number(allocation.tax_amount)) }}</td>
                    <td class="p-3 text-right tabular-nums">{{ money(Number(allocation.creditable_tax_amount)) }}</td>
                    <td class="p-3 font-mono text-xs">{{ record.document.journal?.entry_number }}</td>
                  </tr>
                </template>
                <tr v-if="!taxRegister?.data.length">
                  <td colspan="8" class="p-7 text-center text-muted">No hay documentos tributarios en el período.</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="taxRegister && taxRegister.last_page > 1" class="flex items-center justify-end gap-3 border-t border-line p-3 text-sm">
            <button type="button" class="rounded border border-line px-3 py-1.5 disabled:opacity-50"
              :disabled="taxRegister.current_page <= 1" @click="refreshTaxRegister(taxRegister.current_page - 1)">Anterior</button>
            <span>{{ taxRegister.current_page }} / {{ taxRegister.last_page }}</span>
            <button type="button" class="rounded border border-line px-3 py-1.5 disabled:opacity-50"
              :disabled="taxRegister.current_page >= taxRegister.last_page" @click="refreshTaxRegister(taxRegister.current_page + 1)">Siguiente</button>
          </div>
        </div>
      </section>

      <section v-else class="overflow-hidden rounded-xl border border-line bg-surface">
        <div class="flex items-center justify-between gap-3 border-b border-line p-4">
          <h3 class="font-semibold">Comprobantes contabilizados</h3>
          <span class="text-xs text-muted">{{ history?.total ?? 0 }} documento(s)</span>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full min-w-[740px] text-left text-sm">
            <thead class="bg-soft text-xs text-muted"><tr>
              <th class="p-3">Fecha</th><th class="p-3">Documento</th><th class="p-3">Tercero</th>
              <th class="p-3 text-right">Total</th><th class="p-3">Voucher</th><th class="p-3">Estado</th>
            </tr></thead>
            <tbody>
              <tr v-for="r in (history?.data ?? [])" :key="r.id" class="border-t border-line">
                <td class="p-3 tabular-nums">{{ r.entry_date }}</td>
                <td class="p-3 font-medium">{{ r.document_type }} {{ r.series }}-{{ r.number }}</td>
                <td class="p-3">{{ r.entity?.tax_id }} · {{ r.entity?.legal_name }}</td>
                <td class="p-3 text-right tabular-nums">{{ r.currency }} {{ money(Number(r.total_amount)) }}</td>
                <td class="p-3 font-mono text-xs">{{ r.journal?.subdiary_code }} / {{ r.journal?.entry_number }}</td>
                <td class="p-3">{{ r.journal?.status === 'REVERSED' ? 'Extornado' : 'Registrado' }}</td>
              </tr>
              <tr v-if="!history?.data.length"><td colspan="6" class="p-7 text-center text-muted">Aún no hay comprobantes registrados.</td></tr>
            </tbody>
          </table>
        </div>
        <div v-if="history && history.last_page > 1" class="flex items-center justify-end gap-3 border-t border-line p-3 text-sm">
          <button type="button" class="rounded border border-line px-3 py-1.5 disabled:opacity-50"
            :disabled="history.current_page <= 1" @click="refreshHistory(history.current_page - 1)">Anterior</button>
          <span>{{ history.current_page }} / {{ history.last_page }}</span>
          <button type="button" class="rounded border border-line px-3 py-1.5 disabled:opacity-50"
            :disabled="history.current_page >= history.last_page" @click="refreshHistory(history.current_page + 1)">Siguiente</button>
        </div>
      </section>
    </template>
    <DocumentPostingConfirmDialog
      :open="confirmOpen"
      :busy="posting"
      :document-label="(draft.series || '—') + '-' + (draft.number || '—')"
      :currency="draft.currency"
      :total="money(amountTotal)"
      @cancel="confirmOpen = false"
      @confirm="postDocument"
    />
  </div>
</template>
