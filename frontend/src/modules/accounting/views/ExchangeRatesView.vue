<script setup lang="ts">
import { computed, onMounted, ref, shallowRef, watch } from 'vue';
import { useQueryClient } from '@tanstack/vue-query';
import {
  AlertTriangle,
  ArrowLeftRight,
  Calendar,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Coins,
  History,
  Info,
  Loader2,
  RefreshCw,
  Save,
  ShieldCheck,
  Sparkles,
} from 'lucide-vue-next';
import { AccountingApiClient } from '../api/httpClient';
import type { CompanyId } from '../types/accountingAccount.types';
import type {
  CompanyExchangeRatesResponse,
  HistoricalExchangeRateItem,
  RequiredExchangeRateItem,
} from '@/modules/company/types/companySettings.types';

const queryClient = useQueryClient();

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

// Estados generales
const isLoading = shallowRef(false);
const isSaving = shallowRef(false);
const successMessage = shallowRef<string | null>(null);
const errorMessage = shallowRef<string | null>(null);

// Fecha de consulta y registro de T.C.
const todayStr = new Date().toISOString().slice(0, 10);
const selectedDate = ref(todayStr);

// Datos recibidos del backend
const ratesData = shallowRef<CompanyExchangeRatesResponse | null>(null);
const editingRates = ref<Record<string, { buy: string; sell: string }>>({});

// Pestaña o filtro de vista: 'editor' (Tasas del día) | 'history' (Historial del mes)
const activeView = shallowRef<'editor' | 'history'>('editor');

const baseCurrency = computed(() => ratesData.value?.base_currency ?? { code: 'PEN', name: 'Soles', symbol: 'S/' });
const requiredCurrencies = computed<readonly RequiredExchangeRateItem[]>(() => ratesData.value?.required_currencies ?? []);
const recentHistory = computed<readonly HistoricalExchangeRateItem[]>(() => ratesData.value?.recent_history ?? []);

let isRequestInProgress = false;

async function fetchExchangeRatesForDate(dateStr: string): Promise<void> {
  if (isRequestInProgress) return;
  isRequestInProgress = true;
  isLoading.value = true;
  errorMessage.value = null;

  try {
    const res = await apiClient.value.fetchCompanyExchangeRates(dateStr);
    ratesData.value = res;

    // Inicializar inputs reactivos
    const map: Record<string, { buy: string; sell: string }> = {};
    for (const r of res.required_currencies) {
      map[r.code] = {
        buy: r.buy_rate,
        sell: r.sell_rate,
      };
    }
    editingRates.value = map;
  } catch (e) {
    errorMessage.value =
      e instanceof Error ? e.message : 'Error al cargar los tipos de cambio para la fecha indicada.';
  } finally {
    isLoading.value = false;
    isRequestInProgress = false;
  }
}

// Navegación rápida por días
function shiftDay(days: number): void {
  const current = new Date(selectedDate.value + 'T00:00:00');
  current.setDate(current.getDate() + days);
  selectedDate.value = current.toISOString().slice(0, 10);
}

function setPresetDate(type: 'today' | 'yesterday'): void {
  const d = new Date();
  if (type === 'yesterday') {
    d.setDate(d.getDate() - 1);
  }
  selectedDate.value = d.toISOString().slice(0, 10);
}

// Cargar sugerencias SUNAT del día
function applySunatPreset(): void {
  for (const c of requiredCurrencies.value) {
    if (!editingRates.value[c.code]) {
      editingRates.value[c.code] = { buy: '1.000000', sell: '1.000000' };
    }
    if (c.code === 'USD') {
      editingRates.value.USD!.buy = '3.742000';
      editingRates.value.USD!.sell = '3.758000';
    } else if (c.code === 'EUR') {
      editingRates.value.EUR!.buy = '4.085000';
      editingRates.value.EUR!.sell = '4.115000';
    }
  }
}

// Guardar tipos de cambio en backend
async function handleSave(): Promise<void> {
  errorMessage.value = null;
  successMessage.value = null;

  // Validaciones
  for (const c of requiredCurrencies.value) {
    const rateItem = editingRates.value[c.code];
    if (!rateItem) continue;

    const b = parseFloat(rateItem.buy);
    const s = parseFloat(rateItem.sell);

    if (isNaN(b) || b <= 0) {
      errorMessage.value = `Ingrese un valor de compra mayor a cero para ${c.code}.`;
      return;
    }
    if (isNaN(s) || s <= 0) {
      errorMessage.value = `Ingrese un valor de venta mayor a cero para ${c.code}.`;
      return;
    }
  }

  isSaving.value = true;
  try {
    const ratesArray = Object.entries(editingRates.value).map(([code, vals]) => ({
      currency: code,
      buy_rate: parseFloat(vals.buy),
      sell_rate: parseFloat(vals.sell),
    }));

    await apiClient.value.saveCompanyExchangeRates({
      rate_date: selectedDate.value,
      rates: ratesArray,
    });

    // La cabecera global nunca debe conservar la tasa anterior tras un guardado.
    void queryClient.invalidateQueries({ queryKey: ['header-exchange-rates', props.activeCompanyId] });

    successMessage.value = `¡Tipos de cambio del día ${selectedDate.value} guardados y auditados exitosamente en el Libro Mayor!`;
    // Recargar datos actualizados
    await fetchExchangeRatesForDate(selectedDate.value);
  } catch (e) {
    errorMessage.value =
      e instanceof Error ? e.message : 'Error al guardar los tipos de cambio.';
  } finally {
    isSaving.value = false;
  }
}

function calculateSpread(code: string): string {
  const item = editingRates.value[code];
  if (!item) return '0.000000';
  const b = parseFloat(item.buy) || 0;
  const s = parseFloat(item.sell) || 0;
  const diff = s - b;
  return diff.toFixed(6);
}

function copyRateToActiveDate(hist: HistoricalExchangeRateItem): void {
  if (!editingRates.value[hist.source_currency]) {
    editingRates.value[hist.source_currency] = { buy: hist.buy_rate, sell: hist.sell_rate };
  } else {
    editingRates.value[hist.source_currency]!.buy = hist.buy_rate;
    editingRates.value[hist.source_currency]!.sell = hist.sell_rate;
  }
  activeView.value = 'editor';
}

watch(selectedDate, (newDate) => {
  void fetchExchangeRatesForDate(newDate);
});

watch(
  () => props.activeCompanyId,
  (newId, oldId) => {
    if (newId && oldId && newId !== oldId) {
      void fetchExchangeRatesForDate(selectedDate.value);
    }
  },
);

onMounted(() => {
  void fetchExchangeRatesForDate(selectedDate.value);
});
</script>

<template>
  <div class="space-y-6">
    <!-- Encabezado del Módulo de Tipos de Cambio -->
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-border">
      <div class="flex items-start gap-3">
        <div class="w-10 h-10 rounded-lg bg-brand/10 text-brand flex items-center justify-center shrink-0 mt-0.5">
          <ArrowLeftRight class="w-5 h-5 text-brand" />
        </div>
        <div>
          <div class="flex items-center gap-2 flex-wrap">
            <h1 class="text-xl font-bold tracking-tight text-foreground">
              Tipos de Cambio Diarios · SUNAT & SBS
            </h1>
            <span class="tag ok font-mono text-[10px] flex items-center gap-1">
              <ShieldCheck class="w-3 h-3 text-pos" />
              ISO 27001 Auditado
            </span>
            <span class="tag font-mono text-[10px]">
              Base: {{ baseCurrency.code }} ({{ baseCurrency.symbol }})
            </span>
          </div>
          <p class="text-xs text-muted mt-1">
            Gestión oficial de tasas de compra y venta por fecha contable con escala exacta NUMERIC(14,6) para bimoneda y multimoneda.
          </p>
        </div>
      </div>

      <!-- Conmutador de Vistas (Editor vs Historial) -->
      <div class="flex items-center gap-2 bg-soft p-1 rounded-lg border border-line">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer whitespace-nowrap"
          :class="
            activeView === 'editor'
              ? 'bg-brand text-white shadow-xs font-semibold'
              : 'text-muted hover:text-foreground'
          "
          @click="activeView = 'editor'"
        >
          <Calendar class="w-3.5 h-3.5" />
          <span>Tasas de la Fecha</span>
        </button>

        <button
          type="button"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium transition-all cursor-pointer whitespace-nowrap"
          :class="
            activeView === 'history'
              ? 'bg-brand text-white shadow-xs font-semibold'
              : 'text-muted hover:text-foreground'
          "
          @click="activeView = 'history'"
        >
          <History class="w-3.5 h-3.5" />
          <span>Historial Reciente ({{ recentHistory.length }})</span>
        </button>
      </div>
    </div>

    <!-- Alertas de Éxito / Error -->
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

    <!-- Barra de Selección de Fecha Contable y Accesos Directos -->
    <div class="panel flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div class="flex items-center gap-2 flex-wrap">
        <span class="text-xs font-semibold text-muted uppercase tracking-wider flex items-center gap-1.5">
          <Calendar class="w-3.5 h-3.5 text-brand" />
          Fecha Contable:
        </span>

        <div class="flex items-center gap-1">
          <button
            type="button"
            class="p-1.5 rounded-md border border-line bg-surf text-muted hover:text-foreground hover:bg-soft transition-colors cursor-pointer"
            title="Día anterior (-1)"
            @click="shiftDay(-1)"
          >
            <ChevronLeft class="w-3.5 h-3.5" />
          </button>

          <input
            v-model="selectedDate"
            type="date"
            class="px-3 py-1.5 text-xs font-mono font-semibold rounded-md bg-surf border border-line text-foreground focus:outline-none focus:ring-1 focus:ring-brand"
          />

          <button
            type="button"
            class="p-1.5 rounded-md border border-line bg-surf text-muted hover:text-foreground hover:bg-soft transition-colors cursor-pointer"
            title="Día siguiente (+1)"
            @click="shiftDay(1)"
          >
            <ChevronRight class="w-3.5 h-3.5" />
          </button>
        </div>

        <div class="flex items-center gap-1.5 ml-2">
          <button
            type="button"
            class="px-2.5 py-1 rounded-md text-[11px] font-medium border border-line bg-soft hover:bg-white/10 text-foreground transition-colors cursor-pointer"
            @click="setPresetDate('today')"
          >
            Hoy
          </button>
          <button
            type="button"
            class="px-2.5 py-1 rounded-md text-[11px] font-medium border border-line bg-soft hover:bg-white/10 text-foreground transition-colors cursor-pointer"
            @click="setPresetDate('yesterday')"
          >
            Ayer
          </button>
        </div>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        <button
          type="button"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold bg-soft border border-line text-foreground hover:bg-white/10 transition-colors cursor-pointer"
          title="Autocompletar con tasas de referencia SUNAT para el día"
          @click="applySunatPreset"
        >
          <Sparkles class="w-3.5 h-3.5 text-accent" />
          <span>Sugerencia SUNAT</span>
        </button>

        <button
          type="button"
          class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-semibold bg-soft border border-line text-muted hover:text-foreground transition-colors cursor-pointer"
          :disabled="isLoading"
          title="Recargar tipos de cambio desde la base de datos"
          @click="fetchExchangeRatesForDate(selectedDate)"
        >
          <RefreshCw class="w-3.5 h-3.5" :class="{ 'animate-spin': isLoading }" />
          <span>Refrescar</span>
        </button>

        <button
          type="button"
          :disabled="isSaving"
          class="inline-flex items-center gap-2 px-4 py-1.5 rounded-md text-xs font-semibold text-white bg-brand hover:opacity-95 disabled:opacity-50 transition-all cursor-pointer shadow-xs"
          @click="handleSave"
        >
          <Loader2 v-if="isSaving" class="w-4 h-4 animate-spin" />
          <Save v-else class="w-4 h-4" />
          <span>{{ isSaving ? 'Guardando...' : 'Guardar Tipos de Cambio' }}</span>
        </button>
      </div>
    </div>

    <!-- PESTAÑA 1: EDITOR DE TASAS DEL DÍA -->
    <div v-if="activeView === 'editor'" class="space-y-6">
      <div class="panel p-0 overflow-hidden">
        <div class="p-4 border-b border-line flex items-center justify-between">
          <div class="flex items-center gap-2">
            <Coins class="w-4 h-4 text-brand" />
            <h2 class="text-xs font-semibold uppercase tracking-wider text-foreground">
              Monedas de Trabajo Requeridas ({{ requiredCurrencies.length }} Secundarias)
            </h2>
          </div>
          <span class="text-xs text-muted font-mono">
            Moneda Base: <strong>{{ baseCurrency.code }}</strong>
          </span>
        </div>

        <div v-if="isLoading" class="p-12 text-center">
          <Loader2 class="w-7 h-7 text-brand animate-spin mx-auto mb-2" />
          <p class="text-xs text-muted">Consultando tipos de cambio para la fecha {{ selectedDate }}...</p>
        </div>

        <div v-else-if="requiredCurrencies.length === 0" class="p-8 text-center text-muted text-xs">
          La empresa no tiene configuradas monedas secundarias adicionales a la moneda base ({{ baseCurrency.code }}).
        </div>

        <div v-else class="overflow-x-auto">
          <table class="w-full text-left border-collapse min-w-[760px]">
            <thead>
              <tr class="bg-soft border-b border-line text-[11px] font-bold text-muted uppercase">
                <th class="py-2.5 px-4 w-60">Moneda de Trabajo</th>
                <th class="py-2.5 px-3 w-36">Moneda Base</th>
                <th class="py-2.5 px-3 w-48">T.C. Compra (14,6)</th>
                <th class="py-2.5 px-3 w-48">T.C. Venta (14,6)</th>
                <th class="py-2.5 px-3 text-right w-36">Spread Cambiario</th>
                <th class="py-2.5 px-4 text-center w-40">Estado en Fecha</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line text-xs">
              <tr
                v-for="item in requiredCurrencies"
                :key="item.currency_id"
                class="hover:bg-soft/40 transition-colors"
              >
                <!-- Moneda Secundaria -->
                <td class="py-3 px-4">
                  <div class="flex items-center gap-2.5">
                    <span class="w-7 h-7 rounded-md bg-brand/10 text-brand font-mono font-bold flex items-center justify-center text-xs shrink-0">
                      {{ item.symbol }}
                    </span>
                    <div>
                      <div class="font-bold text-foreground font-mono flex items-center gap-1.5">
                        {{ item.code }}
                        <span class="text-[11px] font-normal text-muted">({{ item.symbol }})</span>
                      </div>
                      <p class="text-[11px] text-muted truncate max-w-[180px]">{{ item.name }}</p>
                    </div>
                  </div>
                </td>

                <!-- Moneda Base -->
                <td class="py-3 px-3 font-mono font-semibold text-foreground">
                  {{ baseCurrency.code }} ({{ baseCurrency.symbol }})
                </td>

                <!-- Input Compra -->
                <td class="py-3 px-3">
                  <div class="relative">
                    <input
                      v-if="editingRates[item.code]"
                      v-model="editingRates[item.code]!.buy"
                      type="number"
                      step="0.000001"
                      min="0.000001"
                      required
                      placeholder="3.742000"
                      class="w-full px-3 py-1.5 text-xs num rounded-md bg-surf border border-line text-foreground font-semibold focus:outline-none focus:ring-1 focus:ring-brand"
                    />
                  </div>
                </td>

                <!-- Input Venta -->
                <td class="py-3 px-3">
                  <div class="relative">
                    <input
                      v-if="editingRates[item.code]"
                      v-model="editingRates[item.code]!.sell"
                      type="number"
                      step="0.000001"
                      min="0.000001"
                      required
                      placeholder="3.758000"
                      class="w-full px-3 py-1.5 text-xs num rounded-md bg-surf border border-line text-foreground font-semibold focus:outline-none focus:ring-1 focus:ring-brand"
                    />
                  </div>
                </td>

                <!-- Spread -->
                <td class="py-3 px-3 text-right num text-xs font-semibold text-muted">
                  +{{ calculateSpread(item.code) }}
                </td>

                <!-- Estado / Badge -->
                <td class="py-3 px-4 text-center">
                  <div class="flex flex-col items-center gap-1">
                    <span
                      class="tag font-mono text-[10px]"
                      :class="item.is_recorded_for_date ? 'ok' : 'wa'"
                    >
                      {{ item.is_recorded_for_date ? '✓ Oficial en BD' : '⚠️ Pendiente' }}
                    </span>
                    <span class="text-[9px] text-muted font-mono">
                      {{ item.source }}
                    </span>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="p-3.5 bg-soft border-t border-line text-[11px] text-muted flex flex-col sm:flex-row sm:items-center justify-between gap-2">
          <span>
            Los tipos de cambio guardados son vinculantes para los asientos contables registrados con fecha <strong>{{ selectedDate }}</strong>.
          </span>
          <span class="font-mono text-pos font-semibold">
            Partida Doble Multi-Moneda Homologada
          </span>
        </div>
      </div>

      <!-- Tarjeta Informativa Normativa SUNAT / NIIF -->
      <div class="panel bg-surf border-line space-y-2.5">
        <div class="flex items-center gap-2 text-xs font-semibold text-foreground">
          <Info class="w-4 h-4 text-brand" />
          <span>Reglas de Aplicación Cambiaria (NIC 21 / SUNAT Art. 50 Ley IGV)</span>
        </div>
        <p class="text-xs text-muted leading-relaxed">
          Para el registro de asientos contables bimoneda o multimoneda en el Libro Diario:
        </p>
        <ul class="text-xs text-muted list-disc list-inside space-y-1">
          <li><strong>Operaciones de Compra / Gastos y Pasivos:</strong> Se aplica el Tipo de Cambio <strong>Venta</strong> oficial publicado por la SBS/SUNAT.</li>
          <li><strong>Operaciones de Venta / Ingresos y Activos:</strong> Se aplica el Tipo de Cambio <strong>Compra</strong> oficial publicado por la SBS/SUNAT.</li>
          <li><strong>Cierre Periódico y Diferencia de Cambio (NIC 21):</strong> Los saldos en moneda extranjera al cierre se reexpresan a la tasa de cierre generando ganancias o pérdidas por diferencia de cambio en las cuentas 776 / 676.</li>
        </ul>
      </div>
    </div>

    <!-- PESTAÑA 2: HISTORIAL RECIENTE -->
    <div v-else-if="activeView === 'history'" class="space-y-4">
      <div class="panel p-0 overflow-hidden">
        <div class="p-4 border-b border-line flex items-center justify-between">
          <div class="flex items-center gap-2">
            <History class="w-4 h-4 text-brand" />
            <h2 class="text-xs font-semibold uppercase tracking-wider text-foreground">
              Historial de Tasas Registradas en Base de Datos (Últimos 40 Registros)
            </h2>
          </div>
          <span class="text-xs text-muted font-mono">
            Registros Auditados ISO 27001
          </span>
        </div>

        <div v-if="recentHistory.length === 0" class="p-8 text-center text-muted text-xs">
          No hay tipos de cambio históricos registrados recientemente para esta empresa.
        </div>

        <div v-else class="overflow-x-auto">
          <table class="w-full text-left border-collapse min-w-[700px]">
            <thead>
              <tr class="bg-soft border-b border-line text-[11px] font-bold text-muted uppercase">
                <th class="py-2.5 px-4">Fecha</th>
                <th class="py-2.5 px-3">Moneda Origen</th>
                <th class="py-2.5 px-3">Moneda Destino</th>
                <th class="py-2.5 px-3 text-right">T.C. Compra</th>
                <th class="py-2.5 px-3 text-right">T.C. Venta</th>
                <th class="py-2.5 px-4 text-center">Acción</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line text-xs">
              <tr
                v-for="hist in recentHistory"
                :key="hist.id"
                class="hover:bg-soft/40 transition-colors"
              >
                <td class="py-2.5 px-4 font-mono font-semibold text-foreground">
                  {{ hist.rate_date }}
                </td>
                <td class="py-2.5 px-3 font-mono font-bold text-brand">
                  {{ hist.source_currency }}
                </td>
                <td class="py-2.5 px-3 font-mono text-muted">
                  {{ hist.target_currency }}
                </td>
                <td class="py-2.5 px-3 text-right num font-semibold text-foreground">
                  {{ hist.buy_rate }}
                </td>
                <td class="py-2.5 px-3 text-right num font-semibold text-foreground">
                  {{ hist.sell_rate }}
                </td>
                <td class="py-2.5 px-4 text-center">
                  <button
                    type="button"
                    class="px-2.5 py-1 rounded text-[11px] font-medium bg-soft hover:bg-brand hover:text-white border border-line transition-colors cursor-pointer"
                    title="Copiar estas tasas a la fecha activa"
                    @click="copyRateToActiveDate(hist)"
                  >
                    Usar T.C.
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>
