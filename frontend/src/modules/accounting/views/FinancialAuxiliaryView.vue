<script setup lang="ts">
import { computed, ref } from 'vue';
import { Calculator, FolderOpen, Save } from 'lucide-vue-next';
import { AccountingApiClient, ApiHttpError } from '../api/httpClient';
import type { CompanyId } from '../types/accountingAccount.types';
import { formatExactLocalAmount } from '../utils/formatExactLocalAmount';
import UiSelect from '@/shared/ui/UiSelect.vue';

const props = defineProps<{
  mode: 'loan' | 'asset';
  activeCompanyId: CompanyId;
  getAccessToken: () => string | null;
  canEdit: boolean;
}>();

const client = new AccountingApiClient('', {
  getActiveCompanyId: () => props.activeCompanyId, getAccessToken: props.getAccessToken,
});

const loan = ref({
  principal: '', transaction_costs: '0', previous_tax_ebitda: '0', apply_ebitda_limit: false,
  tax_interest_expense: '0', tax_interest_income: '0',
});
const payments = ref('');
const asset = ref({
  book_cost: '', residual: '0', useful_months: 60, available_from: '', stop_on: '',
  tax_cost: '', tax_category: 'OTHER', tax_use_from: '', last_eligible_month: 12,
  prior_tax_depreciation: '0', recorded_depreciation: '0',
});
const assetCategories = [
  { value: 'OTHER', label: 'Otros bienes · 10%' },
  { value: 'DATA_EQUIPMENT', label: 'Equipos de procesamiento de datos · 25%' },
  { value: 'VEHICLE', label: 'Vehículos terrestres, excepto ferrocarriles · 20%' },
  { value: 'BUILDING', label: 'Edificios y construcciones · 5%' },
];
const isLoan = computed(() => props.mode === 'loan');
const form = computed(() => isLoan.value ? {
  ...loan.value, payments: payments.value.split(/[\n;]+/).map((value) => value.trim()).filter(Boolean),
} : {
  ...asset.value, stop_on: asset.value.stop_on || null, tax_use_from: asset.value.tax_use_from || null,
});

type Calculation = { rows: Record<string, string | number>[] } & Record<string, unknown>;
const response = ref<{ input: string; data: Calculation }>();
const result = computed(() => response.value?.input === JSON.stringify(form.value) ? response.value.data : null);
const error = ref('');
const loading = ref(false);
const identity = ref({ reference: '', label: '', source_reference: '', expected_revision: 0 });
const loadedReference = ref('');
const revisionToOpen = ref<number>();
const records = ref<{ reference: string; label: string; revision: number }[]>([]);
const recordPage = ref(1);
const hasLaterPage = ref(false);

async function listRecords(page = 1) {
  if (loading.value) return;
  loading.value = true;
  error.value = '';
  try {
    const batch = await client.listFinancialAuxiliaries(props.mode, page);
    recordPage.value = page;
    hasLaterPage.value = !!batch.next_page_url;
    records.value = batch.data;
  } catch (reason) {
    showError(reason);
  } finally {
    loading.value = false;
  }
}

async function openRecord(reference: string, revision?: number) {
  if (loading.value) return;
  loading.value = true;
  error.value = '';
  try {
    const record = await client.fetchFinancialAuxiliary<Calculation>(props.mode, reference, revision);
    const { payments: items, ...values } = record.inputs;
    if (props.mode === 'loan') {
      Object.assign(loan.value, values);
      payments.value = (items as string[]).join('\n');
    } else {
      Object.assign(asset.value, values, { stop_on: values.stop_on ?? '', tax_use_from: values.tax_use_from ?? '' });
    }
    identity.value = { reference, label: record.label, source_reference: record.source_reference, expected_revision: record.revision };
    loadedReference.value = reference;
    revisionToOpen.value = record.revision;
    response.value = { input: JSON.stringify(form.value), data: record.result };
  } catch (reason) {
    showError(reason);
  } finally {
    loading.value = false;
  }
}

function showError(reason: unknown) {
  error.value = reason instanceof ApiHttpError
    ? Object.values(reason.validationErrors).flat()[0] ?? reason.message
    : 'No se pudo completar la operación. Revise la conexión.';
}

async function saveRecord() {
  if (loading.value || !props.canEdit) return;
  const input = JSON.stringify(form.value);
  loading.value = true;
  error.value = '';
  try {
    const reference = identity.value.reference.trim().toUpperCase();
    const saved = await client.saveFinancialAuxiliary<Calculation>(props.mode, JSON.stringify({
      ...form.value,
      ...identity.value,
      reference,
      expected_revision: reference === loadedReference.value ? identity.value.expected_revision : 0,
    }));
    response.value = { input, data: saved.data };
    loadedReference.value = reference;
    identity.value.reference = reference;
    identity.value.expected_revision = saved.revision;
    revisionToOpen.value = saved.revision;
  } catch (reason) {
    showError(reason);
  } finally {
    loading.value = false;
  }
}


const loanFields = [
  ['principal', 'Principal contractual'], ['transaction_costs', 'Costos de transacción atribuibles'],
  ['tax_interest_expense', 'Gasto financiero computable de la empresa'],
  ['tax_interest_income', 'Ingreso financiero computable de la empresa'],
] as const;
const assetFields = [
  ['book_cost', 'Costo contable'], ['residual', 'Valor residual'],
  ['tax_cost', 'Costo computable fiscal'], ['prior_tax_depreciation', 'Depreciación fiscal previa'],
  ['recorded_depreciation', 'Depreciación contabilizada en 2025'],
] as const;

const totals = computed(() => isLoan.value ? [
  ['initial_carrying_amount', 'Valor inicial del pasivo'],
  ['effective_monthly_percent', 'TIE mensual (%)'], ['effective_annual_percent', 'TIE anual equivalente (%)'],
  ['total_cash_payments', 'Pagos contractuales'], ['total_effective_interest', 'Interés efectivo total'],
  ['net_tax_interest', 'Interés neto fiscal'], ['ebitda_limit', 'Límite 30% EBITDA'],
  ['tax_excess', 'Exceso sobre el límite'],
] : [
  ['book_depreciation', 'Depreciación contable del ejercicio'],
  ['book_carrying_amount', 'Valor contable antes de la baja o reclasificación'],
  ['tax_rate', 'Tasa fiscal (%)'], ['tax_months', 'Meses con uso fiscal'],
  ['tax_depreciation_limit', 'Depreciación fiscal según tasa'],
  ['tax_depreciation', 'Depreciación fiscal estimada'], ['tax_base_closing', 'Base fiscal remanente'],
  ['period_adjustment', 'Diferencia del ejercicio'], ['carrying_vs_tax_base', 'Valor contable menos base fiscal'],
]);

const columns = computed(() => isLoan.value ? [
  ['month', 'Mes'], ['opening', 'Saldo inicial'], ['effective_interest', 'Interés NIIF'],
  ['payment', 'Cuota'], ['amortization', 'Amortización'], ['closing', 'Saldo final'],
] : [
  ['month', 'Mes'], ['book_depreciation', 'Depreciación contable'],
  ['accumulated_book', 'Acumulada'], ['carrying_amount', 'Valor contable'],
]);

function displayAmount(key: string, value: unknown) {
  if (value === null || value === undefined) return 'No aplicado';
  if (key === 'month' || key === 'tax_months' || key.includes('percent') || key === 'tax_rate') return String(value);
  return formatExactLocalAmount(String(value), 'PEN');
}

async function calculate() {
  const input = JSON.stringify(form.value);
  loading.value = true;
  error.value = '';
  try {
    const data = await client.calculateFinancialAuxiliary<Calculation>(props.mode, input);
    response.value = { input, data };
  } catch (reason) {
    showError(reason);
  } finally {
    loading.value = false;
  }
}
</script>

<template>
  <div class="space-y-4">
    <header>
      <h1 class="text-xl font-bold">{{ isLoan ? 'Préstamos recibidos' : 'Activos fijos' }}</h1>
      <p class="mt-1 text-sm text-muted">{{ isLoan ? 'Costo amortizado NIIF 9 y límite tributario' : 'Depreciación contable NIC 16 y fiscal' }} · Simulación en PEN · 2025</p>
    </header>

    <details class="rounded-lg border border-border bg-surface p-4" @toggle="($event.target as HTMLDetailsElement).open && listRecords()">
      <summary class="cursor-pointer font-semibold">Fichas guardadas</summary>
      <ul class="mt-2 divide-y divide-border">
        <li v-for="record in records" :key="record.reference" class="flex items-center justify-between gap-2 py-2 text-sm">
          <span>{{ record.reference }} · {{ record.label }} · Revisión {{ record.revision }}</span>
          <button class="ui-btn ui-btn-secondary" type="button" :disabled="loading" @click="openRecord(record.reference)"><FolderOpen /> Abrir</button>
        </li>
      </ul>
      <p v-if="!records.length && !loading" class="mt-2 text-sm text-muted">No hay fichas en esta página.</p>
      <div class="mt-2 flex flex-wrap gap-2">
        <button v-if="recordPage > 1" type="button" class="ui-btn ui-btn-secondary" :disabled="loading" @click="listRecords(recordPage - 1)">Anterior</button>
        <button v-if="hasLaterPage" type="button" class="ui-btn ui-btn-secondary" :disabled="loading" @click="listRecords(recordPage + 1)">Siguiente</button>
      </div>
      <div v-if="loadedReference" class="mt-2 flex flex-wrap items-end gap-2">
        <label class="ui-label">Revisión de {{ loadedReference }}
          <input v-model.number="revisionToOpen" type="number" min="1" class="block">
        </label>
        <button type="button" class="ui-btn ui-btn-secondary" :disabled="loading || !revisionToOpen" @click="openRecord(loadedReference, revisionToOpen)">Abrir revisión</button>
      </div>
    </details>

    <form class="space-y-4 rounded-lg border border-border bg-surface p-4" @submit.prevent="calculate">
      <div v-if="isLoan" class="grid gap-4 sm:grid-cols-2">
        <label v-for="[key, label] in loanFields" :key="key" class="ui-label">{{ label }}
          <input v-model.trim="loan[key]" required inputmode="decimal" class="mt-1 w-full">
        </label>
        <label class="ui-label sm:col-span-2">Cuotas mensuales
          <textarea v-model="payments" required rows="3" class="mt-1 w-full rounded-md border border-line bg-surf p-2"
            placeholder="Una cuota por línea. Use 0 en el mes sin pago." />
        </label>
        <label class="flex items-center gap-2 text-sm sm:col-span-2">
          <input v-model="loan.apply_ebitda_limit" type="checkbox"> Aplicar el límite del 30% del EBITDA tributario anterior
        </label>
        <label v-if="loan.apply_ebitda_limit" class="ui-label">EBITDA tributario anterior para el límite
          <input v-model.trim="loan.previous_tax_ebitda" required inputmode="decimal" class="mt-1 w-full">
        </label>
      </div>

      <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <label v-for="[key, label] in assetFields" :key="key" class="ui-label">{{ label }}
          <input v-model.trim="asset[key]" required inputmode="decimal" class="mt-1 w-full">
        </label>
        <label class="ui-label">Vida útil (meses)
          <input v-model.number="asset.useful_months" required type="number" min="1" max="1200" class="mt-1 w-full">
        </label>
        <label class="ui-label">Disponible para uso
          <input v-model="asset.available_from" required type="date" class="mt-1 w-full">
        </label>
        <label class="ui-label">Baja o reclasificación a mantenido para la venta
          <input v-model="asset.stop_on" type="date" class="mt-1 w-full">
        </label>
        <label class="ui-label">Inicio de uso en renta gravada
          <input v-model="asset.tax_use_from" type="date" class="mt-1 w-full">
        </label>
        <label class="ui-label">Último mes fiscal admitido en 2025
          <input v-model.number="asset.last_eligible_month" required type="number" min="0" max="12" class="mt-1 w-full">
        </label>
        <div>
          <label for="asset-tax-category" class="ui-label">Categoría fiscal ordinaria</label>
          <UiSelect id="asset-tax-category" v-model="asset.tax_category" :options="assetCategories" />
        </div>
      </div>
      <p class="text-xs text-muted">{{ isLoan
        ? 'Cuotas al final de cada mes. Verifique los costos atribuibles y la aplicación del límite antes de usar la conciliación.'
        : 'Línea recta contable con prorrateo diario. La depreciación fiscal se calcula por meses de uso y categoría ordinaria.' }}</p>
      <button class="ui-btn ui-btn-primary" type="submit" :disabled="loading">
        <Calculator /> {{ loading ? 'Calculando…' : 'Calcular' }}
      </button>
      <div v-if="canEdit" class="grid gap-3 border-t border-border pt-3 sm:grid-cols-3">
        <label class="ui-label">Código de la ficha <input v-model.trim="identity.reference" maxlength="40" class="w-full"></label>
        <label class="ui-label">Nombre <input v-model.trim="identity.label" maxlength="160" class="w-full"></label>
        <label class="ui-label">Referencia de contrato o documento <input v-model.trim="identity.source_reference" maxlength="255" class="w-full"></label>
        <button type="button" class="ui-btn ui-btn-secondary justify-self-start" :disabled="loading || !identity.reference || !identity.label || !identity.source_reference" @click="saveRecord">
          <Save /> Guardar ficha y cálculo
        </button>
        <p v-if="loadedReference" role="status" class="text-sm text-muted sm:col-span-2">Ficha {{ loadedReference }} · Revisión {{ identity.expected_revision }}</p>
      </div>
      <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>
    </form>

    <template v-if="result">
      <dl class="grid gap-x-6 rounded-lg border border-border bg-surface p-4 md:grid-cols-2">
        <div v-for="[key, label] in totals" :key="key" class="flex justify-between gap-3 border-b border-border py-2 text-sm">
          <dt>{{ label }}</dt><dd class="font-semibold tabular-nums">{{ displayAmount(key!, result[key!]) }}</dd>
        </div>
      </dl>
      <div class="overflow-x-auto rounded-lg border border-border bg-surface p-4">
        <table class="w-full whitespace-nowrap text-sm">
          <caption class="pb-3 text-left font-semibold">Cronograma contable</caption>
          <thead><tr>
            <th v-for="[key, label] in columns" :key="key" scope="col" class="p-2 text-right">{{ label }}</th>
          </tr></thead>
          <tbody>
            <tr v-for="row in result.rows" :key="row.month" class="border-t border-border">
              <td v-for="[key] in columns" :key="key" class="p-2 text-right tabular-nums">{{ displayAmount(key!, row[key!]) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
