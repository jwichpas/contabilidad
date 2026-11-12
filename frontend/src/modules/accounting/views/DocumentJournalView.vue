<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { BookOpenCheck, CheckCircle2, FilePlus2, RefreshCw, Settings2 } from 'lucide-vue-next';
import UiSelect from '@/shared/ui/UiSelect.vue';
import { AccountingApiClient } from '../api/httpClient';
import type { CompanyId } from '../types/accountingAccount.types';
import type { JournalEditorAccount } from '../types/journalEntry.types';
import type { CashFlowAreaItem, CompanyAreaItem, CostCenterItem } from '../types/catalogs.types';
import type {
  DocumentJournalModule, DocumentJournalSettings, DocumentJournalRecord,
  DocumentJournalEntity, DocumentJournalPage,
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
const tab = ref<'register' | 'settings' | 'history'>('register');
const loading = ref(true);
const saving = ref(false);
const posting = ref(false);
const error = ref('');
const success = ref('');
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
const sections = ['register', 'history', 'settings'] as const;

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
  taxable_amount: '',
  tax_amount: '0.00',
  cost_center_id: '',
  company_area_id: '',
  cash_flow_area_id: '',
  description: '',
});

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
const mainAccount = computed(() => accounts.value.find(a => a.id === settings.value?.main_account_id));
const needsCostCenter = computed(() => !!mainAccount.value && (mainAccount.value.requires_cost_center ||
  mainAccount.value.has_automatic_destination));
const amountTotal = computed(() => {
  const base = Number(draft.taxable_amount);
  const tax = Number(draft.tax_amount);
  return Number.isFinite(base) && Number.isFinite(tax) && base >= 0 && tax >= 0 ? base + tax : NaN;
});
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
    });
    setForm(saved);
    success.value = 'Parámetros guardados para esta empresa y este módulo.';
    tab.value = 'register';
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'No se pudieron guardar los parámetros.';
  } finally {
    saving.value = false;
  }
}

function calculateTax(): void {
  const base = Number(draft.taxable_amount);
  const rate = Number(settings.value?.default_tax_rate ?? '0');
  if (Number.isFinite(base) && base > 0 && Number.isFinite(rate)) {
    draft.tax_amount = (Math.round((base * rate / 100 + Number.EPSILON) * 100) / 100).toFixed(2);
  }
}

async function refreshHistory(page = 1): Promise<void> {
  try {
    history.value = await api.fetchDocumentJournalRecords(props.module, page);
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'No se pudo actualizar el registro.';
  }
}

async function postDocument(): Promise<void> {
  if (!props.canPost || posting.value || !configured.value) return;
  error.value = '';
  success.value = '';
  if (!draft.entity_id || !draft.series.trim() || !draft.number.trim() || !draft.taxable_amount) {
    error.value = 'Complete el tercero, la serie, el número y la base imponible.';
    return;
  }
  if (needsCostCenter.value && !draft.cost_center_id) {
    error.value = 'La cuenta de origen requiere centro de costos.';
    return;
  }
  if (!Number.isFinite(amountTotal.value) || amountTotal.value <= 0) {
    error.value = 'Revise los importes de la operación.';
    return;
  }
  if (!window.confirm('¿Contabilizar el comprobante ' + draft.series + '-' + draft.number +
    ' por ' + draft.currency + ' ' + money(amountTotal.value) +
    '? El asiento será inmutable y sólo podrá corregirse mediante extorno.')) return;
  posting.value = true;
  emit('posting-change', true);
  try {
    const record = await api.postDocumentJournal(props.module, {
      document_type: draft.document_type,
      series: draft.series.trim().toUpperCase(),
      number: draft.number.trim().toUpperCase(),
      entity_id: Number(draft.entity_id),
      issue_date: draft.issue_date,
      entry_date: draft.entry_date,
      currency: draft.currency,
      taxable_amount: draft.taxable_amount,
      tax_amount: draft.tax_amount,
      ...(draft.cost_center_id ? { cost_center_id: Number(draft.cost_center_id) } : {}),
      ...(draft.company_area_id ? { company_area_id: Number(draft.company_area_id) } : {}),
      ...(draft.cash_flow_area_id ? { cash_flow_area_id: Number(draft.cash_flow_area_id) } : {}),
      ...(draft.description.trim() ? { description: draft.description.trim() } : {}),
    });
    success.value = 'Comprobante contabilizado. Voucher ' + (record.journal?.entry_number ?? record.journal_entry_id);
    draft.series = '';
    draft.number = '';
    draft.taxable_amount = '';
    draft.tax_amount = '0.00';
    draft.description = '';
    await refreshHistory();
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'No se pudo contabilizar el documento.';
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
        <p class="mt-1 text-sm text-muted">Parametrización independiente por empresa y asiento integrado en el Libro Diario Bimoneda.</p>
      </div>
      <button type="button" class="inline-flex items-center gap-2 rounded-lg border border-line bg-surface px-3 py-2 text-sm hover:bg-hover"
        :disabled="loading" @click="load">
        <RefreshCw class="size-4" :class="{ 'animate-spin': loading }" /> Actualizar
      </button>
    </header>

    <div v-if="error" role="alert" class="rounded-lg border border-danger/40 bg-surface p-3 text-sm text-danger">{{ error }}</div>
    <div v-if="success" role="status" class="flex items-center gap-2 rounded-lg border border-line bg-surface p-3 text-sm text-pos">
      <CheckCircle2 class="size-4 shrink-0" />{{ success }}
    </div>
    <div v-if="loading" role="status" class="rounded-xl border border-line bg-surface p-8 text-sm text-muted">Cargando cuentas, subdiarios y comprobantes de la empresa…</div>
    <template v-else>
      <div class="flex flex-wrap gap-2 border-b border-line pb-3" role="tablist" :aria-label="title">
        <button v-for="t in sections" :key="t" type="button"
          role="tab" :aria-selected="tab === t"
          :class="['inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm',
            tab === t ? 'bg-brand/10 font-semibold text-foreground' : 'text-muted hover:bg-hover']"
          @click="tab = t; error = ''; success = ''">
          <FilePlus2 v-if="t === 'register'" class="size-4" />
          <BookOpenCheck v-else-if="t === 'history'" class="size-4" />
          <Settings2 v-else class="size-4" />
          {{ t === 'register' ? 'Nuevo comprobante' : t === 'history' ? 'Documentos contabilizados' : 'Parámetros del módulo' }}
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

        <h3 class="mt-7 font-semibold">Distribución contable</h3>
        <p class="mt-1 text-xs text-muted">Importes positivos de la factura o boleta. No incluye notas de crédito ni operaciones exoneradas especiales en esta primera versión.</p>
        <div class="mt-4 grid gap-4 sm:grid-cols-3">
          <label class="space-y-1.5 text-sm"><span class="font-medium">Base imponible</span>
            <input v-model="draft.taxable_amount" inputmode="decimal" type="number" step="0.01" min="0.01"
              class="w-full rounded-lg border border-line bg-background px-3 py-2" placeholder="100.00" />
          </label>
          <div class="space-y-1.5">
            <label class="text-sm font-medium" for="doc-tax-amount">IGV del comprobante</label>
            <div class="flex gap-2">
              <input id="doc-tax-amount" v-model="draft.tax_amount" inputmode="decimal" type="number" step="0.01" min="0"
                class="min-w-0 w-full rounded-lg border border-line bg-background px-3 py-2" />
              <button type="button" title="Calcular con tasa referencial" class="shrink-0 rounded-lg border border-line px-2 text-xs hover:bg-hover"
                @click="calculateTax">Calcular</button>
            </div>
          </div>
          <div class="rounded-lg border border-line bg-soft p-3">
            <p class="text-xs text-muted">Total {{ draft.currency }}</p>
            <p class="mt-1 text-xl font-semibold tabular-nums">{{ money(amountTotal) }}</p>
          </div>
          <label v-if="needsCostCenter" class="space-y-1.5 text-sm"><span class="font-medium">Centro de costo obligatorio</span>
            <UiSelect v-model="draft.cost_center_id" :options="centersOptions" searchable />
          </label>
          <label v-if="mainAccount?.requires_company_area" class="space-y-1.5 text-sm"><span class="font-medium">Área de empresa</span>
            <UiSelect v-model="draft.company_area_id" :options="companyAreaOptions" searchable />
          </label>
          <label v-if="mainAccount?.requires_cash_flow_area" class="space-y-1.5 text-sm"><span class="font-medium">Área de flujo de efectivo</span>
            <UiSelect v-model="draft.cash_flow_area_id" :options="cashFlowOptions" searchable />
          </label>
          <label class="space-y-1.5 text-sm sm:col-span-3"><span class="font-medium">Glosa adicional (opcional)</span>
            <input v-model="draft.description" maxlength="180" class="w-full rounded-lg border border-line bg-background px-3 py-2"
              placeholder="Si se deja vacío se genera con comprobante y tercero" />
          </label>
        </div>
        <div class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-line pt-5">
          <p class="text-xs text-muted">El tipo de cambio diario se toma de la configuración de la empresa. El asiento es inmutable.</p>
          <button type="button" class="inline-flex items-center gap-2 rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
            :disabled="!canPost || !configured || posting" @click="postDocument">
            <FilePlus2 class="size-4" /> {{ posting ? 'Contabilizando…' : 'Registrar asiento contable' }}
          </button>
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
  </div>
</template>
