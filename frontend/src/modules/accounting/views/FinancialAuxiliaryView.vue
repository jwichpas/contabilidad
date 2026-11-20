<script setup lang="ts">
import { computed, ref } from 'vue';
import { Calculator } from 'lucide-vue-next';
import { AccountingApiClient, ApiHttpError } from '../api/httpClient';
import type { CompanyId } from '../types/accountingAccount.types';
import { formatExactLocalAmount } from '../utils/formatExactLocalAmount';
import UiSelect from '@/shared/ui/UiSelect.vue';

const props = defineProps<{
  mode: 'loan' | 'asset';
  activeCompanyId: CompanyId;
  getAccessToken: () => string | null;
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
    error.value = reason instanceof ApiHttpError
      ? Object.values(reason.validationErrors).flat()[0] ?? reason.message
      : 'No se pudo calcular. Revise la conexión.';
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
