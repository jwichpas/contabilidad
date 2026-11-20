<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { Benefit, BenefitBatch, BenefitBatchRow, BenefitBatchSubmission } from './payroll.types';
import { ApiHttpError } from '@/modules/accounting/api/httpClient';
import { formatAccountingDate } from '@/modules/accounting/utils/journalPresentation';
import { formatExactLocalAmount } from '@/modules/accounting/utils/formatExactLocalAmount';

const props = defineProps<{
  month: string;
  refreshKey: number;
  preview: (kind: Benefit['kind'], date: string) => Promise<BenefitBatch>;
  save: (kind: Benefit['kind'], data: BenefitBatchSubmission) => Promise<{ saved: number }>;
}>();
const emit = defineEmits<{ review: [row: BenefitBatchRow, kind: Benefit['kind']] }>();
const postingDate = ref(props.month + '-01');
const kind = computed<Benefit['kind'] | undefined>(() => {
  const month = props.month.slice(5);
  if (month === '05' || month === '11') return 'CTS';
  if (month === '07' || month === '12') return 'GRATIFICATION';
  return undefined;
});
const lastDate = computed(() => {
  const year = Number(props.month.slice(0, 4));
  const month = Number(props.month.slice(5));
  return props.month + '-' + new Date(Date.UTC(year, month, 0)).getUTCDate();
});
const label = computed(() => kind.value === 'CTS' ? 'CTS' : 'Gratificaciones');
const batch = ref<BenefitBatch>();
const loading = ref(false);
const saving = ref(false);
const error = ref('');
const success = ref('');
const confirmed = ref(false);
const withholding = ref<Record<string, string>>({});
const ready = computed(() => batch.value?.rows.filter(row => row.status === 'ready') ?? []);
const review = computed(() => batch.value?.rows.filter(row => row.status === 'review').length ?? 0);
const missingHistory = computed(() => ready.value.some(row => row.status === 'ready' && row.missing_history.length));
const missingTax = computed(() => kind.value === 'GRATIFICATION' && ready.value.some(row => !(withholding.value[row.worker.code]?.trim())));
let generation = 0;

function money(amount?: string | null) {
  return amount == null ? '—' : formatExactLocalAmount(amount, 'PEN').replace(/^PEN (.*,[0-9]{2})00$/, 'S/ $1');
}
function errorMessage(reason: unknown) {
  return reason instanceof ApiHttpError && reason.status < 500
    ? (Object.values(reason.validationErrors).flat()[0] ?? reason.message)
    : 'No se pudo completar el cálculo por lote.';
}

async function load() {
  const sequence = ++generation;
  const selected = kind.value;
  batch.value = undefined;
  confirmed.value = false;
  error.value = '';
  if (!selected || !postingDate.value.startsWith(props.month + '-')) {
    loading.value = false;
    return;
  }
  loading.value = true;
  try {
    const result = await props.preview(selected, postingDate.value);
    if (sequence === generation) batch.value = result;
  } catch (reason) {
    if (sequence === generation) error.value = errorMessage(reason);
  } finally {
    if (sequence === generation) loading.value = false;
  }
}
watch(() => props.month, () => {
  postingDate.value = props.month + '-01';
  withholding.value = {};
  success.value = '';
});
watch([() => props.month, postingDate, () => props.refreshKey], load, { immediate: true });

function zeroWithholdings() {
  withholding.value = Object.fromEntries(ready.value.map(({ worker }) => [worker.code, '0']));
  confirmed.value = false;
}

async function save() {
  const selected = kind.value;
  if (!selected || !batch.value || !confirmed.value || missingTax.value || !ready.value.length || saving.value) return;
  const period = props.month;
  saving.value = true;
  error.value = '';
  success.value = '';
  try {
    const result = await props.save(selected, {
      posting_date: postingDate.value,
      token: batch.value.token,
      confirmed: confirmed.value,
      withholdings: selected === 'GRATIFICATION' ? withholding.value : {},
    });
    if (props.month === period) {
      await load();
      success.value = result.saved === 1 ? 'Se guardó 1 cálculo.' : `Se guardaron ${result.saved} cálculos.`;
    }
  } catch (reason) {
    if (props.month === period) error.value = errorMessage(reason);
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <section class="min-w-0 space-y-3 rounded-lg border border-line bg-surf p-3 sm:p-4" aria-labelledby="benefit-batch-heading">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div>
        <h2 id="benefit-batch-heading" class="font-semibold">{{ kind ? label + ' del periodo' : 'Cálculo por lote' }}</h2>
        <p v-if="batch" class="text-sm text-muted">
          Semestre {{ formatAccountingDate(batch.start) }} al {{ formatAccountingDate(batch.end) }} · Remuneración de {{ batch.base_month }}
        </p>
      </div>
      <label v-if="kind" class="ui-label">Fecha contable
        <input v-model="postingDate" type="date" :min="month + '-01'" :max="lastDate" :disabled="saving">
      </label>
    </div>
    <p v-if="!kind" class="text-sm text-muted">Selecciona mayo o noviembre para CTS, o julio o diciembre para gratificaciones.</p>
    <p v-else-if="!postingDate.startsWith(month + '-')" class="text-sm text-muted">Selecciona una fecha del periodo.</p>
    <p v-if="loading" role="status" class="text-sm text-muted">Revisando el semestre…</p>
    <p v-if="error" role="alert" class="text-sm text-neg">
      {{ error }} <button type="button" class="ml-2 underline" @click="load">Actualizar vista previa</button>
    </p>
    <p v-if="success" role="status" class="text-sm font-medium text-pos">{{ success }}</p>
    <p v-if="batch && !batch.rows.length" class="text-sm text-muted">No hay trabajadores vigentes en este periodo.</p>
    <template v-if="batch?.rows.length">
      <div class="flex flex-wrap items-center gap-2 text-sm">
        <span class="rounded-md bg-soft px-2 py-1">{{ ready.length }} por guardar</span>
        <span v-if="review" class="rounded-md bg-soft px-2 py-1 text-warn">{{ review }} por revisar</span>
        <button v-if="kind === 'GRATIFICATION' && ready.length" type="button" class="ui-btn ui-btn-sm ui-btn-secondary"
          :disabled="saving" @click="zeroWithholdings">Registrar retención cero para todos</button>
      </div>
      <div class="overflow-x-auto" role="region" aria-label="Cálculo por trabajador" tabindex="0">
        <table class="ui-table">
          <thead><tr><th scope="col">Trabajador</th><th scope="col">Base computable</th>
            <th scope="col">Meses / días</th><th scope="col">Beneficio</th>
            <th v-if="kind === 'GRATIFICATION'" scope="col">Bonificación / retención</th><th scope="col">Estado</th></tr></thead>
          <tbody>
            <tr v-for="row in batch.rows" :key="row.worker.code">
              <td><strong>{{ row.worker.given_names }} {{ row.worker.surname }}</strong><p class="text-xs text-muted">{{ row.worker.code }}</p></td>
              <td class="whitespace-nowrap tabular-nums">{{ money(row.status === 'saved' ? row.record.data.result.basis : row.result?.basis ?? row.input.base) }}</td>
              <td class="whitespace-nowrap tabular-nums">{{ row.status === 'saved' ? row.record.data.months : row.input.months }} /
                {{ row.status === 'saved' ? row.record.data.days : row.input.days }}</td>
              <td class="whitespace-nowrap font-medium tabular-nums">{{ money(row.status === 'saved' ? row.record.data.result.amount : row.result?.amount) }}</td>
              <td v-if="kind === 'GRATIFICATION'">
                <div class="whitespace-nowrap">{{ money(row.status === 'saved' ? row.record.data.result.bonus : row.result?.bonus) }}</div>
                <input v-if="row.status === 'ready'" v-model.trim="withholding[row.worker.code]" type="text" inputmode="decimal"
                  class="mt-1 w-24" :aria-label="'Retención validada de ' + row.worker.code" placeholder="Retención"
                  :disabled="saving" @input="confirmed = false">
                <p v-else-if="row.status === 'saved'" class="whitespace-nowrap text-xs text-muted">Retención {{ money(row.record.data.withholding) }}</p>
              </td>
              <td class="min-w-36">
                <span v-if="row.status === 'saved'" class="text-pos">Guardado</span>
                <template v-else-if="row.status === 'ready'">
                  <span class="text-brand">Por guardar</span>
                  <p v-if="row.missing_history.length" class="text-xs text-muted">Sin planilla aprobada en {{ row.missing_history.join(', ') }}</p>
                </template>
                <template v-else>
                  <ul class="space-y-0.5 text-xs text-warn"><li v-for="issue in row.issues" :key="issue">{{ issue }}</li></ul>
                  <button type="button" class="mt-1 text-xs text-brand underline" @click="emit('review', row, batch.kind)">Revisar trabajador</button>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="ready.length" class="flex flex-wrap items-center gap-3">
        <label class="flex max-w-2xl items-center gap-2 text-sm">
          <input v-model="confirmed" type="checkbox" :disabled="saving">
          Confirmo los importes y las incidencias del semestre{{ kind === 'CTS' ? ', y que la gratificación previa anotada fue percibida.' : ', incluida la retención validada.' }}
        </label>
        <button type="button" class="ui-btn ui-btn-primary ui-btn-sm" :disabled="!confirmed || missingTax || saving" @click="save">
          Guardar {{ ready.length }} {{ ready.length === 1 ? 'cálculo' : 'cálculos' }}
        </button>
      </div>
      <p v-if="kind === 'GRATIFICATION' && month.endsWith('-12') && ready.length" class="text-xs text-muted">
      Diciembre se proyecta hasta fin de mes según el vínculo registrado.
    </p>
    <p v-if="missingHistory" class="text-xs text-muted">Para los meses sin cierre aprobado, revisa las incidencias antes de confirmar el lote.</p>
    </template>
  </section>
</template>
