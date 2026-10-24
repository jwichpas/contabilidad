<script setup lang="ts">
import { computed, onMounted, ref, shallowRef, watch } from 'vue';
import {
  AlertTriangle,
  BookOpen,
  CheckCircle2,
  ChevronDown,
  ChevronUp,
  Clock,
  Coins,
  Hash,
  History,
  Layers,
  Loader2,
  Plus,
  RefreshCw,
  Save,
  ShieldCheck,
  Sparkles,
  Trash2,
} from 'lucide-vue-next';
import type { OperationalAccount } from '../types/journalEntry.types';
import type { EntityMaster } from '../../company/types/entityMaster.types';
import JournalEntityPicker from '../components/JournalEntityPicker.vue';
import JournalAccountCombobox from '../components/JournalAccountCombobox.vue';
import { entityMatches, entityRequired } from '../utils/journalEntity';
import { cashFlowMatches } from '../utils/journalArea';
import { resetAccountDimensions } from '../utils/journalLine';
import { AccountingApiClient, ApiHttpError } from '../api/httpClient';
import { SystemDecimal } from '../../../shared/types/numeric.types';
import type { CompanyId } from '../types/accountingAccount.types';
import type { CostCenterItem, CompanyAreaItem, CashFlowAreaItem } from '../types/catalogs.types';
import type {
  CurrencyCode,
  EntryType,
  JournalEntryResponse,
} from '../types/journalEntry.types';

const props = defineProps<{
  readonly activeCompanyId: CompanyId;
  readonly getAccessToken: () => string | null;
}>();

const apiClient = computed(
  () =>
    new AccountingApiClient('', {
      getAccessToken: props.getAccessToken,
      getActiveCompanyId: () => props.activeCompanyId,
    }),
);

// Pestaña activa: 'form' (Nuevo Asiento) o 'history' (Libro Diario)
const activeTab = shallowRef<'form' | 'history'>('form');

// Estados generales
const isLoading = shallowRef(false);
const isSubmitting = shallowRef(false);
const errorMessage = shallowRef<string | null>(null);
const successMessage = shallowRef<string | null>(null);

// Catálogo de Cuentas Operativas (Nivel 4+)
const operationalAccounts = shallowRef<readonly OperationalAccount[]>([]);
const costCenters = shallowRef<readonly CostCenterItem[]>([]);
const companyAreas = shallowRef<readonly CompanyAreaItem[]>([]);
const cashFlowAreas = shallowRef<readonly CashFlowAreaItem[]>([]);

// Campos de Cabecera del Voucher
const todayStr = new Date().toISOString().slice(0, 10);
const entryDate = ref(todayStr);
const entryNumber = ref('');
const entryType = ref<EntryType>('STANDARD');
const subdiaryCode = ref('');
const subdiaries = shallowRef<readonly { code: string; name: string }[]>([]);
const currency = ref<CurrencyCode>('PEN');
const exchangeRate = ref('3.750000');
const exchangeRateType = ref<'BUY' | 'SELL'>('SELL');
const headerDescription = ref('');

// Régimen de Monedas y Tipos de Cambio Diarios Obligatorios (Empresa)
interface EditableCurrencyRate {
  currency_id: number;
  code: string;
  name: string;
  symbol: string;
  buy_rate: string;
  sell_rate: string;
  is_recorded_for_date: boolean;
  source: string;
}

const baseCurrencyInfo = ref<{ code: string; name: string; symbol: string }>({
  code: 'PEN',
  name: 'Soles',
  symbol: 'S/',
});

const requiredCurrencyRates = ref<EditableCurrencyRate[]>([]);
const isLoadingRates = shallowRef(false);

const activeForeignCurrency = computed<EditableCurrencyRate>(() => {
  const found = requiredCurrencyRates.value.find((r) => r.code === currency.value);
  if (found) return found;
  if (requiredCurrencyRates.value.length > 0) return requiredCurrencyRates.value[0]!;
  return {
    currency_id: 2,
    code: 'USD',
    name: 'Dólar Estadounidense',
    symbol: '$',
    buy_rate: '3.742000',
    sell_rate: '3.758000',
    is_recorded_for_date: false,
    source: 'DEFAULT',
  };
});

const availableVoucherCurrencies = computed(() => {
  const list: Array<{ code: CurrencyCode; label: string }> = [
    {
      code: baseCurrencyInfo.value.code as CurrencyCode,
      label: `${baseCurrencyInfo.value.code} · ${baseCurrencyInfo.value.name} (${baseCurrencyInfo.value.symbol}) [Base]`,
    },
  ];
  for (const r of requiredCurrencyRates.value) {
    if (r.code !== baseCurrencyInfo.value.code) {
      list.push({
        code: r.code as CurrencyCode,
        label: `${r.code} · ${r.name} (${r.symbol})`,
      });
    }
  }
  return list;
});

function syncActiveVoucherExchangeRate(): void {
  const target =
    currency.value === baseCurrencyInfo.value.code
      ? requiredCurrencyRates.value[0]
      : requiredCurrencyRates.value.find((r) => r.code === currency.value);

  if (target) {
    exchangeRate.value =
      exchangeRateType.value === 'BUY' ? target.buy_rate : target.sell_rate;
  }
  recalculateForeignAmounts();
}

function updateRateForCurrency(code: string, field: 'buy_rate' | 'sell_rate', value: string): void {
  const target = requiredCurrencyRates.value.find((r) => r.code === code);
  if (target) {
    target[field] = value;
    if (
      currency.value === code ||
      (currency.value === baseCurrencyInfo.value.code && requiredCurrencyRates.value[0]?.code === code)
    ) {
      if (
        (field === 'buy_rate' && exchangeRateType.value === 'BUY') ||
        (field === 'sell_rate' && exchangeRateType.value === 'SELL')
      ) {
        exchangeRate.value = value;
      }
    }
    recalculateForeignAmounts();
  }
}

function convertLocalToForeign(raw: string): string {
  try {
    const amount = SystemDecimal.parseMonetary(raw);
    const rate = SystemDecimal.parseExchangeRate(exchangeRate.value.trim() || '1');
    return rate.isPositive() ? amount.divideBy(rate).toString() : '';
  } catch {
    // Mientras el usuario escribe, una entrada parcial no debe producir NaN.
    return '';
  }
}

function recalculateForeignAmounts(): void {
  for (const line of lines.value) {
    line.debitForeign = line.debitLocal ? convertLocalToForeign(line.debitLocal) : '';
    line.creditForeign = line.creditLocal ? convertLocalToForeign(line.creditLocal) : '';
  }
}

async function loadExchangeRatesForDate(dateStr: string): Promise<void> {
  isLoadingRates.value = true;
  try {
    const res = await apiClient.value.fetchCompanyExchangeRates(dateStr);
    baseCurrencyInfo.value = res.base_currency;
    requiredCurrencyRates.value = res.required_currencies.map((c) => ({
      currency_id: c.currency_id,
      code: c.code,
      name: c.name,
      symbol: c.symbol,
      buy_rate: c.buy_rate,
      sell_rate: c.sell_rate,
      is_recorded_for_date: c.is_recorded_for_date,
      source: c.source,
    }));
    syncActiveVoucherExchangeRate();
  } catch (err) {
    console.error('Error al cargar tipos de cambio obligatorios:', err);
  } finally {
    isLoadingRates.value = false;
  }
}

// Líneas en edición del Asiento
interface EditingLine {
  id: number;
  accountId: number | null;
  costCenterId: number | null;
  companyAreaId: number | null;
  cashFlowAreaId: number | null;
  entity: EntityMaster | null;
  description: string;
  debitLocal: string;
  creditLocal: string;
  debitForeign: string;
  creditForeign: string;
}

let nextLineKey = 1;
const lines = ref<EditingLine[]>([
  {
    id: nextLineKey++,
    accountId: null,
    costCenterId: null,
    companyAreaId: null,
    cashFlowAreaId: null,
    entity: null,
    description: '',
    debitLocal: '',
    creditLocal: '',
    debitForeign: '',
    creditForeign: '',
  },
  {
    id: nextLineKey++,
    accountId: null,
    costCenterId: null,
    companyAreaId: null,
    cashFlowAreaId: null,
    entity: null,
    description: '',
    debitLocal: '',
    creditLocal: '',
    debitForeign: '',
    creditForeign: '',
  },
]);

// Historial de Asientos del Periodo
const journalEntries = shallowRef<readonly JournalEntryResponse[]>([]);
const expandedEntryIds = shallowRef<Set<number>>(new Set());

// After a network timeout the POST may have committed. Reuse its key only
// for an identical payload and company; never for a changed journal draft.
let pendingJournalAttempt: {
  companyId: number;
  payloadJson: string;
  key: string;
} | null = null;

// ==========================================
// CÁLCULOS Y TOTALES EN VIVO (PARTIDA DOBLE)
// ==========================================

function isValidPositiveExchangeRate(value: string): boolean {
  if (!/^\d+(?:\.\d{1,6})?$/.test(value.trim())) return false;
  try {
    return SystemDecimal.parseExchangeRate(value).isPositive();
  } catch {
    return false;
  }
}

function formatPostedAmount(value: string, prefix = ''): string {
  try {
    const exact = SystemDecimal.parseMonetary(value);
    return exact.isPositive() ? prefix + exact.toDisplay(2) : '—';
  } catch {
    return '—';
  }
}

function parseAmount(val: string): SystemDecimal {
  try {
    return SystemDecimal.parseMonetary(val.trim() || '0');
  } catch {
    return SystemDecimal.zero();
  }
}

const totalDebitLocal = computed(() =>
  lines.value.reduce((acc, l) => acc.add(parseAmount(l.debitLocal)), SystemDecimal.zero()),
);
const totalCreditLocal = computed(() =>
  lines.value.reduce((acc, l) => acc.add(parseAmount(l.creditLocal)), SystemDecimal.zero()),
);
const totalDebitForeign = computed(() =>
  lines.value.reduce((acc, l) => acc.add(parseAmount(l.debitForeign)), SystemDecimal.zero()),
);
const totalCreditForeign = computed(() =>
  lines.value.reduce((acc, l) => acc.add(parseAmount(l.creditForeign)), SystemDecimal.zero()),
);
const diffLocal = computed(() =>
  totalDebitLocal.value.subtract(totalCreditLocal.value).abs(),
);
const diffForeign = computed(() =>
  totalDebitForeign.value.subtract(totalCreditForeign.value).abs(),
);
const isBalanced = computed(() =>
  lines.value.length >= 2 &&
  totalDebitLocal.value.isPositive() &&
  diffLocal.value.isZero() &&
  diffForeign.value.isZero(),
);

// ==========================================
// MÉTODOS DE FORMULARIO
// ==========================================

function addLine(): void {
  lines.value.push({
    id: nextLineKey++,
    accountId: null,
    costCenterId: null,
    companyAreaId: null,
    cashFlowAreaId: null,
    entity: null,
    description: headerDescription.value,
    debitLocal: '',
    creditLocal: '',
    debitForeign: '',
    creditForeign: '',
  });
}

function removeLine(index: number): void {
  if (lines.value.length > 2) {
    lines.value.splice(index, 1);
  }
}

function onAmountChange(line: EditingLine, side: 'debit' | 'credit', rawValue: string): void {
  // Mantener la entrada como texto decimal: Vue convierte v-model:number a IEEE-754.
  if (side === 'debit') line.debitLocal = rawValue;
  else line.creditLocal = rawValue;
  if (side === 'debit') {
    if (line.debitLocal) {
      line.creditLocal = '';
      line.creditForeign = '';
      line.debitForeign = convertLocalToForeign(line.debitLocal);
    } else {
      line.debitForeign = '';
    }
  } else {
    if (line.creditLocal) {
      line.debitLocal = '';
      line.debitForeign = '';
      line.creditForeign = convertLocalToForeign(line.creditLocal);
    } else {
      line.creditForeign = '';
    }
  }
}

/**
 * Cuadre automático: calcula la diferencia y la asigna a la última línea o agrega una nueva
 */
function autoBalance(): void {
  const diff = totalDebitLocal.value.subtract(totalCreditLocal.value);
  if (diff.isZero()) return;

  let target = lines.value[lines.value.length - 1];
  if (!target || target.debitLocal || target.creditLocal) {
    addLine();
    target = lines.value[lines.value.length - 1]!;
  }

  const amount = diff.abs().toString();
  if (diff.isPositive()) {
    target.creditLocal = amount;
    target.creditForeign = convertLocalToForeign(amount);
    target.debitLocal = '';
    target.debitForeign = '';
  } else {
    target.debitLocal = amount;
    target.debitForeign = convertLocalToForeign(amount);
    target.creditLocal = '';
    target.creditForeign = '';
  }
}

function clearForm(): void {
  pendingJournalAttempt = null;
  subdiaryCode.value = '';
  headerDescription.value = '';
  lines.value = [
    {
      id: nextLineKey++,
      accountId: null,
      costCenterId: null,
      companyAreaId: null,
      cashFlowAreaId: null,
      entity: null,
      description: '',
      debitLocal: '',
      creditLocal: '',
      debitForeign: '',
      creditForeign: '',
    },
    {
      id: nextLineKey++,
      accountId: null,
      costCenterId: null,
      companyAreaId: null,
      cashFlowAreaId: null,
      entity: null,
      description: '',
      debitLocal: '',
      creditLocal: '',
      debitForeign: '',
      creditForeign: '',
    },
  ];
  errorMessage.value = null;
  successMessage.value = null;
  void fetchNextNumber();
}

async function fetchNextNumber(): Promise<void> {
  try {
    const res = await apiClient.value.fetchNextJournalEntryNumber(entryDate.value);
    entryNumber.value = res.next_number;
  } catch {
    // Si falla, el usuario puede continuar o el servidor lo genera
  }
}

let loadSequence = 0;
const historyLoaded = shallowRef(false);
const isLoadingHistory = shallowRef(false);

async function loadHistory(): Promise<void> {
  if (isLoadingHistory.value) return;
  const tenant = props.activeCompanyId;
  isLoadingHistory.value = true;
  try {
    const entries = await apiClient.value.fetchJournalEntries({ limit: 50 });
    if (tenant !== props.activeCompanyId) return;
    journalEntries.value = entries;
    historyLoaded.value = true;
  } catch (e) {
    if (tenant === props.activeCompanyId) {
      errorMessage.value = e instanceof Error ? e.message : 'No se pudo cargar el Libro Diario.';
    }
  } finally {
    isLoadingHistory.value = false;
  }
}

async function loadCatalogsAndHistory(): Promise<void> {
  const sequence = ++loadSequence;
  const tenant = props.activeCompanyId;
  isLoading.value = true;
  errorMessage.value = null;
  historyLoaded.value = false;
  journalEntries.value = [];
  try {
    const config = await apiClient.value.fetchJournalEditorBootstrap(entryDate.value);
    if (sequence !== loadSequence || tenant !== props.activeCompanyId) return;
    operationalAccounts.value = config.accounts;
    subdiaries.value = config.subdiaries;
    costCenters.value = config.cost_centers;
    companyAreas.value = config.company_areas;
    cashFlowAreas.value = config.cash_flow_areas;
    entryNumber.value = config.next_number.next_number;
    baseCurrencyInfo.value = config.exchange_rates.base_currency;
    requiredCurrencyRates.value = config.exchange_rates.required_currencies.map(c => ({
      currency_id: c.currency_id,
      code: c.code,
      name: c.name,
      symbol: c.symbol,
      buy_rate: c.buy_rate,
      sell_rate: c.sell_rate,
      is_recorded_for_date: c.is_recorded_for_date,
      source: c.source,
    }));
    syncActiveVoucherExchangeRate();
    if (activeTab.value === 'history') void loadHistory();
  } catch (e) {
    if (sequence !== loadSequence || tenant !== props.activeCompanyId) return;
    errorMessage.value =
      e instanceof Error ? e.message : 'Error al cargar el editor de asientos.';
  } finally {
    if (sequence === loadSequence) isLoading.value = false;
  }
}

async function handleSubmit(): Promise<void> {
  errorMessage.value = null;
  successMessage.value = null;
  if (!subdiaryCode.value || !subdiaries.value.some(s => s.code === subdiaryCode.value)) {
    errorMessage.value = 'Seleccione el subdiario contable del asiento.';
    return;
  }

  if (!headerDescription.value.trim()) {
    errorMessage.value = 'Por favor ingrese la glosa o descripción general del asiento.';
    return;
  }

  if (!isBalanced.value) {
    errorMessage.value =
      'El asiento se encuentra descuadrado. La suma del Debe debe ser exactamente igual a la suma del Haber.';
    return;
  }

  // Validar obligatoriedad de tipos de cambio para CADA moneda secundaria configurada
  for (const secRate of requiredCurrencyRates.value) {
    if (!isValidPositiveExchangeRate(secRate.buy_rate) ||
        !isValidPositiveExchangeRate(secRate.sell_rate)) {
      errorMessage.value = `Es obligatorio registrar el Tipo de Cambio (Compra y Venta mayor a 0) para la moneda ${secRate.code} (${secRate.name}) en la fecha ${entryDate.value}.`;
      return;
    }
  }

  // Validar cuentas
  for (let i = 0; i < lines.value.length; i++) {
    const l = lines.value[i]!;
    if (!l.accountId) {
      errorMessage.value = `Seleccione una cuenta contable operativa en la línea #${i + 1}.`;
      return;
    }
    const chosenAccount = operationalAccounts.value.find((account) => account.id === l.accountId);
    if ((chosenAccount?.requires_cost_center || chosenAccount?.has_automatic_destination) && !l.costCenterId) {
      errorMessage.value = `La cuenta ${chosenAccount.code} requiere centro de costos en la línea #${i + 1}.`;
      return;
    }
    if (chosenAccount && !chosenAccount.requires_cost_center && !chosenAccount.has_automatic_destination && l.costCenterId !== null) {
      errorMessage.value = `La cuenta ${chosenAccount.code} no habilita centro de costos en la línea #${i + 1}.`;
      return;
    }
    if (!chosenAccount || !entityMatches(chosenAccount, l.entity)) {
      errorMessage.value = `Seleccione un anexo activo del tipo exigido por la cuenta en la línea #${i + 1}.`;
      return;
    }
    if (chosenAccount.requires_company_area && l.companyAreaId === null) {
      errorMessage.value = `La cuenta ${chosenAccount.code} requiere área organizativa en la línea #${i + 1}.`;
      return;
    }
    if (!chosenAccount.requires_company_area && l.companyAreaId !== null) {
      errorMessage.value = `La cuenta ${chosenAccount.code} no habilita área organizativa en la línea #${i + 1}.`;
      return;
    }
    if (l.companyAreaId && !companyAreas.value.some((area) => area.id === l.companyAreaId && area.is_active)) {
      errorMessage.value = `Seleccione un área organizativa activa en la línea #${i + 1}.`;
      return;
    }
    const flow = cashFlowAreas.value.find((area) => area.id === l.cashFlowAreaId);
    if ((l.cashFlowAreaId && !flow) || !cashFlowMatches(chosenAccount, flow ?? null)) {
      errorMessage.value = `Seleccione un área de flujo activa compatible con la cuenta en la línea #${i + 1}.`;
      return;
    }
    const d = parseAmount(l.debitLocal);
    const c = parseAmount(l.creditLocal);
    if (d.isZero() && c.isZero()) {
      errorMessage.value = `La línea #${i + 1} debe contener un importe mayor a cero.`;
      return;
    }
  }

  isSubmitting.value = true;
  try {
    const payload = {
      entry_date: entryDate.value,
      entry_number: entryNumber.value || undefined,
      entry_type: entryType.value,
      subdiary_code: subdiaryCode.value,
      currency: currency.value,
      exchange_rate: exchangeRate.value.trim() || '1',
      exchange_rate_type: exchangeRateType.value,
      exchange_rates: requiredCurrencyRates.value.map((r) => ({
        currency: r.code,
        buy_rate: r.buy_rate.trim() || '0',
        sell_rate: r.sell_rate.trim() || '0',
        rate: (exchangeRateType.value === 'BUY' ? r.buy_rate : r.sell_rate).trim() || '0',
      })),
      description: headerDescription.value.trim(),
      lines: lines.value.map((l) => ({
        accounting_account_id: l.accountId!,
        cost_center_id: l.costCenterId,
        entity_id: l.entity?.id ?? null,
        company_area_id: l.companyAreaId,
        cash_flow_area_id: l.cashFlowAreaId,
        description: l.description.trim() || headerDescription.value.trim(),
        debit_local: l.debitLocal.trim() || '0',
        credit_local: l.creditLocal.trim() || '0',
        debit_foreign: l.debitForeign.trim() || '0',
        credit_foreign: l.creditForeign.trim() || '0',
      })),
    };

    const payloadJson = JSON.stringify(payload);
    const key =
      pendingJournalAttempt?.companyId === props.activeCompanyId &&
      pendingJournalAttempt.payloadJson === payloadJson
        ? pendingJournalAttempt.key
        : crypto.randomUUID();
    pendingJournalAttempt = {
      companyId: props.activeCompanyId,
      payloadJson,
      key,
    };

    const savedEntry = await apiClient.value.createJournalEntry(payload, key);
    pendingJournalAttempt = null;

    // Limpiar antes de mostrar el éxito: clearForm también borra las alertas.
    clearForm();
    successMessage.value = `¡Asiento ${savedEntry.entry_number} asentado exitosamente en el Libro Diario!`;

    // El POST ya quedó confirmado. Si falla el GET del historial, no presentar
    // el guardado como fallido ni sugerir volver a registrar el mismo asiento.
    try {
      journalEntries.value = await apiClient.value.fetchJournalEntries({ limit: 50 });
    } catch {
      errorMessage.value = 'El asiento se registró, pero no se pudo actualizar el historial. Recargue la vista para consultarlo; no vuelva a registrarlo.';
    }
  } catch (e) {
    // Keep the key after an ambiguous network/server error, but not after
    // a conclusive client-side rejection (HTTP 400/409/422).
    if (e instanceof ApiHttpError && e.status >= 400 && e.status < 500) {
      pendingJournalAttempt = null;
    }
    errorMessage.value =
      e instanceof Error ? e.message : 'Error al registrar el asiento contable.';
  } finally {
    isSubmitting.value = false;
  }
}

function toggleExpandEntry(id: number): void {
  const current = new Set(expandedEntryIds.value);
  if (current.has(id)) {
    current.delete(id);
  } else {
    current.add(id);
  }
  expandedEntryIds.value = current;
}

watch(entryDate, (newDate) => {
  void fetchNextNumber();
  void loadExchangeRatesForDate(newDate);
});

watch(activeTab, (tab) => {
  if (tab === 'history' && !historyLoaded.value) void loadHistory();
});

watch(currency, () => {
  syncActiveVoucherExchangeRate();
});

watch(exchangeRateType, () => {
  syncActiveVoucherExchangeRate();
});

watch(
  () => props.activeCompanyId,
  () => {
    pendingJournalAttempt = null;
    void loadCatalogsAndHistory();
  },
);

onMounted(() => {
  void loadCatalogsAndHistory();
});
</script>

<template>
  <div class="space-y-6">
    <!-- Encabezado de Sección y Conmutador de Pestañas -->
    <div
      class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-border"
    >
      <div>
        <div class="flex items-center gap-2">
          <span
            class="w-8 h-8 rounded-md bg-brand/10 text-brand flex items-center justify-center"
          >
            <BookOpen class="w-4 h-4 text-brand" />
          </span>
          <h1 class="text-xl font-semibold tracking-tight text-foreground">
            Libro Diario · Voucher Contable Bimoneda
          </h1>
          <span
            class="tag ok font-mono text-[11px]"
          >
            <ShieldCheck class="w-3 h-3 inline mr-1" />
            ISO 27001 Inmutable
          </span>
        </div>
        <p class="text-xs text-muted mt-1">
          Registro transaccional de alta velocidad con partida doble en tiempo real (PEN / USD) y
          cuadre de 4 decimales.
        </p>
      </div>

      <!-- Conmutador de Pestañas -->
      <div class="inline-flex p-1 bg-surface-muted rounded-md border border-line">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer"
          :class="
            activeTab === 'form'
              ? 'bg-brand text-white shadow-xs font-semibold'
              : 'text-muted hover:text-foreground'
          "
          @click="activeTab = 'form'"
        >
          <Plus class="w-3.5 h-3.5" />
          <span>Nuevo Asiento (Voucher)</span>
        </button>

        <button
          type="button"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer"
          :class="
            activeTab === 'history'
              ? 'bg-brand text-white shadow-xs font-semibold'
              : 'text-muted hover:text-foreground'
          "
          @click="activeTab = 'history'"
        >
          <History class="w-3.5 h-3.5" />
          <span>Libro Diario ({{ journalEntries.length }})</span>
        </button>
      </div>
    </div>

    <!-- Alerta de Éxito -->
    <div
      v-if="successMessage"
      class="p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-700 dark:text-emerald-300 flex items-center justify-between text-xs font-medium"
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

    <!-- Alerta de Error -->
    <div
      v-if="errorMessage"
      class="p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-700 dark:text-rose-300 flex items-center justify-between text-xs font-medium"
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

    <!-- ======================================================== -->
    <!-- PESTAÑA 1: FORMULARIO DE INGRESO DE ASIENTO (VOUCHER)    -->
    <!-- ======================================================== -->
    <div v-if="activeTab === 'form'" class="space-y-6">
      <!-- Tarjeta de Parámetros de Cabecera -->
      <div
        class="panel space-y-4"
      >
        <div class="flex items-center justify-between border-b border-line pb-3">
          <span class="text-xs font-semibold uppercase tracking-wider text-muted flex items-center gap-2">
            <Layers class="w-3.5 h-3.5 text-brand" />
            Datos Generales del Asiento
          </span>

          <span class="tag ok font-mono text-[11px] flex items-center gap-1">
            <Clock class="w-3 h-3 text-pos" />
            Periodo Abierto
          </span>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-7 gap-3.5">
          <!-- Fecha Contable -->
          <div>
            <label class="block text-[11px] font-semibold text-muted mb-1">
              Fecha Contable
            </label>
            <input
              v-model="entryDate"
              type="date"
              required
              class="w-full px-3 py-1.5 text-xs font-mono rounded-md bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
            />
          </div>

          <!-- N.° Correlativo -->
          <div>
            <label class="block text-[11px] font-semibold text-muted mb-1">
              N.° Correlativo
            </label>
            <div class="relative">
              <input
                v-model="entryNumber"
                type="text"
                placeholder="01-00001"
                class="w-full pl-7 pr-3 py-1.5 text-xs num font-semibold rounded-md bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
              />
              <Hash class="w-3.5 h-3.5 text-muted absolute left-2 top-2" />
            </div>
          </div>

          <!-- Subdiario: clasificación independiente del tipo de asiento. -->
          <div>
            <label class="block text-[11px] font-semibold text-muted mb-1">
              Subdiario
            </label>
            <select v-model="subdiaryCode" :disabled="isLoading || isSubmitting"
              class="w-full px-3 py-1.5 text-xs rounded-md bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand disabled:opacity-50">
              <option value="">Seleccionar subdiario...</option>
              <option v-for="sub in subdiaries" :key="sub.code" :value="sub.code">
                {{ sub.name }}
              </option>
            </select>
          </div>

          <!-- Tipo de Asiento -->
          <div>
            <label class="block text-[11px] font-semibold text-muted mb-1">
              Tipo de Asiento
            </label>
            <select
              v-model="entryType"
              class="w-full px-3 py-1.5 text-xs rounded-md bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
            >
              <option value="STANDARD">STANDARD · Diario Normal</option>
              <option value="OPENING">OPENING · Asiento de Apertura</option>
              <option value="ADJUSTMENT">ADJUSTMENT · Ajuste Periódico</option>
              <option value="CLOSING">CLOSING · Cierre Contable</option>
            </select>
          </div>

          <!-- Moneda de Operación -->
          <div>
            <label class="block text-[11px] font-semibold text-muted mb-1">
              Moneda del Voucher
            </label>
            <select
              v-model="currency"
              class="w-full px-3 py-1.5 text-xs font-semibold rounded-md bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
            >
              <option
                v-for="curOption in availableVoucherCurrencies"
                :key="curOption.code"
                :value="curOption.code"
              >
                {{ curOption.label }}
              </option>
            </select>
          </div>

          <!-- Tipo de Cambio Efectivo (14,6) -->
          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="text-[11px] font-semibold text-muted">T.C. Efectivo</label>
              <span class="text-[10px] text-brand font-mono font-medium">
                {{ activeForeignCurrency.code }}/{{ baseCurrencyInfo.code }}
              </span>
            </div>
            <input
              v-model="exchangeRate"
              type="text"
              required
              class="w-full px-3 py-1.5 text-xs num rounded-md bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
            />
          </div>

          <!-- Criterio TC SUNAT -->
          <div>
            <label class="block text-[11px] font-semibold text-muted mb-1">
              Criterio TC SUNAT
            </label>
            <select
              v-model="exchangeRateType"
              class="w-full px-3 py-1.5 text-xs rounded-md bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
            >
              <option value="SELL">SUNAT Venta (Pasivo/Gasto)</option>
              <option value="BUY">SUNAT Compra (Activo/Ingreso)</option>
            </select>
          </div>
        </div>

        <!-- Franja compacta de tipos de cambio por moneda secundaria.
             Mantener ambos valores editables: el POST sigue exigiendo compra y venta. -->
        <div v-if="requiredCurrencyRates.length > 0"
          class="rounded-md border border-line bg-soft/50 px-3 py-2">
          <div class="flex flex-wrap items-center gap-2.5">
            <div class="flex items-center gap-1.5 text-xs font-semibold text-foreground"
              title="Compra y venta son obligatorias para cada moneda secundaria configurada">
              <Coins class="h-4 w-4 text-brand shrink-0" />
              <span>T.C. diario · {{ entryDate }}</span>
              <span class="text-[10px] font-normal text-muted">(base {{ baseCurrencyInfo.code }})</span>
              <Loader2 v-if="isLoadingRates" class="h-3 w-3 animate-spin text-brand" />
            </div>
            <div v-for="rateItem in requiredCurrencyRates" :key="rateItem.currency_id"
              class="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-md border bg-surf px-2 py-1"
              :class="currency === rateItem.code ? 'border-brand/60' : 'border-line'">
              <span class="text-xs font-semibold text-foreground">{{ rateItem.code }}</span>
              <label class="flex items-center gap-1 text-[10px] text-muted">
                Compra
                <input :value="rateItem.buy_rate" type="number" step="0.000001" min="0.000001" required
                  :aria-label="`Tipo de cambio compra ${rateItem.code}`"
                  class="w-[92px] rounded border border-line bg-soft px-1.5 py-1 text-xs font-semibold num text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
                  @input="updateRateForCurrency(rateItem.code, 'buy_rate', ($event.target as HTMLInputElement).value)" />
              </label>
              <label class="flex items-center gap-1 text-[10px] text-muted">
                Venta
                <input :value="rateItem.sell_rate" type="number" step="0.000001" min="0.000001" required
                  :aria-label="`Tipo de cambio venta ${rateItem.code}`"
                  class="w-[92px] rounded border border-line bg-soft px-1.5 py-1 text-xs font-semibold num text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
                  @input="updateRateForCurrency(rateItem.code, 'sell_rate', ($event.target as HTMLInputElement).value)" />
              </label>
              <span class="text-[10px] font-medium"
                :class="rateItem.is_recorded_for_date ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300'">
                {{ rateItem.is_recorded_for_date ? '✓ En BD' : 'Pendiente' }}
              </span>
            </div>
          </div>
        </div>

        <!-- Glosa / Descripción General -->
        <div>
          <label class="block text-[11px] font-semibold text-muted mb-1">
            Glosa / Explicación del Asiento
          </label>
          <input
            v-model="headerDescription"
            type="text"
            required
            placeholder="Ej: Por la provisión de servicios contables según Factura F001-000492"
            class="w-full px-3.5 py-1.5 text-xs rounded-md bg-surf border border-line text-foreground placeholder-muted focus:outline-none focus:ring-1 focus:ring-brand"
          />
        </div>
      </div>

      <!-- Rejilla Dinámica de Líneas (Partida Doble) -->
      <div
        class="panel p-0 overflow-hidden"
      >
        <div class="p-4 border-b border-line flex items-center justify-between">
          <div class="flex items-center gap-2">
            <span class="text-xs font-semibold uppercase tracking-wider text-muted">
              Líneas del Asiento ({{ lines.length }})
            </span>
            <span class="text-[11px] text-muted num">
              · Mínimo 2 líneas operativas
            </span>
          </div>

          <div class="flex items-center gap-2">
            <button
              type="button"
              class="inline-flex items-center gap-1.5 px-3 py-1 rounded-md text-xs font-semibold bg-surf border border-line text-foreground hover:bg-soft transition-colors cursor-pointer"
              title="Calcula y completa la diferencia en el Debe o Haber"
              @click="autoBalance"
            >
              <Sparkles class="w-3.5 h-3.5 text-accent" />
              <span>Balancear Asiento</span>
            </button>

            <button
              type="button"
              class="inline-flex items-center gap-1.5 px-3 py-1 rounded-md text-xs font-semibold bg-brand text-white hover:opacity-90 transition-opacity cursor-pointer"
              @click="addLine"
            >
              <Plus class="w-3.5 h-3.5" />
              <span>Agregar Línea</span>
            </button>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-left border-collapse min-w-[1080px]">
            <thead>
              <tr class="bg-surface-muted border-b border-border text-[11px] font-bold text-muted uppercase">
                <th class="py-2.5 px-3 w-10 text-center">#</th>
                <th class="py-2.5 px-3 w-[340px]">Cuenta Contable Operativa</th>
                <th class="py-2.5 px-3 w-44" title="Obligatorio solo si la cuenta contable lo exige; no genera transferencia automática">Centro de costo</th>
                <th class="py-2.5 px-3 w-64">Anexo / Tercero</th>
                <th class="py-2.5 px-3 w-64">Áreas</th>
                <th class="py-2.5 px-3">Glosa Específica</th>
                <th class="py-2.5 px-3 w-36 text-right">Debe ({{ baseCurrencyInfo.code }})</th>
                <th class="py-2.5 px-3 w-36 text-right">Haber ({{ baseCurrencyInfo.code }})</th>
                <th class="py-2.5 px-3 w-28 text-right text-muted font-mono">Debe ({{ activeForeignCurrency.code }})</th>
                <th class="py-2.5 px-3 w-28 text-right text-muted font-mono">Haber ({{ activeForeignCurrency.code }})</th>
                <th class="py-2.5 px-2 w-12 text-center"></th>
              </tr>
            </thead>

            <tbody class="divide-y divide-border text-xs">
              <tr
                v-for="(line, idx) in lines"
                :key="line.id"
                class="hover:bg-surface-muted/50 transition-colors"
              >
                <!-- Número de Línea -->
                <td class="py-2 px-3 text-center font-mono text-muted text-[11px]">
                  {{ idx + 1 }}
                </td>

                <!-- Autocomplete por código o nombre: máximo 40 opciones visibles. -->
                <td class="py-2 px-3">
                  <JournalAccountCombobox
                    v-model="line.accountId"
                    :accounts="operationalAccounts"
                    :disabled="isSubmitting || isLoading"
                    @update:model-value="resetAccountDimensions(line)"
                  />
                </td>

                <!-- Cada dimensión se habilita solo si la configuración de la cuenta la exige. -->
                <td class="py-2 px-3">
                  <template v-if="(operationalAccounts.find(a => a.id === line.accountId)?.requires_cost_center || operationalAccounts.find(a => a.id === line.accountId)?.has_automatic_destination)">
                    <label class="block text-[10px] font-semibold text-amber-700 dark:text-amber-300 mb-1">Centro obligatorio</label>
                    <select v-model="line.costCenterId" :disabled="isSubmitting"
                      class="w-full px-2 py-1.5 text-xs rounded-md bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand">
                      <option :value="null">Seleccionar centro obligatorio</option>
                      <option v-for="cc in costCenters.filter(cc => cc.is_active)" :key="cc.id" :value="cc.id">
                        {{ cc.code }} · {{ cc.name }}
                      </option>
                    </select>
                  </template>
                  <span v-else class="text-[11px] text-muted">—</span>
                </td>

                <td class="py-2 px-3">
                  <JournalEntityPicker
                    v-if="entityRequired(operationalAccounts.find(a => a.id === line.accountId))"
                    :key="`${line.id}-${line.accountId ?? 'none'}`"
                    v-model="line.entity" :api="apiClient"
                    :account="operationalAccounts.find((account) => account.id === line.accountId)"
                    :disabled="isSubmitting" />
                  <span v-else class="text-[11px] text-muted">—</span>
                </td>

                <td class="py-2 px-3 min-w-60">
                  <label v-if="operationalAccounts.find(a => a.id === line.accountId)?.requires_company_area"
                    class="block text-[11px] text-muted">
                    Área organizativa (obligatoria)
                    <select v-model="line.companyAreaId" :disabled="isSubmitting"
                      class="mt-1 w-full rounded-md border border-line bg-surf px-2 py-1.5 text-xs text-foreground">
                      <option :value="null">Seleccionar área organizativa</option>
                      <option v-for="area in companyAreas.filter(area => area.is_active)" :key="area.id" :value="area.id">
                        {{ area.code }} · {{ area.name }}
                      </option>
                    </select>
                  </label>
                  <label v-if="operationalAccounts.find(a => a.id === line.accountId)?.requires_cash_flow_area"
                    class="block text-[11px] text-muted"
                    :class="operationalAccounts.find(a => a.id === line.accountId)?.requires_company_area ? 'mt-2' : ''">
                    Área de flujo de efectivo (obligatoria)
                    <select v-model="line.cashFlowAreaId" :disabled="isSubmitting"
                      class="mt-1 w-full rounded-md border border-line bg-surf px-2 py-1.5 text-xs text-foreground">
                      <option :value="null">Seleccionar flujo obligatorio</option>
                      <option v-for="area in cashFlowAreas.filter(area => cashFlowMatches(operationalAccounts.find(a => a.id === line.accountId), area))"
                        :key="area.id" :value="area.id">
                        {{ area.code }} · {{ area.name }}
                      </option>
                    </select>
                  </label>
                  <span v-if="!operationalAccounts.find(a => a.id === line.accountId)?.requires_company_area &&
                    !operationalAccounts.find(a => a.id === line.accountId)?.requires_cash_flow_area"
                    class="text-[11px] text-muted">—</span>
                </td>

                <!-- Glosa de Línea -->
                <td class="py-2 px-3">
                  <input
                    v-model="line.description"
                    type="text"
                    :placeholder="headerDescription || 'Misma glosa general'"
                    class="w-full px-2.5 py-1.5 text-xs rounded-md bg-surf border border-line text-foreground placeholder-muted focus:outline-none focus:ring-1 focus:ring-brand"
                  />
                </td>

                <!-- Debe PEN -->
                <td class="py-2 px-3">
                  <input
                    :value="line.debitLocal"
                    type="text"
                    inputmode="decimal"
                    autocomplete="off"
                    placeholder="0.00"
                    class="w-full px-2.5 py-1.5 text-xs num text-right font-semibold rounded-md bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
                    @input="onAmountChange(line, 'debit', ($event.target as HTMLInputElement).value)"
                  />
                </td>

                <!-- Haber PEN -->
                <td class="py-2 px-3">
                  <input
                    :value="line.creditLocal"
                    type="text"
                    inputmode="decimal"
                    autocomplete="off"
                    placeholder="0.00"
                    class="w-full px-2.5 py-1.5 text-xs num text-right font-semibold rounded-md bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
                    @input="onAmountChange(line, 'credit', ($event.target as HTMLInputElement).value)"
                  />
                </td>

                <!-- Debe Extranjera (Calculado) -->
                <td class="py-2 px-3 text-right num text-muted text-[11px]">
                  {{ line.debitForeign ? `${activeForeignCurrency.symbol} ${line.debitForeign}` : '—' }}
                </td>

                <!-- Haber Extranjera (Calculado) -->
                <td class="py-2 px-3 text-right num text-muted text-[11px]">
                  {{ line.creditForeign ? `${activeForeignCurrency.symbol} ${line.creditForeign}` : '—' }}
                </td>

                <!-- Eliminar Línea -->
                <td class="py-2 px-2 text-center">
                  <button
                    type="button"
                    class="p-1 rounded-md text-muted hover:text-neg hover:bg-red-500/10 transition-colors disabled:opacity-20 cursor-pointer"
                    :disabled="lines.length <= 2"
                    title="Eliminar línea"
                    @click="removeLine(idx)"
                  >
                    <Trash2 class="w-3.5 h-3.5" />
                  </button>
                </td>
              </tr>
            </tbody>

            <!-- Fila de Totales y Diferencias -->
            <tfoot>
              <tr class="bg-soft border-t-2 border-line text-xs">
                <td colspan="6" class="py-2.5 px-4 font-semibold text-foreground text-right uppercase tracking-wider">
                  Totales Asiento:
                </td>
                <td class="py-2.5 px-3 text-right font-semibold num text-foreground">
                  {{ baseCurrencyInfo.symbol }} {{ totalDebitLocal.toDisplay(2) }}
                </td>
                <td class="py-2.5 px-3 text-right font-semibold num text-foreground">
                  {{ baseCurrencyInfo.symbol }} {{ totalCreditLocal.toDisplay(2) }}
                </td>
                <td class="py-2.5 px-3 text-right text-muted font-medium num text-[11px]">
                  {{ activeForeignCurrency.symbol }} {{ totalDebitForeign.toDisplay(2) }}
                </td>
                <td class="py-2.5 px-3 text-right text-muted font-medium num text-[11px]">
                  {{ activeForeignCurrency.symbol }} {{ totalCreditForeign.toDisplay(2) }}
                </td>
                <td></td>
              </tr>

              <!-- Fila de Diferencia (Cuadre) -->
              <tr class="bg-surf text-xs">
                <td colspan="6" class="py-2 px-4 text-right font-semibold text-muted">
                  Diferencia Partida Doble:
                </td>
                <td
                  colspan="2"
                  class="py-2 px-3 text-center font-bold num"
                  :class="diffLocal.isZero() ? 'text-pos' : 'text-neg'"
                >
                  {{ diffLocal.isZero() ? `${baseCurrencyInfo.symbol} 0.00 (Cuadrado)` : `${baseCurrencyInfo.symbol} ${diffLocal.toDisplay(2)} (Descuadre)` }}
                </td>
                <td
                  colspan="2"
                  class="py-2 px-3 text-center font-bold num text-[11px]"
                  :class="diffForeign.isZero() ? 'text-pos' : 'text-neg'"
                >
                  {{ diffForeign.isZero() ? `${activeForeignCurrency.symbol} 0.00` : `${activeForeignCurrency.symbol} ${diffForeign.toDisplay(2)}` }}
                </td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- Barra de Estado de Balance y Acciones Finales -->
      <div
        class="panel flex flex-col sm:flex-row sm:items-center justify-between gap-4"
        :class="isBalanced ? 'border-pos/50 bg-pos/5' : 'border-warn/50 bg-warn/5'"
      >
        <div class="flex items-center gap-3">
          <div
            class="w-9 h-9 rounded-md flex items-center justify-center shrink-0"
            :class="isBalanced ? 'bg-pos/20 text-pos' : 'bg-warn/20 text-warn'"
          >
            <CheckCircle2 v-if="isBalanced" class="w-5 h-5 text-pos" />
            <AlertTriangle v-else class="w-5 h-5 text-warn" />
          </div>

          <div>
            <p class="text-xs font-semibold text-foreground">
              {{ isBalanced ? 'Asiento Contable Listo para Asentar' : 'Asiento Incompleto o Descuadrado' }}
            </p>
            <p class="text-[11px] text-muted">
              {{
                isBalanced
                  ? 'Cumple con los principios de Partida Doble en Soles y Dólares. Se grabará de forma inmutable.'
                  : 'Para asentar, verifique que la diferencia sea S/ 0.00 y que todas las cuentas estén seleccionadas.'
              }}
            </p>
          </div>
        </div>

        <div class="flex items-center gap-2.5">
          <button
            type="button"
            class="px-4 py-2 rounded-md border border-line text-xs font-medium text-muted hover:text-foreground bg-surf transition-colors cursor-pointer"
            @click="clearForm"
          >
            Limpiar
          </button>

          <button
            type="button"
            :disabled="!isBalanced || isSubmitting"
            class="inline-flex items-center justify-center gap-2 px-5 py-2 rounded-md text-xs font-semibold text-white bg-brand hover:opacity-95 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer transition-all"
            @click="handleSubmit"
          >
            <Loader2 v-if="isSubmitting" class="w-4 h-4 animate-spin" />
            <Save v-else class="w-4 h-4 text-white" />
            <span>{{ isSubmitting ? 'Asentando en Libro Diario...' : 'Asentar Asiento (Post Journal)' }}</span>
          </button>
        </div>
      </div>
    </div>

    <!-- ======================================================== -->
    <!-- PESTAÑA 2: HISTORIAL DEL LIBRO DIARIO                    -->
    <!-- ======================================================== -->
    <div v-else class="space-y-4">
      <div
        class="panel p-0 overflow-hidden"
      >
        <div class="p-4 border-b border-line flex items-center justify-between">
          <div class="flex items-center gap-2">
            <span class="text-xs font-semibold uppercase tracking-wider text-muted">
              Asientos Registrados en el Periodo ({{ journalEntries.length }})
            </span>
            <span class="text-[11px] text-muted font-mono">
              · Trazabilidad Inmutable ISO/IEC 27001
            </span>
          </div>

          <button
            type="button"
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium border border-line bg-surf hover:bg-soft transition-colors cursor-pointer"
            @click="loadHistory"
          >
            <RefreshCw class="w-3.5 h-3.5 text-muted" />
            <span>Actualizar</span>
          </button>
        </div>

        <div v-if="isLoadingHistory" class="p-12 text-center text-sm text-muted" role="status">
          <Loader2 class="w-5 h-5 animate-spin mx-auto mb-2 text-brand" />
          Cargando Libro Diario…
        </div>
        <div v-else-if="journalEntries.length === 0" class="p-12 text-center">
          <BookOpen class="w-10 h-10 text-muted mx-auto mb-3 opacity-40" />
          <p class="text-sm font-semibold text-foreground">No hay asientos contables registrados</p>
          <p class="text-xs text-muted mt-1">
            Utiliza la pestaña "Nuevo Asiento (Voucher)" para ingresar el primer asiento de este ejercicio.
          </p>
          <button
            type="button"
            class="mt-4 px-4 py-2 rounded-md text-xs font-semibold bg-brand text-white hover:opacity-95 cursor-pointer"
            @click="activeTab = 'form'"
          >
            Crear Primer Asiento
          </button>
        </div>

        <div v-else class="overflow-x-auto">
          <table class="w-full text-left border-collapse min-w-[800px]">
            <thead>
              <tr class="bg-surface-muted border-b border-border text-[11px] font-bold text-muted uppercase">
                <th class="py-2.5 px-3 w-10"></th>
                <th class="py-2.5 px-3 w-28">N.° Asiento</th>
                <th class="py-2.5 px-3 w-24">Fecha</th>
                <th class="py-2.5 px-3 w-24">Tipo</th>
                <th class="py-2.5 px-3">Glosa del Asiento</th>
                <th class="py-2.5 px-3 w-16 text-center">Moneda</th>
                <th class="py-2.5 px-3 w-28 text-right">T.C.</th>
                <th class="py-2.5 px-3 w-24 text-center">Estado</th>
              </tr>
            </thead>

            <tbody class="divide-y divide-border text-xs">
              <template v-for="entry in journalEntries" :key="entry.id">
                <tr
                  class="hover:bg-surface-muted/50 transition-colors cursor-pointer"
                  @click="toggleExpandEntry(entry.id)"
                >
                  <td class="py-2.5 px-3 text-center">
                    <ChevronDown
                      v-if="expandedEntryIds.has(entry.id)"
                      class="w-4 h-4 text-brand"
                    />
                    <ChevronUp v-else class="w-4 h-4 text-muted" />
                  </td>

                  <td class="py-2.5 px-3 num font-semibold text-foreground">
                    {{ entry.entry_number }}
                  </td>

                  <td class="py-2.5 px-3 num text-muted">
                    {{ entry.entry_date }}
                  </td>

                  <td class="py-2.5 px-3">
                    <span
                      class="tag font-mono text-[10px] text-muted border-line bg-soft"
                    >
                      {{ entry.entry_type }} · {{ subdiaries.find(s => s.code === entry.subdiary_code)?.name ?? entry.subdiary_code ?? 'General' }}
                    </span>
                  </td>

                  <td class="py-2.5 px-3 text-foreground font-medium">
                    {{ entry.description }}
                  </td>

                  <td class="py-2.5 px-3 text-center font-mono font-semibold">
                    {{ entry.currency }}
                  </td>

                  <td class="py-2.5 px-3 text-right num text-muted">
                    {{ entry.exchange_rate }}
                  </td>

                  <td class="py-2.5 px-3 text-center">
                    <span
                      class="tag text-[10px]"
                      :class="entry.status === 'POSTED' ? 'ok' : 'er'"
                    >
                      {{ entry.status }}
                    </span>
                  </td>
                </tr>

                <!-- Detalle Desplegable de Líneas del Asiento -->
                <tr v-if="expandedEntryIds.has(entry.id)" class="bg-soft/40">
                  <td colspan="8" class="p-4">
                    <div class="rounded-md border border-line bg-surf overflow-hidden">
                      <div class="px-3 py-2 bg-soft border-b border-line text-[11px] font-semibold text-muted uppercase">
                        Detalle de Cuentas Imputadas ({{ entry.lines.length }} Líneas)
                      </div>
                      <table class="w-full text-left text-xs">
                        <thead>
                          <tr class="border-b border-line text-[10px] text-muted uppercase font-mono">
                            <th class="py-1.5 px-3 w-10 text-center">#</th>
                            <th class="py-1.5 px-3 w-40">Cuenta</th>
                            <th class="py-1.5 px-3 w-40">Centro de costo</th>
                            <th class="py-1.5 px-3">Anexo</th>
                            <th class="py-1.5 px-3">Áreas</th>
                            <th class="py-1.5 px-3">Glosa de Línea</th>
                            <th class="py-1.5 px-3 w-32 text-right">Debe (PEN)</th>
                            <th class="py-1.5 px-3 w-32 text-right">Haber (PEN)</th>
                            <th class="py-1.5 px-3 w-28 text-right font-mono">Debe (USD)</th>
                            <th class="py-1.5 px-3 w-28 text-right font-mono">Haber (USD)</th>
                          </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                          <tr v-for="l in entry.lines" :key="l.id" class="text-xs">
                            <td class="py-1.5 px-3 text-center text-muted num text-[11px]">{{ l.line_number }}</td>
                            <td class="py-1.5 px-3 font-semibold text-foreground">
                              {{ l.account ? `${l.account.code} · ${l.account.name}` : `ID ${l.accounting_account_id}` }}
                            </td>
                            <td class="py-1.5 px-3 text-muted text-[11px]">{{ l.cost_center?.code || costCenters.find((cc) => cc.id === l.cost_center_id)?.code || '—' }}</td>
                            <td class="py-1.5 px-3 text-xs">{{ l.entity ? `${l.entity.tax_id} · ${l.entity.legal_name}` : l.entity_id ? `Anexo ${l.entity_id}` : '—' }}</td>
                            <td class="py-1.5 px-3 text-xs">
                              <div>{{ l.company_area ? `${l.company_area.code} · ${l.company_area.name}` : l.company_area_id ? `Área ${l.company_area_id}` : 'Sin área organizativa' }}</div>
                              <div class="text-muted">{{ l.cash_flow_area ? `${l.cash_flow_area.code} · ${l.cash_flow_area.name}` : l.cash_flow_area_id ? `Flujo ${l.cash_flow_area_id}` : 'Sin área de flujo' }}</div>
                            </td>
                            <td class="py-1.5 px-3 text-muted text-xs">{{ l.description || entry.description }}</td>
                            <td class="py-1.5 px-3 text-right font-medium num">
                              {{ formatPostedAmount(l.debit_local) }}
                            </td>
                            <td class="py-1.5 px-3 text-right font-medium num">
                              {{ formatPostedAmount(l.credit_local) }}
                            </td>
                            <td class="py-1.5 px-3 text-right text-muted num text-[11px]">
                              {{ formatPostedAmount(l.debit_foreign, '$ ') }}
                            </td>
                            <td class="py-1.5 px-3 text-right text-muted num text-[11px]">
                              {{ formatPostedAmount(l.credit_foreign, '$ ') }}
                            </td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>
