<script setup lang="ts">
import { ref } from 'vue';
import UiSelect from '@/shared/ui/UiSelect.vue';
import type { Attendance, PayrollInput, PayrollInputDraft, Revision, Worker } from './payroll.types';

const props = defineProps<{
  month: string;
  worker: Worker;
  saved?: Revision<PayrollInput>;
  attendance?: Attendance;
  canImport: boolean;
  catalogAfp: boolean;
  busy: boolean;
}>();
const emit = defineEmits<{ save: [data: PayrollInputDraft]; close: [] }>();
const workFields = [
  ['paid_days', 'Días equivalentes remunerados', 30], ['vacation_paid_days', 'Días de vacaciones incluidos', 30],
  ['ordinary_minutes', 'Minutos ordinarios', 21659], ['overtime_25', 'Minutos extra al 25%', 21659],
  ['overtime_35', 'Minutos extra al 35%', 21659],
] as const;
const extraFields = [
  ['taxable_extra', 'Otros ingresos afectos'], ['nontaxable_extra', 'Otros ingresos inafectos'],
  ['income_tax', 'Retención de quinta validada'], ['other_deductions', 'Otros descuentos'],
] as const;
const afpFields = [
  ['insurance', 'Prima SBS (%)'], ['commission', 'Comisión sobre flujo (%)'],
  ['insurance_ceiling', 'Tope asegurable (S/)'], ['source', 'Referencia SBS'],
] as const;
const provisionFields = [
  ['gratification_base', 'Base computable de gratificación'], ['cts_base', 'Base CTS'],
  ['prior_gratification', 'Gratificación previa computable'], ['vacation_base', 'Base vacacional'],
] as const;
const coverageOptions = [{ value: 'ESSALUD', label: 'EsSalud' }, { value: 'EPS', label: 'EPS' }] as const;

const year = Number(props.month.slice(0, 4));
const month = Number(props.month.slice(5));
const first = props.month + '-01';
const last = `${props.month}-${new Date(Date.UTC(year, month, 0)).getUTCDate()}`;
const start = props.worker.hired_on > first ? props.worker.hired_on : first;
const finish = props.worker.ended_on && props.worker.ended_on < last ? props.worker.ended_on : last;
const paidDays = start === first && finish === last ? 30 : Math.min(30, (Date.parse(finish) - Date.parse(start)) / 86_400_000 + 1);
const saved = props.saved?.data;
const legacy = props.attendance;
const rates = saved ? saved.afp_rates : legacy?.afp_rates;
const provisions = saved ? saved.provisions : legacy?.provisions;
const balance = props.worker.afp_commission === 'BALANCE';
const form = ref<PayrollInput>({
  worker: props.worker.code, paid_days: paidDays, vacation_paid_days: 0, ordinary_minutes: 0, overtime_25: 0, overtime_35: 0,
  taxable_extra: legacy?.taxable_extra ?? '0', nontaxable_extra: legacy?.nontaxable_extra ?? '0', income_tax: legacy?.income_tax ?? '0',
  other_deductions: legacy?.other_deductions ?? '0', reference: '', input_source: 'manual', jornada_confirmed: false,
  ...saved,
  provisions: provisions ? { ...provisions } : null,
  afp_rates: props.worker.pension === 'AFP' && !props.catalogAfp
    ? { insurance: rates?.insurance ?? '', commission: balance ? '0' : rates?.commission ?? '',
      insurance_ceiling: rates?.insurance_ceiling ?? '', source: rates?.source ?? '' }
    : null,
});

const importRequested = ref(false);

function importAttendance() {
  if (!props.canImport || !props.attendance) return;
  for (const [key] of workFields) form.value[key] = props.attendance[key];
  form.value.input_source = 'attendance';
  importRequested.value = true;
  form.value.jornada_confirmed = true;
}

function toggleProvisions() {
  form.value.provisions = form.value.provisions ? null : {
    gratification_base: '', gratification_month: false, cts_base: '', prior_gratification: '',
    cts_days: 0, vacation_base: '', vacation_days: 0, coverage: 'ESSALUD', reference: '',
  };
}

function submit() {
  const data = { ...form.value };
  if (data.afp_rates && !data.afp_rates.insurance && !data.afp_rates.source && !data.afp_rates.insurance_ceiling) data.afp_rates = null;
  emit('save', { ...data, expected_revision: props.saved?.revision ?? 0, import_attendance: importRequested.value });
}
</script>

<template>
  <form class="space-y-4 rounded-lg border border-line bg-surf p-4" @submit.prevent="submit">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <div><h2 class="font-semibold">Datos de planilla · {{ worker.given_names }} {{ worker.surname }}</h2>
        <p class="text-sm text-muted">{{ form.input_source === 'attendance' ? (importRequested ? 'Se copiará el cierre vigente al guardar.' : 'Usa la jornada importada previamente.') : 'Ingreso directo en Planilla mensual.' }}</p>
      </div>
      <button v-if="canImport" type="button" class="ui-btn ui-btn-secondary ui-btn-sm" :disabled="busy" @click="importAttendance">Importar cierre de asistencia</button>
    </div>
    <fieldset :disabled="busy" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      <label v-for="[key,label,max] in workFields" :key="key" class="ui-label">{{ label }}
        <input v-model.number="form[key]" type="number" min="0" :max="max"
          :readonly="form.input_source === 'attendance'" required>
      </label>
      <div v-if="form.input_source === 'attendance'" class="flex items-end">
        <button type="button" class="ui-btn ui-btn-sm ui-btn-secondary" @click="form.input_source = 'manual'">Editar jornada manualmente</button>
      </div>
      <label v-else class="flex items-center gap-2 text-sm">
        <input v-model="form.jornada_confirmed" type="checkbox"> Jornada confirmada para exportación PLAME
      </label>
      <label v-for="[key,label] in extraFields" :key="key" class="ui-label">{{ label }}
        <input v-model.trim="form[key]" inputmode="decimal" required>
      </label>
      <label class="ui-label">Referencia (opcional)<input v-model.trim="form.reference" maxlength="500"></label>
    </fieldset>
    <fieldset v-if="form.afp_rates" :disabled="busy" class="grid gap-3 rounded-md border border-line p-3 sm:grid-cols-2">
      <legend class="px-1 text-sm font-semibold">Tasas AFP del devengue</legend>
      <label v-for="[key,label] in afpFields" :key="key" class="ui-label">{{ label }}
        <input v-model.trim="form.afp_rates[key]" :readonly="key === 'commission' && balance"
          :inputmode="key === 'source' ? 'text' : 'decimal'">
      </label>
    </fieldset>
    <fieldset :disabled="busy" class="space-y-2 rounded-md border border-line p-3">
      <legend class="px-1 text-sm font-semibold">Provisiones del mes</legend>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" :checked="!!form.provisions" @change="toggleProvisions"> Estimar provisiones</label>
      <div v-if="form.provisions" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
        <label v-for="[key,label] in provisionFields" :key="key" class="ui-label">{{ label }}
          <input v-model.trim="form.provisions[key]" inputmode="decimal" required>
        </label>
        <label class="ui-label">Días computables CTS<input v-model.number="form.provisions.cts_days" type="number" min="0" max="30" required></label>
        <label class="ui-label">Días computables de vacaciones<input v-model.number="form.provisions.vacation_days" type="number" min="0" max="30" required></label>
        <div>
          <label for="payroll-provision-coverage" class="ui-label">Cobertura para bonificación</label>
          <UiSelect id="payroll-provision-coverage" v-model="form.provisions.coverage" :options="coverageOptions" :disabled="busy" />
        </div>
        <label class="flex items-center gap-2 text-sm"><input v-model="form.provisions.gratification_month" type="checkbox"> Mes completo computable para gratificación</label>
        <label class="ui-label">Sustento de bases y días<input v-model.trim="form.provisions.reference" maxlength="500" required></label>
      </div>
    </fieldset>
    <div class="flex gap-2">
      <button type="submit" class="ui-btn ui-btn-primary ui-btn-sm" :disabled="busy">Guardar datos de planilla</button>
      <button type="button" class="ui-btn ui-btn-secondary ui-btn-sm" :disabled="busy" @click="emit('close')">Cerrar</button>
    </div>
  </form>
</template>
