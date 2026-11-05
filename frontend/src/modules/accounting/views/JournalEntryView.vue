<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import {
  AlertTriangle,
  BookOpen,
  CheckCircle2,
  ChevronDown,
  ChevronUp,
  Coins,
  Hash,
  History,
  Layers,
  Loader2,
  Plus,
  RefreshCw,
  Save,
  Sparkles,
  Trash2,
} from 'lucide-vue-next';
import type { JournalEditorAccount } from '../types/journalEntry.types';
import type { EntityMaster } from '../../company/types/entityMaster.types';
import JournalEntityPicker from '../components/JournalEntityPicker.vue';
import UiButton from '@/shared/ui/UiButton.vue';
import JournalAccountCombobox from '../components/JournalAccountCombobox.vue';
import { entityMatches, entityRequired } from '../utils/journalEntity';
import { cashFlowMatches } from '../utils/journalArea';
import { resetAccountDimensions } from '../utils/journalLine';
import { reconcileForeignRounding, reconcileLocalRounding, type ForeignRoundingAdjustment } from '../utils/journalForeignRounding';
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
const emit = defineEmits<{ 'posting-change': [value: boolean] }>();

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
const contextNotice = shallowRef<string | null>(null);
const foreignRoundingAdjustment = shallowRef<ForeignRoundingAdjustment | null>(null);

// Catálogo de Cuentas Operativas (Nivel 4+)
const operationalAccounts = shallowRef<readonly JournalEditorAccount[]>([]);
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
const isBaseVoucher = computed(() => currency.value === baseCurrencyInfo.value.code);
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

function syncActiveVoucherExchangeRate(recalculate = true): void {
  const target =
    currency.value === baseCurrencyInfo.value.code
      ? requiredCurrencyRates.value[0]
      : requiredCurrencyRates.value.find((r) => r.code === currency.value);

  if (target) {
    exchangeRate.value =
      exchangeRateType.value === 'BUY' ? target.buy_rate : target.sell_rate;
  }
  if (recalculate) recalculateAmounts();
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
    recalculateAmounts();
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

function convertForeignToLocal(raw: string): string {
  try {
    const amount = SystemDecimal.parseMonetary(raw);
    const rate = SystemDecimal.parseExchangeRate(exchangeRate.value.trim() || '1');
    return rate.isPositive() ? amount.multiplyBy(rate).toString() : '';
  } catch {
    return '';
  }
}

function recalculateAmounts(): void {
  if (!isBaseVoucher.value) {
    for (const line of lines.value) {
      line.debitLocal = line.debitForeign ? convertForeignToLocal(line.debitForeign) : '';
      line.creditLocal = line.creditForeign ? convertForeignToLocal(line.creditForeign) : '';
    }
    const reconciled = reconcileLocalRounding(lines.value);
    reconciled.values.forEach((amounts, index) => {
      lines.value[index]!.debitLocal = amounts.debitLocal;
      lines.value[index]!.creditLocal = amounts.creditLocal;
    });
    foreignRoundingAdjustment.value = reconciled.adjustment;
    return;
  }
  for (const line of lines.value) {
    line.debitForeign = line.debitLocal ? convertLocalToForeign(line.debitLocal) : '';
    line.creditForeign = line.creditLocal ? convertLocalToForeign(line.creditLocal) : '';
  }
  const reconciled = reconcileForeignRounding(lines.value);
  reconciled.values.forEach((amounts, index) => {
    lines.value[index]!.debitForeign = amounts.debitForeign;
    lines.value[index]!.creditForeign = amounts.creditForeign;
  });
  foreignRoundingAdjustment.value = reconciled.adjustment;
}

function deriveForeignFromLocal(): void {
  for (const line of lines.value) {
    line.debitForeign = line.debitLocal ? convertLocalToForeign(line.debitLocal) : '';
    line.creditForeign = line.creditLocal ? convertLocalToForeign(line.creditLocal) : '';
  }
  const reconciled = reconcileForeignRounding(lines.value);
  reconciled.values.forEach((amounts, index) => {
    lines.value[index]!.debitForeign = amounts.debitForeign;
    lines.value[index]!.creditForeign = amounts.creditForeign;
  });
}

let rateRequestSequence = 0;
let numberRequestSequence = 0;
let submitSequence = 0;

async function loadExchangeRatesForDate(dateStr: string): Promise<void> {
  const sequence = ++rateRequestSequence;
  const tenant = props.activeCompanyId;
  isLoadingRates.value = true;
  try {
    const res = await apiClient.value.fetchCompanyExchangeRates(dateStr);
    if (sequence !== rateRequestSequence || tenant !== props.activeCompanyId || dateStr !== entryDate.value) return;
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
    if (sequence === rateRequestSequence && tenant === props.activeCompanyId) {
      console.error('Error al cargar tipos de cambio obligatorios:', err);
    }
  } finally {
    if (sequence === rateRequestSequence) isLoadingRates.value = false;
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
const selectedLineId = ref<number | null>(lines.value[0]!.id);
const selectedLine = computed(() => lines.value.find(line => line.id === selectedLineId.value) ?? null);
const selectedLineIndex = computed(() => lines.value.findIndex(line => line.id === selectedLineId.value));
const selectedAccount = computed(() => selectedLine.value ? accountFor(selectedLine.value) : undefined);

function accountFor(line: EditingLine): JournalEditorAccount | undefined {
  return operationalAccounts.value.find(account => account.id === line.accountId);
}

function requiresDimensions(line: EditingLine): boolean {
  const account = accountFor(line);
  return Boolean(account && (account.requires_cost_center || account.has_automatic_destination ||
    entityRequired(account) || account.requires_company_area || account.requires_cash_flow_area));
}

function missingLineFields(line: EditingLine): string[] {
  const account = accountFor(line);
  const missing: string[] = [];
  if (!account) return ['cuenta'];
  if ((account.requires_cost_center || account.has_automatic_destination) && !line.costCenterId) missing.push('centro de costo');
  if (entityRequired(account) && !entityMatches(account, line.entity)) missing.push('anexo');
  if (account.requires_company_area && !line.companyAreaId) missing.push('área organizativa');
  if (account.requires_cash_flow_area && !line.cashFlowAreaId) missing.push('área de flujo');
  return missing;
}

const missingDimensions = computed(() => lines.value.flatMap((line, index) =>
  missingLineFields(line).map(field => `Línea ${index + 1}: ${field}`),
));
const missingHeaderFields = computed(() => {
  const missing: string[] = [];
  if (!subdiaryCode.value || !subdiaries.value.some(s => s.code === subdiaryCode.value)) missing.push('subdiario');
  if (!headerDescription.value.trim()) missing.push('glosa general');
  if (requiredCurrencyRates.value.some(rate => !isValidPositiveExchangeRate(rate.buy_rate) ||
    !isValidPositiveExchangeRate(rate.sell_rate))) missing.push('tipos de cambio');
  return missing;
});
const missingRequirements = computed(() => [...missingHeaderFields.value, ...missingDimensions.value]);

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
  diffForeign.value.isZero() &&
  lines.value.every((line) => {
    const debit = parseAmount(line.debitLocal);
    const credit = parseAmount(line.creditLocal);
    return (debit.isPositive() && credit.isZero() && parseAmount(line.debitForeign).isPositive() && parseAmount(line.creditForeign).isZero()) ||
      (credit.isPositive() && debit.isZero() && parseAmount(line.creditForeign).isPositive() && parseAmount(line.debitForeign).isZero());
  }),
);
const isReadyToPost = computed(() => isBalanced.value && missingRequirements.value.length === 0);

// ==========================================
// MÉTODOS DE FORMULARIO
// ==========================================

function addLine(): void {
  const line: EditingLine = {
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
  };
  lines.value.push(line);
  selectLine(line);
}

function removeLine(index: number): void {
  if (lines.value.length > 2) {
    const removed = lines.value.splice(index, 1)[0];
    if (removed?.id === selectedLineId.value) selectedLineId.value = lines.value[Math.min(index, lines.value.length - 1)]?.id ?? null;
    recalculateAmounts();
  }
}

function selectLine(line: EditingLine): void {
  selectedLineId.value = line.id;
}

async function showLineEditor(line: EditingLine): Promise<void> {
  selectLine(line);
  await nextTick();
  document.getElementById('journal-line-editor')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function changeAccount(line: EditingLine): void {
  resetAccountDimensions(line);
  selectLine(line);
}

function onAmountChange(line: EditingLine, side: 'debit' | 'credit', rawValue: string): void {
  // Mantener la entrada como texto decimal: Vue convierte v-model:number a IEEE-754.
  const target = isBaseVoucher.value ? 'Local' : 'Foreign';
  const opposite = side === 'debit' ? 'credit' : 'debit';
  line[`${side}${target}`] = rawValue;
  line[`${opposite}Local`] = '';
  line[`${opposite}Foreign`] = '';
  recalculateAmounts();
}

/**
 * Cuadre automático: calcula la diferencia y la asigna a la última línea o agrega una nueva
 */
function autoBalance(): void {
  const diff = isBaseVoucher.value
    ? totalDebitLocal.value.subtract(totalCreditLocal.value)
    : totalDebitForeign.value.subtract(totalCreditForeign.value);
  if (diff.isZero()) return;

  let target = lines.value[lines.value.length - 1];
  if (!target || target.debitLocal || target.creditLocal || target.debitForeign || target.creditForeign) {
    addLine();
    target = lines.value[lines.value.length - 1]!;
  }

  const amount = diff.abs().toString();
  const side = diff.isPositive() ? 'credit' : 'debit';
  target[`${side}${isBaseVoucher.value ? 'Local' : 'Foreign'}`] = amount;
  selectLine(target);
  recalculateAmounts();
}

function resetDraft(): void {
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
  selectedLineId.value = lines.value[0]!.id;
  errorMessage.value = null;
  successMessage.value = null;
  foreignRoundingAdjustment.value = null;
}

function resetAfterSave(): void {
  resetDraft();
  contextNotice.value = null;
  void fetchNextNumber();
}

function clearForm(): void {
  if (isSubmitting.value) return;
  resetAfterSave();
}

async function fetchNextNumber(): Promise<void> {
  const sequence = ++numberRequestSequence;
  const tenant = props.activeCompanyId;
  const date = entryDate.value;
  try {
    const res = await apiClient.value.fetchNextJournalEntryNumber(date);
    if (sequence !== numberRequestSequence || tenant !== props.activeCompanyId || date !== entryDate.value) return;
    entryNumber.value = res.next_number;
  } catch {
    // Si falla, el usuario puede continuar o el servidor lo genera
  }
}

let loadSequence = 0;
let historySequence = 0;
let historyTenant: CompanyId | null = null;
const historyLoaded = shallowRef(false);
const isLoadingHistory = shallowRef(false);

async function loadHistory(): Promise<void> {
  const tenant = props.activeCompanyId;
  if (isLoadingHistory.value && historyTenant === tenant) return;
  const sequence = ++historySequence;
  historyTenant = tenant;
  isLoadingHistory.value = true;
  try {
    const entries = await apiClient.value.fetchJournalEntries({ limit: 50 });
    if (sequence !== historySequence || tenant !== props.activeCompanyId) return;
    journalEntries.value = entries;
    historyLoaded.value = true;
  } catch (e) {
    if (sequence === historySequence && tenant === props.activeCompanyId) {
      errorMessage.value = e instanceof Error ? e.message : 'No se pudo cargar el Libro Diario.';
    }
  } finally {
    if (sequence === historySequence) {
      isLoadingHistory.value = false;
      historyTenant = null;
    }
  }
}

async function loadCatalogsAndHistory(): Promise<void> {
  const sequence = ++loadSequence;
  const tenant = props.activeCompanyId;
  const date = entryDate.value;
  isLoading.value = true;
  errorMessage.value = null;
  historyLoaded.value = false;
  journalEntries.value = [];
  operationalAccounts.value = [];
  subdiaries.value = [];
  costCenters.value = [];
  companyAreas.value = [];
  cashFlowAreas.value = [];
  try {
    const config = await apiClient.value.fetchJournalEditorBootstrap(date);
    if (sequence !== loadSequence || tenant !== props.activeCompanyId) return;
    operationalAccounts.value = config.accounts;
    subdiaries.value = config.subdiaries;
    costCenters.value = config.cost_centers;
    companyAreas.value = config.company_areas;
    cashFlowAreas.value = config.cash_flow_areas;
    if (date === entryDate.value) {
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
    }
    if (activeTab.value === 'history') void loadHistory();
  } catch (e) {
    if (sequence !== loadSequence || tenant !== props.activeCompanyId) return;
    errorMessage.value =
      e instanceof Error ? e.message : 'Error al cargar el editor de asientos.';
  } finally {
    if (sequence === loadSequence && tenant === props.activeCompanyId) isLoading.value = false;
  }
}

async function handleSubmit(): Promise<void> {
  if (isSubmitting.value || isLoading.value) return;
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

  if (missingDimensions.value.length) {
    const first = lines.value.find(line => missingLineFields(line).length > 0);
    if (first) selectLine(first);
    errorMessage.value = `Complete los datos obligatorios: ${missingDimensions.value.join('; ')}.`;
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

  const tenant = props.activeCompanyId;
  const sequence = ++submitSequence;
  isSubmitting.value = true;
  emit('posting-change', true);
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
    if (sequence !== submitSequence || tenant !== props.activeCompanyId) return;
    pendingJournalAttempt = null;

    // Limpiar antes de mostrar el éxito; el guard de envío impide Limpiar desde la UI.
    resetAfterSave();
    successMessage.value = `¡Asiento ${savedEntry.entry_number} asentado exitosamente en el Libro Diario!`;

    // El POST ya quedó confirmado. Si falla el GET del historial, no presentar
    // el guardado como fallido ni sugerir volver a registrar el mismo asiento.
    try {
      const entries = await apiClient.value.fetchJournalEntries({ limit: 50 });
      if (sequence !== submitSequence || tenant !== props.activeCompanyId) return;
      journalEntries.value = entries;
      historyLoaded.value = true;
    } catch {
      if (sequence === submitSequence && tenant === props.activeCompanyId) {
        errorMessage.value = 'El asiento se registró, pero no se pudo actualizar el historial. Recargue la vista para consultarlo; no vuelva a registrarlo.';
      }
    }
  } catch (e) {
    if (sequence !== submitSequence || tenant !== props.activeCompanyId) return;
    // Keep the key after an ambiguous network/server error, but not after
    // a conclusive client-side rejection (HTTP 400/409/422).
    if (e instanceof ApiHttpError && e.status >= 400 && e.status < 500) {
      pendingJournalAttempt = null;
    }
    errorMessage.value =
      e instanceof Error ? e.message : 'Error al registrar el asiento contable.';
  } finally {
    if (sequence === submitSequence) {
      isSubmitting.value = false;
      emit('posting-change', false);
    }
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
  syncActiveVoucherExchangeRate(false);
  // On currency switch, carry the draft through the base amount once, then
  // let the new voucher currency become the editable source of truth.
  if (!isBaseVoucher.value) deriveForeignFromLocal();
  recalculateAmounts();
});

watch(exchangeRateType, () => {
  syncActiveVoucherExchangeRate();
});

watch(exchangeRate, () => {
  recalculateAmounts();
});

watch(
  () => props.activeCompanyId,
  () => {
    const wasSubmitting = isSubmitting.value;
    const hadDraft = Boolean(subdiaryCode.value || headerDescription.value.trim() || lines.value.some(
      (line) => line.accountId !== null || line.description.trim() || line.debitLocal || line.creditLocal,
    ));
    ++rateRequestSequence;
    ++numberRequestSequence;
    ++historySequence;
    ++submitSequence;
    isLoadingRates.value = false;
    isLoadingHistory.value = false;
    isSubmitting.value = false;
    historyTenant = null;
    entryNumber.value = '';
    requiredCurrencyRates.value = [];
    resetDraft();
    contextNotice.value = wasSubmitting
      ? 'Se cambió de empresa durante un envío. Revise el Libro Diario de la empresa anterior antes de intentar registrar ese asiento otra vez.'
      : hadDraft ? 'Se descartó el borrador del asiento al cambiar de empresa.' : null;
    void loadCatalogsAndHistory();
  },
);

onMounted(() => {
  void loadCatalogsAndHistory();
});
onBeforeUnmount(() => {
  // La vista se reemplaza al cambiar tenant. El componente anterior conserva
  // las promesas HTTP, pero ninguna respuesta tardía debe iniciar más GET ni
  // modificar su borrador después de desmontarlo.
  ++submitSequence;
  ++numberRequestSequence;
  ++rateRequestSequence;
  ++historySequence;
  ++loadSequence;
  emit('posting-change', false);
});
</script>

<template>
  <div class="journal-page space-y-5 sm:space-y-6">
    <!-- Encabezado de Sección y Conmutador de Pestañas -->
    <div
      class="journal-heading flex flex-col gap-4 border-b border-line pb-5 lg:flex-row lg:items-center lg:justify-between"
    >
      <div>
        <div class="flex items-center gap-2">
          <span
            class="w-8 h-8 rounded-md bg-brand/10 text-brand flex items-center justify-center"
          >
            <BookOpen class="w-4 h-4 text-brand" />
          </span>
          <h1 class="text-lg font-semibold tracking-tight text-foreground sm:text-xl">
            Libro diario <span class="font-normal text-muted">· Asientos bimoneda</span>
          </h1>
        </div>
        <p class="text-xs text-muted mt-1">
          Registra y consulta asientos con partida doble, importes exactos y validaciones antes del registro.
        </p>
      </div>

      <!-- Conmutador de Pestañas -->
      <div class="journal-tabs inline-flex max-w-full gap-1 rounded-lg border border-line bg-soft p-1">
        <button
          type="button"
          class="journal-tab inline-flex items-center justify-center gap-1.5 rounded-md px-3 py-2 text-xs font-medium transition-colors cursor-pointer"
          :aria-pressed="activeTab === 'form'"
          :class="
            activeTab === 'form'
              ? 'bg-surf text-foreground shadow-card font-semibold'
              : 'text-muted hover:bg-surf/70 hover:text-foreground'
          "
          @click="activeTab = 'form'"
        >
          <Plus class="w-3.5 h-3.5" />
          <span>Nuevo asiento</span>
        </button>

        <button
          type="button"
          class="journal-tab inline-flex items-center justify-center gap-1.5 rounded-md px-3 py-2 text-xs font-medium transition-colors cursor-pointer"
          :aria-pressed="activeTab === 'history'"
          :class="
            activeTab === 'history'
              ? 'bg-surf text-foreground shadow-card font-semibold'
              : 'text-muted hover:bg-surf/70 hover:text-foreground'
          "
          @click="activeTab = 'history'"
        >
          <History class="w-3.5 h-3.5" />
          <span>Libro diario ({{ historyLoaded ? journalEntries.length : '…' }})</span>
        </button>
      </div>
    </div>

    <!-- Alerta de Éxito -->
    <div v-if="contextNotice" class="rounded-xl border border-brand/30 bg-brand/10 p-4 text-xs text-foreground" role="status">
      {{ contextNotice }}
      <button type="button" class="ml-2 underline" @click="contextNotice = null">Cerrar</button>
    </div>

    <div
      v-if="successMessage"
      class="journal-alert flex items-center justify-between gap-3 rounded-lg border border-pos/35 bg-pos/5 p-4 text-xs font-medium text-foreground"
      role="alert"
    >
      <div class="flex items-center gap-2">
        <CheckCircle2 class="w-4 h-4 text-emerald-500 shrink-0" />
        <span>{{ successMessage }}</span>
      </div>
      <button
        type="button"
        class="text-pos hover:opacity-80 font-bold"
        aria-label="Cerrar mensaje de éxito"
        @click="successMessage = null"
      >
        ✕
      </button>
    </div>

    <!-- Alerta de Error -->
    <div
      v-if="errorMessage"
      class="journal-alert flex items-center justify-between gap-3 rounded-lg border border-neg/35 bg-neg/5 p-4 text-xs font-medium text-foreground"
      role="alert"
    >
      <div class="flex items-center gap-2">
        <AlertTriangle class="w-4 h-4 text-rose-500 shrink-0" />
        <span>{{ errorMessage }}</span>
      </div>
      <button
        type="button"
        class="text-neg hover:opacity-80 font-bold"
        aria-label="Cerrar mensaje de error"
        @click="errorMessage = null"
      >
        ✕
      </button>
    </div>

    <!-- ======================================================== -->
    <!-- PESTAÑA 1: FORMULARIO DE INGRESO DE ASIENTO (VOUCHER)    -->
    <!-- ======================================================== -->
    <div v-if="activeTab === 'form' && isLoading" class="space-y-6" role="status" aria-label="Cargando editor de asientos">
      <div v-for="card in 2" :key="card" class="panel space-y-4 p-5" aria-hidden="true">
        <div class="ui-skeleton h-5 w-1/2 sm:w-1/3" />
        <div class="grid gap-4 sm:grid-cols-2">
          <div v-for="field in 4" :key="field" class="ui-skeleton h-12 w-full" />
        </div>
      </div>
      <span class="sr-only">Cargando editor de asientos…</span>
    </div>
    <fieldset v-else-if="activeTab === 'form'" :disabled="isSubmitting" class="min-w-0 space-y-6">
      <!-- Tarjeta de Parámetros de Cabecera -->
      <div
        class="panel journal-section space-y-4"
      >
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line pb-3">
          <span class="text-xs font-semibold uppercase tracking-wider text-muted flex items-center gap-2">
            <Layers class="w-3.5 h-3.5 text-brand" />
            Datos Generales del Asiento
          </span>

          <span class="text-[11px] font-medium text-muted">Período sujeto a validación al registrar</span>
        </div>

        <div class="journal-header-fields grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-5">
          <!-- Fecha Contable -->
          <div>
            <label for="journal-entry-date" class="block text-xs font-semibold text-muted mb-1">
              Fecha Contable
            </label>
            <input id="journal-entry-date" v-model="entryDate"
              type="date"
              required
              class="w-full px-3 py-1.5 text-[13px] font-mono rounded-md bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
            />
          </div>

          <!-- N.° Correlativo -->
          <div>
            <label for="journal-entry-number" class="block text-xs font-semibold text-muted mb-1">
              N.° Correlativo
            </label>
            <div class="relative">
              <input id="journal-entry-number" v-model="entryNumber"
                type="text"
                placeholder="01-00001"
                class="w-full pl-7 pr-3 py-1.5 text-[13px] num font-semibold rounded-md bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
              />
              <Hash class="w-3.5 h-3.5 text-muted absolute left-2 top-2" />
            </div>
          </div>

          <!-- Subdiario: clasificación independiente del tipo de asiento. -->
          <div>
            <label for="journal-subdiary" class="block text-xs font-semibold text-muted mb-1">
              Subdiario
            </label>
            <select id="journal-subdiary" v-model="subdiaryCode" :disabled="isLoading || isSubmitting"
              class="w-full px-3 py-1.5 text-[13px] rounded-md bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand disabled:opacity-50">
              <option value="">Seleccionar subdiario...</option>
              <option v-for="sub in subdiaries" :key="sub.code" :value="sub.code">
                {{ sub.name }}
              </option>
            </select>
          </div>

          <!-- Tipo de Asiento -->
          <div>
            <label for="journal-entry-type" class="block text-xs font-semibold text-muted mb-1">
              Tipo de Asiento
            </label>
            <select id="journal-entry-type" v-model="entryType"
              class="w-full px-3 py-1.5 text-[13px] rounded-md bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
            >
              <option value="STANDARD">STANDARD · Diario Normal</option>
              <option value="OPENING">OPENING · Asiento de Apertura</option>
              <option value="ADJUSTMENT">ADJUSTMENT · Ajuste Periódico</option>
              <option value="CLOSING">CLOSING · Cierre Contable</option>
            </select>
          </div>

          <!-- Moneda de Operación -->
          <div>
            <label for="journal-entry-currency" class="block text-xs font-semibold text-muted mb-1">
              Moneda del Voucher
            </label>
            <select id="journal-entry-currency" v-model="currency"
              class="w-full px-3 py-1.5 text-[13px] font-semibold rounded-md bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
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
              <label for="journal-exchange-rate" class="text-xs font-semibold text-muted">T.C. Efectivo</label>
              <span class="text-[10px] text-brand font-mono font-medium">
                {{ activeForeignCurrency.code }}/{{ baseCurrencyInfo.code }}
              </span>
            </div>
            <input
              id="journal-exchange-rate"
              v-model="exchangeRate"
              type="text"
              required
              class="w-full px-3 py-1.5 text-[13px] num rounded-md bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
            />
          </div>

          <!-- Criterio TC SUNAT -->
          <div>
            <label for="journal-fx-criterion" class="block text-xs font-semibold text-muted mb-1">
              Criterio TC SUNAT
            </label>
            <select id="journal-fx-criterion" v-model="exchangeRateType"
              class="w-full px-3 py-1.5 text-[13px] rounded-md bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
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
                  class="w-[104px] rounded-md border border-border-strong bg-surf px-2 py-1.5 text-xs font-semibold num text-foreground focus:outline-none focus:ring-2 focus:ring-brand/20"
                  @input="updateRateForCurrency(rateItem.code, 'buy_rate', ($event.target as HTMLInputElement).value)" />
              </label>
              <label class="flex items-center gap-1 text-[10px] text-muted">
                Venta
                <input :value="rateItem.sell_rate" type="number" step="0.000001" min="0.000001" required
                  :aria-label="`Tipo de cambio venta ${rateItem.code}`"
                  class="w-[104px] rounded-md border border-border-strong bg-surf px-2 py-1.5 text-xs font-semibold num text-foreground focus:outline-none focus:ring-2 focus:ring-brand/20"
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
          <label for="journal-description" class="block text-[11px] font-semibold text-muted mb-1">
            Glosa / Explicación del Asiento
          </label>
          <input id="journal-description" v-model="headerDescription"
            type="text"
            required
            placeholder="Ej: Por la provisión de servicios contables según Factura F001-000492"
            class="w-full px-3.5 py-1.5 text-xs rounded-md bg-surf border border-line text-foreground placeholder-muted focus:outline-none focus:ring-1 focus:ring-brand"
          />
        </div>
      </div>

      <!-- Una sola superficie de edición; las partidas agregadas son una lista de lectura. -->
      <div class="panel journal-section min-w-0 overflow-hidden p-0">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line p-4">
          <div>
            <h2 class="text-base font-semibold tracking-tight text-foreground">Editor único de asientos</h2>
            <p class="text-xs text-muted">{{ lines.length }} partidas · mínimo 2 para registrar</p>
          </div>
          <div class="journal-quick-status flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-line bg-soft px-3 py-2 text-xs">
            <span class="font-semibold" :class="isReadyToPost ? 'text-pos' : 'text-warn'">
              {{ isReadyToPost ? 'Listo para registrar' : isBalanced ? 'Cuadrado · faltan datos' : 'Completar y cuadrar partidas' }}
            </span>
            <span v-if="totalDebitLocal.isPositive() || totalCreditLocal.isPositive()" class="num text-foreground">
              Diferencia: {{ baseCurrencyInfo.symbol }} {{ diffLocal.toDisplay(4) }} · {{ activeForeignCurrency.symbol }} {{ diffForeign.toDisplay(4) }}
            </span>
          </div>
          <div class="flex flex-wrap gap-2">
            <UiButton variant="secondary" title="Completa la diferencia en Debe o Haber" @click="autoBalance">
              <Sparkles class="h-4 w-4 text-brand" aria-hidden="true" /> Balancear
            </UiButton>
            <UiButton variant="primary" @click="addLine">
              <Plus class="h-4 w-4" aria-hidden="true" /> Agregar partida
            </UiButton>
          </div>
        </div>

        <div v-if="selectedLine" id="journal-line-editor" class="journal-line-editor min-w-0 space-y-4 border-b border-line bg-soft/30 p-4">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-sm font-semibold text-foreground">Editar partida {{ selectedLineIndex + 1 }}</h3>
            <span v-if="missingLineFields(selectedLine).length" class="text-xs text-warn">
              Falta: {{ missingLineFields(selectedLine).join(', ') }}
            </span>
          </div>
          <div class="grid min-w-0 gap-3 md:grid-cols-2">
            <div class="min-w-0">
              <label class="mb-1 block text-xs font-medium text-muted">Cuenta contable</label>
              <JournalAccountCombobox v-model="selectedLine.accountId" :accounts="operationalAccounts"
                :disabled="isSubmitting || isLoading" @update:model-value="changeAccount(selectedLine)" />
            </div>
            <label class="min-w-0 text-xs font-medium text-muted">Glosa de la partida
              <input v-model="selectedLine.description" type="text" :placeholder="headerDescription || 'Misma glosa general'"
                class="mt-1 block w-full min-w-0 rounded-md border border-line bg-surf px-3 py-2 text-sm text-foreground" />
            </label>
          </div>
          <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            <label class="min-w-0 text-xs font-medium text-muted">Debe {{ currency }}
              <input :value="isBaseVoucher ? selectedLine.debitLocal : selectedLine.debitForeign" type="text" inputmode="decimal" autocomplete="off" placeholder="0.00"
                :aria-label="`Debe ${currency}, línea ${selectedLineIndex + 1}`"
                class="mt-1 block w-full min-w-0 rounded-md border border-line bg-surf px-2 py-2 text-right text-sm num text-foreground"
                @input="onAmountChange(selectedLine, 'debit', ($event.target as HTMLInputElement).value)" />
            </label>
            <label class="min-w-0 text-xs font-medium text-muted">Haber {{ currency }}
              <input :value="isBaseVoucher ? selectedLine.creditLocal : selectedLine.creditForeign" type="text" inputmode="decimal" autocomplete="off" placeholder="0.00"
                :aria-label="`Haber ${currency}, línea ${selectedLineIndex + 1}`"
                class="mt-1 block w-full min-w-0 rounded-md border border-line bg-surf px-2 py-2 text-right text-sm num text-foreground"
                @input="onAmountChange(selectedLine, 'credit', ($event.target as HTMLInputElement).value)" />
            </label>
            <div class="min-w-0 rounded-md bg-soft px-2 py-2 text-xs text-muted num">
              Debe {{ isBaseVoucher ? activeForeignCurrency.code : baseCurrencyInfo.code }} · calculado<br>
              <strong class="text-foreground">{{ isBaseVoucher ? (selectedLine.debitForeign ? activeForeignCurrency.symbol + ' ' + selectedLine.debitForeign : '—') : (selectedLine.debitLocal ? baseCurrencyInfo.symbol + ' ' + selectedLine.debitLocal : '—') }}</strong>
            </div>
            <div class="min-w-0 rounded-md bg-soft px-2 py-2 text-xs text-muted num">
              Haber {{ isBaseVoucher ? activeForeignCurrency.code : baseCurrencyInfo.code }} · calculado<br>
              <strong class="text-foreground">{{ isBaseVoucher ? (selectedLine.creditForeign ? activeForeignCurrency.symbol + ' ' + selectedLine.creditForeign : '—') : (selectedLine.creditLocal ? baseCurrencyInfo.symbol + ' ' + selectedLine.creditLocal : '—') }}</strong>
            </div>
          </div>

          <div v-if="requiresDimensions(selectedLine)" class="grid min-w-0 gap-3 border-t border-line pt-4 sm:grid-cols-2">
            <label v-if="selectedAccount?.requires_cost_center || selectedAccount?.has_automatic_destination"
              class="min-w-0 text-xs font-medium text-muted">Centro de costo obligatorio
              <select v-model="selectedLine.costCenterId" class="mt-1 block w-full min-w-0 rounded-md border border-line bg-surf px-2 py-2 text-sm text-foreground">
                <option :value="null">Seleccionar centro</option>
                <option v-for="cc in costCenters.filter(cc => cc.is_active)" :key="cc.id" :value="cc.id">{{ cc.code }} · {{ cc.name }}</option>
              </select>
            </label>
            <div v-if="entityRequired(selectedAccount)" class="min-w-0">
              <div class="mb-1 text-xs font-medium text-muted">Anexo / tercero obligatorio</div>
              <JournalEntityPicker :key="selectedLine.id + '-' + selectedLine.accountId"
                v-model="selectedLine.entity" :api="apiClient" :account="selectedAccount" :disabled="isSubmitting" />
            </div>
            <label v-if="selectedAccount?.requires_company_area" class="min-w-0 text-xs font-medium text-muted">Área organizativa obligatoria
              <select v-model="selectedLine.companyAreaId" class="mt-1 block w-full min-w-0 rounded-md border border-line bg-surf px-2 py-2 text-sm text-foreground">
                <option :value="null">Seleccionar área organizativa</option>
                <option v-for="area in companyAreas.filter(area => area.is_active)" :key="area.id" :value="area.id">{{ area.code }} · {{ area.name }}</option>
              </select>
            </label>
            <label v-if="selectedAccount?.requires_cash_flow_area" class="min-w-0 text-xs font-medium text-muted">Área de flujo de efectivo obligatoria
              <select v-model="selectedLine.cashFlowAreaId" class="mt-1 block w-full min-w-0 rounded-md border border-line bg-surf px-2 py-2 text-sm text-foreground">
                <option :value="null">Seleccionar flujo</option>
                <option v-for="area in cashFlowAreas.filter(area => cashFlowMatches(selectedAccount, area))" :key="area.id" :value="area.id">{{ area.code }} · {{ area.name }}</option>
              </select>
            </label>
          </div>
        </div>

        <div class="border-b border-line p-4">
          <h3 class="text-sm font-semibold text-foreground">Partidas agregadas <span class="num font-normal text-muted">({{ lines.length }})</span></h3>
          <p class="mt-1 text-xs text-muted">Seleccione una partida para editarla en el formulario de arriba.</p>
        </div>
        <div class="hidden overflow-x-auto lg:block">
          <table class="journal-lines-table w-full min-w-[900px] table-fixed border-collapse text-left text-xs">
            <thead class="bg-surface-muted text-muted">
              <tr>
                <th class="w-[55px] px-3 py-3">#</th>
                <th class="w-[250px] px-3 py-3">Cuenta</th>
                <th class="w-[190px] px-3 py-3">Glosa</th>
                <th class="w-[100px] px-2 py-3 text-right">Debe {{ baseCurrencyInfo.code }}</th>
                <th class="w-[100px] px-2 py-3 text-right">Haber {{ baseCurrencyInfo.code }}</th>
                <th class="w-[100px] px-2 py-3 text-right">Debe {{ activeForeignCurrency.code }}</th>
                <th class="w-[100px] px-2 py-3 text-right">Haber {{ activeForeignCurrency.code }}</th>
                <th class="w-[110px] px-2 py-3">Acciones</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line">
              <tr v-for="(line, idx) in lines" :key="line.id" :class="selectedLineId === line.id ? 'bg-brand/10' : 'bg-surf'" :data-current="selectedLineId === line.id ? 'true' : undefined">
                <td class="px-3 py-3 num">{{ idx + 1 }}</td>
                <td class="truncate px-3 py-3" :title="accountFor(line)?.name || ''">{{ accountFor(line) ? `${accountFor(line)?.code} · ${accountFor(line)?.name}` : 'Seleccionar cuenta' }}</td>
                <td class="truncate px-3 py-3" :title="line.description || headerDescription">{{ line.description || headerDescription || '—' }}</td>
                <td class="px-2 py-3 text-right num">{{ line.debitLocal || '—' }}</td>
                <td class="px-2 py-3 text-right num">{{ line.creditLocal || '—' }}</td>
                <td class="px-2 py-3 text-right num">{{ line.debitForeign || '—' }}</td>
                <td class="px-2 py-3 text-right num">{{ line.creditForeign || '—' }}</td>
                <td class="px-2 py-3">
                  <button type="button" class="rounded px-2 py-1 font-semibold text-brand hover:bg-soft" :aria-label="`Editar partida ${idx + 1}`" :aria-pressed="selectedLineId === line.id" @click="showLineEditor(line)">Editar</button>
                  <button type="button" :disabled="lines.length <= 2" class="ml-1 rounded px-2 py-1 font-semibold text-neg hover:bg-neg/10 disabled:opacity-30"
                    :aria-label="`Eliminar partida ${idx + 1}`" @click="removeLine(idx)">Quitar</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="divide-y divide-line lg:hidden">
          <div v-for="(line, idx) in lines" :key="line.id" class="flex min-w-0 items-center gap-2 px-4 py-3"
            :class="selectedLineId === line.id ? 'bg-brand/10' : 'bg-surf'">
            <button type="button" class="min-w-0 flex-1 rounded-md p-1 text-left" :aria-label="`Editar partida ${idx + 1}`" :aria-pressed="selectedLineId === line.id" @click="showLineEditor(line)">
              <span class="block truncate text-sm font-semibold text-foreground">{{ idx + 1 }} · {{ accountFor(line) ? `${accountFor(line)?.code} · ${accountFor(line)?.name}` : 'Seleccionar cuenta' }}</span>
              <span class="block truncate text-xs text-muted">{{ line.description || headerDescription || 'Sin glosa' }}</span>
              <span class="block text-xs num text-foreground">Debe {{ isBaseVoucher ? baseCurrencyInfo.symbol : activeForeignCurrency.symbol }} {{ (isBaseVoucher ? line.debitLocal : line.debitForeign) || '0' }} · Haber {{ isBaseVoucher ? baseCurrencyInfo.symbol : activeForeignCurrency.symbol }} {{ (isBaseVoucher ? line.creditLocal : line.creditForeign) || '0' }}</span>
              <span v-if="missingLineFields(line).length" class="block text-xs text-warn">Falta: {{ missingLineFields(line).join(', ') }}</span>
            </button>
            <button type="button" :disabled="lines.length <= 2" class="shrink-0 rounded border border-line p-2 text-neg disabled:opacity-30"
              :aria-label="`Eliminar partida ${idx + 1}`" @click="removeLine(idx)"><Trash2 class="h-4 w-4" /></button>
          </div>
        </div>
        <section aria-labelledby="journal-totals-title" class="journal-totals bg-soft p-4">
          <h3 id="journal-totals-title" class="mb-3 text-sm font-semibold text-foreground">Totales del asiento</h3>
          <dl class="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <div class="journal-total"><dt>Debe {{ baseCurrencyInfo.code }}</dt><dd class="num">{{ baseCurrencyInfo.symbol }} {{ totalDebitLocal.toDisplay(4) }}</dd></div>
            <div class="journal-total"><dt>Haber {{ baseCurrencyInfo.code }}</dt><dd class="num">{{ baseCurrencyInfo.symbol }} {{ totalCreditLocal.toDisplay(4) }}</dd></div>
            <div class="journal-total"><dt>Debe {{ activeForeignCurrency.code }}</dt><dd class="num">{{ activeForeignCurrency.symbol }} {{ totalDebitForeign.toDisplay(4) }}</dd></div>
            <div class="journal-total"><dt>Haber {{ activeForeignCurrency.code }}</dt><dd class="num">{{ activeForeignCurrency.symbol }} {{ totalCreditForeign.toDisplay(4) }}</dd></div>
          </dl>
          <p class="mt-3 text-xs font-semibold" :class="diffLocal.isZero() && diffForeign.isZero() ? 'text-pos' : 'text-neg'">
            Diferencia: {{ baseCurrencyInfo.symbol }} {{ diffLocal.toDisplay(4) }} · {{ activeForeignCurrency.symbol }} {{ diffForeign.toDisplay(4) }}
          </p>
        </section>
      </div>

      <!-- Barra de Estado de Balance y Acciones Finales -->
      <div
        class="panel journal-post-bar flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
        :class="isReadyToPost ? 'border-pos/50 bg-pos/5' : 'border-warn/50 bg-warn/5'"
        :data-ready="isReadyToPost ? 'true' : 'false'"
      >
        <div class="flex items-center gap-3">
          <div
            class="w-9 h-9 rounded-md flex items-center justify-center shrink-0"
            :class="isReadyToPost ? 'bg-pos/20 text-pos' : 'bg-warn/20 text-warn'"
          >
            <CheckCircle2 v-if="isReadyToPost" class="w-5 h-5 text-pos" />
            <AlertTriangle v-else class="w-5 h-5 text-warn" />
          </div>

          <div>
            <p class="text-xs font-semibold text-foreground">
              {{ isReadyToPost ? 'Asiento listo para registrar' : isBalanced ? 'Faltan datos obligatorios' : 'Asiento incompleto o descuadrado' }}
            </p>
            <p class="text-[11px] text-muted">
              {{
                isReadyToPost
                  ? 'Las partidas cuadran y sus datos obligatorios están completos.'
                  : isBalanced ? `Complete: ${missingRequirements.join('; ')}.` : 'Para registrar, complete las partidas y cuadre ambos importes.'
              }}
            </p>
            <p v-if="foreignRoundingAdjustment" class="mt-1 text-[11px] text-muted">
              Ajuste de redondeo {{ isBaseVoucher ? activeForeignCurrency.code : baseCurrencyInfo.code }} {{ foreignRoundingAdjustment.amount }} en la línea {{ foreignRoundingAdjustment.lineIndex + 1 }}.
            </p>
          </div>
        </div>

        <div class="flex w-full flex-wrap items-center gap-2.5 sm:w-auto">
          <UiButton variant="secondary" @click="clearForm">Limpiar</UiButton>

          <UiButton
            variant="primary"
            class="flex-1 sm:flex-none"
            :disabled="!isReadyToPost || isLoading"
            :loading="isSubmitting"
            @click="handleSubmit"
          >
            <Save v-if="!isSubmitting" class="h-4 w-4" aria-hidden="true" />
            <span>{{ isSubmitting ? 'Registrando...' : 'Registrar asiento' }}</span>
          </UiButton>
        </div>
      </div>
    </fieldset>

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
              · Registro contable trazable
            </span>
          </div>

          <UiButton variant="secondary" size="sm" :disabled="isLoadingHistory" @click="loadHistory">
            <RefreshCw class="h-3.5 w-3.5" aria-hidden="true" />
            <span>Actualizar</span>
          </UiButton>
        </div>

        <div v-if="isLoadingHistory || isLoading" class="space-y-4 p-5" role="status" aria-label="Cargando Libro Diario">
          <p class="flex items-center gap-2 text-xs text-muted"><Loader2 class="size-4 animate-spin text-brand" /> Cargando Libro Diario…</p>
          <div v-for="row in 4" :key="row" class="ui-skeleton h-12 w-full" aria-hidden="true" />
        </div>
        <div v-else-if="journalEntries.length === 0" class="p-12 text-center">
          <BookOpen class="w-10 h-10 text-muted mx-auto mb-3 opacity-40" />
          <p class="text-sm font-semibold text-foreground">No hay asientos contables registrados</p>
          <p class="text-xs text-muted mt-1">
            Utiliza la pestaña "Nuevo Asiento (Voucher)" para ingresar el primer asiento de este ejercicio.
          </p>
          <UiButton variant="primary" class="mt-4" @click="activeTab = 'form'">
            Crear primer asiento
          </UiButton>
        </div>

        <div v-else class="overflow-x-auto">
          <table class="journal-history-table w-full text-left border-collapse min-w-[800px]">
            <caption class="sr-only">Asientos registrados del libro diario</caption>
            <thead>
              <tr class="bg-surface-muted border-b border-border text-[11px] font-bold text-muted uppercase">
                <th scope="col" class="py-2.5 px-3 w-10">Detalle</th>
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
                    <button
                      type="button"
                      class="journal-history-toggle inline-flex size-8 items-center justify-center rounded-md text-brand hover:bg-soft"
                      :aria-label="`${expandedEntryIds.has(entry.id) ? 'Contraer' : 'Expandir'} asiento ${entry.entry_number}`"
                      :aria-expanded="expandedEntryIds.has(entry.id)"
                      @click.stop="toggleExpandEntry(entry.id)"
                    >
                      <ChevronUp v-if="expandedEntryIds.has(entry.id)" class="h-4 w-4" aria-hidden="true" />
                      <ChevronDown v-else class="h-4 w-4" aria-hidden="true" />
                    </button>
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
                      <div class="overflow-x-auto">
                      <table class="min-w-[1120px] w-full text-left text-xs">
                        <caption class="sr-only">Detalle de partidas del asiento {{ entry.entry_number }}</caption>
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
