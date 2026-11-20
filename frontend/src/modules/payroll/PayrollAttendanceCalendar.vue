<script setup lang="ts">
import { computed } from 'vue';
import { formatAccountingDate } from '@/modules/accounting/utils/journalPresentation';
import type { AttendanceDay, Revision, Worker } from './payroll.types';

const props = defineProps<{ month: string; today: string; worker: Worker; records: Revision<AttendanceDay>[]; disabled: boolean }>();
const emit = defineEmits<{ select: [date: string]; save: [] }>();
const day = defineModel<Omit<AttendanceDay, 'worker'> & { expected_revision: number }>('day', { required: true });
const states = [
  { key: 'WORKED', name: 'Laborado', short: 'Lab.', color: 'text-pos' },
  { key: 'ABSENT', name: 'Falta', short: 'Falta', color: 'text-neg' },
  { key: 'REST', name: 'Descanso', short: 'Desc.', color: 'text-muted' },
  { key: 'VACATION', name: 'Vacaciones', short: 'Vac.', color: 'text-brand' },
  { key: 'PAID_LEAVE', name: 'Licencia con goce', short: 'Lic.', color: 'text-brand' },
  { key: 'UNPAID', name: 'Licencia sin goce', short: 'S/g', color: 'text-warn' },
];
const byDate = computed(() => new Map(props.records.map(({ data }) => [data.date, data.status])));
const weekdays = new Intl.DateTimeFormat('es-PE', { weekday: 'short', timeZone: 'UTC' });
const days = computed(() => {
  const year = Number(props.month.slice(0, 4));
  const month = Number(props.month.slice(5));
  const count = new Date(Date.UTC(year, month, 0)).getUTCDate();
  return Array.from({ length: count }, (_, index) => {
    const number = index + 1;
    const date = `${props.month}-${String(number).padStart(2, '0')}`;
    const weekday = weekdays.format(new Date(date + 'T00:00:00Z'));
    const state = states.find(({ key }) => key === byDate.value.get(date));
    return { date, number, weekday, state, eligible: date >= props.worker.hired_on && (!props.worker.ended_on || date <= props.worker.ended_on) };
  });
});
</script>

<template>
  <div class="min-w-0 space-y-3">
    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
      <p><strong>{{ records.length }}</strong> días registrados · <strong class="text-neg">{{ records.filter(({ data }) => data.status === 'ABSENT').length }}</strong> faltas</p>
      <p class="text-muted">Desplaza el calendario para recorrer el mes.</p>
    </div>
    <div class="max-w-full overflow-x-auto rounded-lg border border-line bg-soft p-2" role="region" aria-label="Días del mes">
      <div class="flex w-max gap-1.5">
        <button v-for="item in days" :key="item.date" type="button"
          class="flex h-20 w-14 shrink-0 flex-col items-center justify-center gap-0.5 rounded-md border bg-surf text-xs disabled:opacity-45"
          :class="day.date === item.date ? 'border-brand bg-brand/10' : 'border-line hover:border-brand'"
          :disabled="!item.eligible"
          :aria-label="`${formatAccountingDate(item.date)}, ${item.state?.name ?? 'Sin registro'}`"
          :aria-pressed="day.date === item.date"
          :aria-current="item.date === today ? 'date' : undefined"
          @click="emit('select', item.date)">
          <span class="text-muted">{{ item.weekday }}</span>
          <span class="text-base font-semibold tabular-nums">{{ item.number }}</span>
          <span :class="item.state?.color ?? 'text-muted'">{{ item.state?.short ?? '—' }}</span>
        </button>
      </div>
    </div>
    <p v-if="!day.date" class="text-sm text-muted">Selecciona un día para ver o registrar su estado.</p>
    <form v-else class="space-y-3 rounded-lg border border-line p-3" @submit.prevent="emit('save')">
      <fieldset :disabled="disabled" class="space-y-3">
        <legend class="px-1 text-sm font-semibold">{{ formatAccountingDate(day.date) }}</legend>
        <div class="flex flex-wrap gap-2">
          <button v-for="state in states" :key="state.key" type="button" class="ui-btn ui-btn-sm"
            :class="state.key === day.status ? 'ui-btn-primary' : 'ui-btn-secondary'"
            :aria-pressed="state.key === day.status" @click="day.status = state.key">{{ state.name }}</button>
        </div>
        <div class="grid gap-2 sm:grid-cols-3">
          <template v-if="day.status === 'WORKED'">
            <label class="ui-label">Minutos trabajados
              <input v-model.number="day.ordinary" type="number" min="0" :max="worker.daily_hours * 60" required>
            </label>
            <label class="ui-label">Minutos extra<input v-model.number="day.extra" type="number" min="0" max="960" required></label>
          </template>
          <label class="ui-label">Observación (opcional)<input v-model.trim="day.reference" maxlength="500"></label>
        </div>
      </fieldset>
      <button type="submit" class="ui-btn ui-btn-primary ui-btn-sm" :disabled="disabled">Guardar día</button>
    </form>
  </div>
</template>
