<script lang="ts">
import type { OperationalAccount } from '../types/journalEntry.types';

// Caché en memoria a nivel módulo: evita descargar 1,200 cuentas (329 kB) en cada navegación
const operationalAccountsCache = new Map<number, readonly OperationalAccount[]>();
</script>

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
import { AccountingApiClient } from '../api/httpClient';
import type { CompanyId } from '../types/accountingAccount.types';
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

// Campos de Cabecera del Voucher
const todayStr = new Date().toISOString().slice(0, 10);
const entryDate = ref(todayStr);
const entryNumber = ref('');
const entryType = ref<EntryType>('STANDARD');
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

function recalculateForeignAmounts(): void {
  const tc = parseFloat(exchangeRate.value) || 1;
  for (const line of lines.value) {
    if (line.debitLocal) {
      const localVal = parseFloat(line.debitLocal) || 0;
      line.debitForeign = (localVal / tc).toFixed(4);
    }
    if (line.creditLocal) {
      const localVal = parseFloat(line.creditLocal) || 0;
      line.creditForeign = (localVal / tc).toFixed(4);
    }
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
    description: '',
    debitLocal: '',
    creditLocal: '',
    debitForeign: '',
    creditForeign: '',
  },
  {
    id: nextLineKey++,
    accountId: null,
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

// ==========================================
// CÁLCULOS Y TOTALES EN VIVO (PARTIDA DOBLE)
// ==========================================

function parseAmount(val: string): number {
  const n = parseFloat(val);
  return isNaN(n) ? 0 : n;
}

const totalDebitLocal = computed(() =>
  lines.value.reduce((acc, l) => acc + parseAmount(l.debitLocal), 0),
);

const totalCreditLocal = computed(() =>
  lines.value.reduce((acc, l) => acc + parseAmount(l.creditLocal), 0),
);

const totalDebitForeign = computed(() =>
  lines.value.reduce((acc, l) => acc + parseAmount(l.debitForeign), 0),
);

const totalCreditForeign = computed(() =>
  lines.value.reduce((acc, l) => acc + parseAmount(l.creditForeign), 0),
);

const diffLocal = computed(() =>
  Math.abs(totalDebitLocal.value - totalCreditLocal.value),
);

const diffForeign = computed(() =>
  Math.abs(totalDebitForeign.value - totalCreditForeign.value),
);

const isBalanced = computed(
  () =>
    lines.value.length >= 2 &&
    totalDebitLocal.value > 0 &&
    diffLocal.value < 0.001 &&
    diffForeign.value < 0.001,
);

// ==========================================
// MÉTODOS DE FORMULARIO
// ==========================================

function addLine(): void {
  lines.value.push({
    id: nextLineKey++,
    accountId: null,
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

function onAmountChange(line: EditingLine, side: 'debit' | 'credit'): void {
  const tc = parseFloat(exchangeRate.value) || 1;

  if (side === 'debit') {
    if (line.debitLocal) {
      line.creditLocal = '';
      line.creditForeign = '';
      const localVal = parseFloat(line.debitLocal) || 0;
      line.debitForeign = (localVal / tc).toFixed(4);
    } else {
      line.debitForeign = '';
    }
  } else {
    if (line.creditLocal) {
      line.debitLocal = '';
      line.debitForeign = '';
      const localVal = parseFloat(line.creditLocal) || 0;
      line.creditForeign = (localVal / tc).toFixed(4);
    } else {
      line.creditForeign = '';
    }
  }
}

/**
 * Cuadre automático: calcula la diferencia y la asigna a la última línea o agrega una nueva
 */
function autoBalance(): void {
  const tc = parseFloat(exchangeRate.value) || 1;
  const dLocal = totalDebitLocal.value;
  const cLocal = totalCreditLocal.value;

  if (dLocal === cLocal && dLocal > 0) return;

  const diff = dLocal - cLocal;
  if (Math.abs(diff) < 0.0001) return;

  // Si la última línea está vacía en importes, usarla; si no, agregar una nueva
  let target = lines.value[lines.value.length - 1];
  if (!target || target.debitLocal || target.creditLocal) {
    addLine();
    target = lines.value[lines.value.length - 1]!;
  }

  if (diff > 0) {
    // Falta en el Haber
    target.creditLocal = diff.toFixed(4);
    target.creditForeign = (diff / tc).toFixed(4);
    target.debitLocal = '';
    target.debitForeign = '';
  } else {
    // Falta en el Debe
    const absDiff = Math.abs(diff);
    target.debitLocal = absDiff.toFixed(4);
    target.debitForeign = (absDiff / tc).toFixed(4);
    target.creditLocal = '';
    target.creditForeign = '';
  }
}

function clearForm(): void {
  headerDescription.value = '';
  lines.value = [
    {
      id: nextLineKey++,
      accountId: null,
      description: '',
      debitLocal: '',
      creditLocal: '',
      debitForeign: '',
      creditForeign: '',
    },
    {
      id: nextLineKey++,
      accountId: null,
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

async function loadCatalogsAndHistory(): Promise<void> {
  isLoading.value = true;
  errorMessage.value = null;
  try {
    const cachedAccounts = operationalAccountsCache.get(props.activeCompanyId);
    if (cachedAccounts && cachedAccounts.length > 0) {
      operationalAccounts.value = cachedAccounts;
    }

    const [accounts, entries, nextNum, ratesRes] = await Promise.all([
      cachedAccounts ? Promise.resolve(cachedAccounts) : apiClient.value.fetchOperationalAccounts(),
      apiClient.value.fetchJournalEntries({ limit: 50 }),
      apiClient.value.fetchNextJournalEntryNumber(entryDate.value),
      apiClient.value.fetchCompanyExchangeRates(entryDate.value).catch(() => null),
    ]);

    operationalAccounts.value = accounts;
    operationalAccountsCache.set(props.activeCompanyId, accounts);
    journalEntries.value = entries;
    entryNumber.value = nextNum.next_number;

    if (ratesRes) {
      baseCurrencyInfo.value = ratesRes.base_currency;
      requiredCurrencyRates.value = ratesRes.required_currencies.map((c) => ({
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
    }
  } catch (e) {
    errorMessage.value =
      e instanceof Error ? e.message : 'Error al cargar los catálogos de asientos.';
  } finally {
    isLoading.value = false;
  }
}

async function handleSubmit(): Promise<void> {
  errorMessage.value = null;
  successMessage.value = null;

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
    const buy = parseFloat(secRate.buy_rate);
    const sell = parseFloat(secRate.sell_rate);
    if (isNaN(buy) || buy <= 0 || isNaN(sell) || sell <= 0) {
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
    const d = parseFloat(l.debitLocal) || 0;
    const c = parseFloat(l.creditLocal) || 0;
    if (d === 0 && c === 0) {
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
        description: l.description.trim() || headerDescription.value.trim(),
        debit_local: l.debitLocal.trim() || '0',
        credit_local: l.creditLocal.trim() || '0',
        debit_foreign: l.debitForeign.trim() || '0',
        credit_foreign: l.creditForeign.trim() || '0',
      })),
    };

    const savedEntry = await apiClient.value.createJournalEntry(payload);
    successMessage.value = `¡Asiento ${savedEntry.entry_number} asentado exitosamente en el Libro Diario!`;

    // Recargar historial y limpiar formulario
    clearForm();
    const updatedEntries = await apiClient.value.fetchJournalEntries({ limit: 50 });
    journalEntries.value = updatedEntries;
  } catch (e) {
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

watch(currency, () => {
  syncActiveVoucherExchangeRate();
});

watch(exchangeRateType, () => {
  syncActiveVoucherExchangeRate();
});

watch(
  () => props.activeCompanyId,
  () => {
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

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3.5">
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

        <!-- Panel de Tipos de Cambio Diarios Obligatorios por Empresa (Multi-Divisa) -->
        <div
          v-if="requiredCurrencyRates.length > 0"
          class="rounded-lg bg-soft/60 border border-line p-3.5 space-y-3"
        >
          <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-line pb-2.5">
            <div class="flex items-center gap-2">
              <Coins class="w-4 h-4 text-brand" />
              <span class="text-xs font-semibold uppercase tracking-wider text-foreground">
                Tipos de Cambio Diarios Obligatorios ({{ requiredCurrencyRates.length }} {{ requiredCurrencyRates.length === 1 ? 'Moneda Secundaria' : 'Monedas Secundarias' }})
              </span>
              <span class="tag ok font-mono text-[10px]">
                Base: {{ baseCurrencyInfo.code }}
              </span>
            </div>
            <div class="flex items-center gap-2">
              <span v-if="isLoadingRates" class="flex items-center gap-1 text-[11px] text-muted">
                <Loader2 class="w-3 h-3 animate-spin text-brand" />
                Consultando...
              </span>
              <span class="text-[11px] text-muted font-mono">
                Fecha Voucher: {{ entryDate }}
              </span>
            </div>
          </div>

          <p class="text-[11px] text-muted">
            Por normativa contable y configuración multi-divisa de la empresa, es obligatorio especificar el tipo de cambio oficial de compra y venta para cada moneda secundaria antes de registrar el asiento contable.
          </p>

          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
            <div
              v-for="rateItem in requiredCurrencyRates"
              :key="rateItem.currency_id"
              :class="[
                'p-3 rounded-md bg-surf border space-y-2 transition-all',
                currency === rateItem.code
                  ? 'border-brand shadow-xs ring-1 ring-brand/30'
                  : 'border-line',
              ]"
            >
              <div class="flex items-center justify-between">
                <span class="font-semibold text-xs text-foreground flex items-center gap-1.5">
                  <span class="w-5 h-5 rounded-full bg-brand/10 text-brand font-mono font-bold flex items-center justify-center text-[10px]">
                    {{ rateItem.symbol }}
                  </span>
                  {{ rateItem.code }} · {{ rateItem.name }}
                </span>

                <div class="flex items-center gap-1">
                  <span
                    v-if="currency === rateItem.code"
                    class="tag ok font-mono text-[9px]"
                  >
                    Voucher
                  </span>
                  <span
                    :class="[
                      'tag font-mono text-[10px]',
                      rateItem.is_recorded_for_date ? 'ok' : 'wa',
                    ]"
                  >
                    {{ rateItem.is_recorded_for_date ? '✓ En BD' : '⚠️ Pendiente' }}
                  </span>
                </div>
              </div>

              <div class="grid grid-cols-2 gap-2 text-xs">
                <div>
                  <label class="block text-[10px] font-semibold text-muted mb-0.5">
                    T.C. Compra (14,6)
                  </label>
                  <input
                    :value="rateItem.buy_rate"
                    type="number"
                    step="0.0001"
                    min="0.0001"
                    required
                    class="w-full px-2 py-1 text-xs num rounded bg-soft border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand font-semibold"
                    @input="updateRateForCurrency(rateItem.code, 'buy_rate', ($event.target as HTMLInputElement).value)"
                  />
                </div>

                <div>
                  <label class="block text-[10px] font-semibold text-muted mb-0.5">
                    T.C. Venta (14,6)
                  </label>
                  <input
                    :value="rateItem.sell_rate"
                    type="number"
                    step="0.0001"
                    min="0.0001"
                    required
                    class="w-full px-2 py-1 text-xs num rounded bg-soft border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand font-semibold"
                    @input="updateRateForCurrency(rateItem.code, 'sell_rate', ($event.target as HTMLInputElement).value)"
                  />
                </div>
              </div>
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
          <table class="w-full text-left border-collapse min-w-[900px]">
            <thead>
              <tr class="bg-surface-muted border-b border-border text-[11px] font-bold text-muted uppercase">
                <th class="py-2.5 px-3 w-10 text-center">#</th>
                <th class="py-2.5 px-3 w-[340px]">Cuenta Contable Operativa</th>
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

                <!-- Selector de Cuenta Operativa -->
                <td class="py-2 px-3">
                  <select
                    v-model="line.accountId"
                    class="w-full px-2.5 py-1.5 text-xs font-mono rounded-md bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
                  >
                    <option :value="null">-- Seleccionar Cuenta Operativa --</option>
                    <option
                      v-for="acc in operationalAccounts"
                      :key="acc.id"
                      :value="acc.id"
                    >
                      {{ acc.code }} · {{ acc.name }}
                    </option>
                  </select>
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
                    v-model="line.debitLocal"
                    type="number"
                    step="0.01"
                    min="0"
                    placeholder="0.00"
                    class="w-full px-2.5 py-1.5 text-xs num text-right font-semibold rounded-md bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
                    @input="onAmountChange(line, 'debit')"
                  />
                </td>

                <!-- Haber PEN -->
                <td class="py-2 px-3">
                  <input
                    v-model="line.creditLocal"
                    type="number"
                    step="0.01"
                    min="0"
                    placeholder="0.00"
                    class="w-full px-2.5 py-1.5 text-xs num text-right font-semibold rounded-md bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
                    @input="onAmountChange(line, 'credit')"
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
                <td colspan="3" class="py-2.5 px-4 font-semibold text-foreground text-right uppercase tracking-wider">
                  Totales Asiento:
                </td>
                <td class="py-2.5 px-3 text-right font-semibold num text-foreground">
                  {{ baseCurrencyInfo.symbol }} {{ totalDebitLocal.toFixed(2) }}
                </td>
                <td class="py-2.5 px-3 text-right font-semibold num text-foreground">
                  {{ baseCurrencyInfo.symbol }} {{ totalCreditLocal.toFixed(2) }}
                </td>
                <td class="py-2.5 px-3 text-right text-muted font-medium num text-[11px]">
                  {{ activeForeignCurrency.symbol }} {{ totalDebitForeign.toFixed(2) }}
                </td>
                <td class="py-2.5 px-3 text-right text-muted font-medium num text-[11px]">
                  {{ activeForeignCurrency.symbol }} {{ totalCreditForeign.toFixed(2) }}
                </td>
                <td></td>
              </tr>

              <!-- Fila de Diferencia (Cuadre) -->
              <tr class="bg-surf text-xs">
                <td colspan="3" class="py-2 px-4 text-right font-semibold text-muted">
                  Diferencia Partida Doble:
                </td>
                <td
                  colspan="2"
                  class="py-2 px-3 text-center font-bold num"
                  :class="diffLocal < 0.001 ? 'text-pos' : 'text-neg'"
                >
                  {{ diffLocal < 0.001 ? `${baseCurrencyInfo.symbol} 0.00 (Cuadrado)` : `${baseCurrencyInfo.symbol} ${diffLocal.toFixed(2)} (Descuadre)` }}
                </td>
                <td
                  colspan="2"
                  class="py-2 px-3 text-center font-bold num text-[11px]"
                  :class="diffForeign < 0.001 ? 'text-pos' : 'text-neg'"
                >
                  {{ diffForeign < 0.001 ? `${activeForeignCurrency.symbol} 0.00` : `${activeForeignCurrency.symbol} ${diffForeign.toFixed(2)}` }}
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
            @click="loadCatalogsAndHistory"
          >
            <RefreshCw class="w-3.5 h-3.5 text-muted" />
            <span>Actualizar</span>
          </button>
        </div>

        <div v-if="journalEntries.length === 0" class="p-12 text-center">
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
                      {{ entry.entry_type }}
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
                            <td class="py-1.5 px-3 text-muted text-xs">{{ l.description || entry.description }}</td>
                            <td class="py-1.5 px-3 text-right font-medium num">
                              {{ parseFloat(l.debit_local) > 0 ? parseFloat(l.debit_local).toFixed(2) : '—' }}
                            </td>
                            <td class="py-1.5 px-3 text-right font-medium num">
                              {{ parseFloat(l.credit_local) > 0 ? parseFloat(l.credit_local).toFixed(2) : '—' }}
                            </td>
                            <td class="py-1.5 px-3 text-right text-muted num text-[11px]">
                              {{ parseFloat(l.debit_foreign) > 0 ? `$ ${parseFloat(l.debit_foreign).toFixed(2)}` : '—' }}
                            </td>
                            <td class="py-1.5 px-3 text-right text-muted num text-[11px]">
                              {{ parseFloat(l.credit_foreign) > 0 ? `$ ${parseFloat(l.credit_foreign).toFixed(2)}` : '—' }}
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
