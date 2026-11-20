<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Plus } from 'lucide-vue-next';
import PayrollMonthlyView from './PayrollMonthlyView.vue';
import PayrollParametersView from './PayrollParametersView.vue';
import { payrollRoutes, type PayrollModule } from '@/shared/layout/navigation.types';
import UiSelect from '@/shared/ui/UiSelect.vue';
import { AccountingApiClient, ApiHttpError } from '@/modules/accounting/api/httpClient';
import type { CompanyId } from '@/modules/accounting/types/accountingAccount.types';
import type { TaxJournalDraft } from '@/modules/accounting/types/annualTaxClose.types';
import { formatAccountingDate, peruAccountingDate } from '@/modules/accounting/utils/journalPresentation';
import { formatExactLocalAmount } from '@/modules/accounting/utils/formatExactLocalAmount';
import type { Attendance, AttendanceDay, Benefit, PayrollParameterEntry, PayrollParameterRevision, PayrollWorkspace, Revision, Worker } from './payroll.types';

const props = defineProps<{ activeCompanyId: CompanyId; getAccessToken: () => string | null; ruc: string; section: PayrollModule }>();
const emit = defineEmits<{ journal: [draft: TaxJournalDraft]; navigate: [module: PayrollModule] }>();
const api = new AccountingApiClient('', { getActiveCompanyId: () => props.activeCompanyId, getAccessToken: props.getAccessToken });
const today = peruAccountingDate();
const month = ref(today.slice(0, 7));
const workspace = ref<PayrollWorkspace>();
const busy = ref(false);
const error = ref('');
const message = ref('');
const workforce = computed(() => workspace.value?.workers.map(({ data }) => ({
  value: data.code, label: `${data.given_names} ${data.surname} · ${data.code}`,
})) ?? []);
const currentWorkers = computed(() => workspace.value?.eligible.map(({data}) => ({
  value: data.code, label: `${data.given_names} ${data.surname} · ${data.code}`,
})) ?? []);
const monthly = computed(() => workspace.value?.payroll);
const pensionOptions = [{ value: 'ONP', label: 'ONP' }, { value: 'AFP', label: 'AFP' }];
const afpOptions = [{ value: 'HA', label: 'Hábitat' }, { value: 'IN', label: 'Integra' },
  { value: 'PR', label: 'Profuturo' }, { value: 'RI', label: 'Prima' }];
const afpCommissionOptions = [{ value: 'FLOW', label: 'Sobre flujo' }, { value: 'BALANCE', label: 'Sobre saldo' }] as const;
const coverageOptions = [{ value: 'ESSALUD', label: 'EsSalud' }, { value: 'EPS', label: 'EPS' }] as const;
const provisionFields = [
  ['gratification_base', 'Base computable de la gratificación'],
  ['cts_base', 'Base CTS antes de añadir la gratificación'],
  ['prior_gratification', 'Gratificación previa computable'],
  ['vacation_base', 'Base vacacional'],
] as const;
const kindOptions = [{ value: 'CTS', label: 'CTS' }, { value: 'GRATIFICATION', label: 'Gratificación' }] as const;

const worker = ref<(Worker & { expected_revision: number })>();
const workerCodePattern = String.raw`[A-Z0-9][A-Z0-9\-]{0,19}`;
const workerText = [['code','Código interno'], ['dni','DNI'], ['given_names','Nombres'], ['surname','Apellido paterno'],
  ['second_surname','Apellido materno'], ['position','Cargo']] as const;
const attendance = ref<Attendance & { expected_revision: number }>();
const selectedAttendance = ref('');
const attendanceCommissionOnBalance = computed(() =>
  workspace.value?.eligible.find(({ reference }) => reference === selectedAttendance.value)?.data.afp_commission === 'BALANCE',
);
const day = ref({ date: '', ordinary: 0, extra: 0, status: 'WORKED', reference: '', expected_revision: 0 });
const dayStatus = [{ value: 'WORKED', label: 'Laborado' }, { value: 'REST', label: 'Descanso' },
  { value: 'VACATION', label: 'Vacaciones' }, { value: 'PAID_LEAVE', label: 'Licencia con goce' }, { value: 'UNPAID', label: 'Sin goce' }];
const workerDays = computed(() => workspace.value?.daily.filter((entry) => entry.data.worker === selectedAttendance.value) ?? []);
const minutesFields = [
  ['paid_days','Días equivalentes remunerados',30], ['vacation_paid_days','Días equivalentes de vacaciones incluidos',30],
  ['ordinary_minutes','Minutos ordinarios trabajados',21659], ['overtime_25','Minutos extra al 25%',21659],
  ['overtime_35','Minutos extra al 35%',21659],
] as const;
const extraFields = [['taxable_extra','Otros ingresos afectos a pensión y salud'], ['nontaxable_extra', 'Otros ingresos inafectos a pensión y salud'],
  ['income_tax','Retención de quinta validada'], ['other_deductions','Otros descuentos']] as const;
const afpFields = [['insurance','Prima SBS (%)'], ['commission','Comisión sobre flujo (%)'],
  ['insurance_ceiling','Remuneración máxima asegurable'], ['source','Fuente y vigencia de tasas SBS']] as const;
const minimum = ref('');
const source = ref('');
const vacation = ref({ worker: '', from: '', to: '', reference: '' });
const benefit = ref({ worker: '', kind: 'CTS' as Benefit['kind'], base: '', months: 6, days: 0,
  previous_gratification: '0', health: 'ESSALUD', posting_date: today, withholding: '0', reference: '', expected_revision: 0 });

function money(value: string | undefined) { return formatExactLocalAmount(value ?? '0', 'PEN'); }
async function run(action: () => Promise<void>) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  message.value = '';
  try { await action(); }
  catch (reason) {
    if (reason instanceof ApiHttpError && reason.status === 503) {
      error.value = 'Planillas no está disponible. Si la base está pendiente de actualizar, aplica la migración de Planillas y vuelve a intentar.';
    } else if (reason instanceof ApiHttpError && reason.status < 500) {
      error.value = Object.values(reason.validationErrors).flat()[0] ?? reason.message;
    } else {
      error.value = 'No se pudo completar la operación de planillas.';
    }
  } finally { busy.value = false; }
}

async function reload() {
  const period = month.value;
  const data = await api.payrollRequest<PayrollWorkspace>(`periods/${period}`);
  if (period !== month.value) return;
  workspace.value = data;
  minimum.value = (workspace.value.payroll?.data.approved ? workspace.value.payroll.data.health_minimum : workspace.value.parameters?.minimum?.amount)
    ?? workspace.value.payroll?.data.health_minimum ?? '';
  source.value = workspace.value.payroll?.data.source ?? '';
}

function selectMonth(value: string) {
  if (!value || value === month.value) return;
  void run(async () => {
    month.value = value;
    workspace.value = undefined;
    attendance.value = undefined;
    worker.value = undefined;
    selectedAttendance.value = '';
    benefit.value.posting_date = value + '-01';
    selectBenefit(benefit.value.worker, benefit.value.kind);
    await reload();
    selectBenefit(benefit.value.worker, benefit.value.kind);
  });
}

function editWorker(entry?: Revision<Worker>) {
  worker.value = entry ? { ...entry.data, expected_revision: entry.revision } : {
    code: '', dni: '', surname: '', second_surname: null, given_names: '', position: '',
    effective_month: month.value, hired_on: '', ended_on: null, salary: '', daily_hours: 8,
    pension: 'ONP', afp: null, cuspp: null, family_allowance: false, night_shift: false, vacation_granted: 0, reference: '', expected_revision: 0,
  };
}

function saveWorker() {
  const draft = worker.value;
  if (!draft) return;
  if (draft.pension === 'AFP' && !draft.afp_commission) {
    error.value = 'Selecciona el esquema de comisión AFP de la ficha.';
    return;
  }
  const { code, ...payload } = draft;
  void run(async () => {
    await api.payrollRequest(`workers/${encodeURIComponent(code)}`, 'PUT', {
      ...payload, afp: payload.pension === 'AFP' ? payload.afp : null, cuspp: payload.pension === 'AFP' ? payload.cuspp : null,
    });
    worker.value = undefined;
    await reload();
    message.value = 'Ficha guardada.';
  });
}

function selectAttendance(code: string) {
  selectedAttendance.value = code;
  editDay();
  const saved = workspace.value?.attendance.find((row) => row.data.worker === code);
  const person = workspace.value?.eligible.find((row) => row.reference === code)?.data;
  const data = saved?.data;
  const afp = monthly.value?.data.approved ? monthly.value.data.parameters?.afp : workspace.value?.parameters?.afp;
  const rates = person?.afp ? afp?.administrators[person.afp] : undefined;
  const balance = person?.afp_commission === 'BALANCE';
  attendance.value = {
    worker: code, paid_days: 0, vacation_paid_days: 0, ordinary_minutes: 0, overtime_25: 0, overtime_35: 0, taxable_extra: '0',
    nontaxable_extra: '0', income_tax: '0', other_deductions: '0', reference: '', ...data,
    expected_revision: saved?.revision ?? 0,
    provisions: data?.provisions ? { ...data.provisions } : null,
    afp_rates: person?.pension === 'AFP' ? (afp && rates
      ? { insurance: afp.insurance, commission: balance ? '0' : rates.flow,
        insurance_ceiling: afp.insurance_ceiling, source: afp.source }
      : { insurance: data?.afp_rates?.insurance ?? '', commission: balance ? '0' : data?.afp_rates?.commission ?? '',
        insurance_ceiling: data?.afp_rates?.insurance_ceiling ?? '', source: data?.afp_rates?.source ?? '' }) : null,
  };
}

function openAttendance(code: string) {
  selectAttendance(code);
  emit('navigate', 'payroll-attendance');
}

function editDay(entry?: Revision<AttendanceDay>) {
  const saved = entry?.data;
  day.value = { date: saved?.date ?? month.value + '-01', ordinary: saved?.ordinary ?? 0, extra: saved?.extra ?? 0,
    status: saved?.status ?? 'WORKED', reference: saved?.reference ?? '', expected_revision: entry?.revision ?? 0 };
}

function saveDay() {
  if (!selectedAttendance.value || !day.value.date) return;
  void run(async () => {
    await api.payrollRequest(`periods/${month.value}/attendance/${selectedAttendance.value}/days/${day.value.date}`, 'PUT', day.value);
    const date = day.value.date;
    await reload();
    editDay(workerDays.value.find(({ data }) => data.date === date));
    message.value = 'Día de asistencia guardado.';
  });
}

function bringMinutes() {
  if (!attendance.value) return;
  const totals = workerDays.value.reduce((sum, { data }) => ({
    ordinary: sum.ordinary + data.ordinary, lower: sum.lower + Math.min(data.extra, 120),
    higher: sum.higher + Math.max(data.extra - 120, 0),
  }), { ordinary: 0, lower: 0, higher: 0 });
  attendance.value.ordinary_minutes = totals.ordinary;
  attendance.value.overtime_25 = totals.lower;
  attendance.value.overtime_35 = totals.higher;
}

function toggleProvisions() {
  if (!attendance.value) return;
  attendance.value.provisions = attendance.value.provisions ? null : {
    gratification_base: '', gratification_month: false, cts_base: '', prior_gratification: '',
    cts_days: 0, vacation_base: '', vacation_days: 0, coverage: 'ESSALUD', reference: '',
  };
}

function saveAttendance() {
  if (!attendance.value) return;
  const { worker: code, ...payload } = attendance.value;
  if (payload.afp_rates && Object.values(payload.afp_rates).every((value) => value === '')) payload.afp_rates = null;
  void run(async () => {
    await api.payrollRequest(`periods/${month.value}/attendance/${code}`, 'PUT', payload);
    await reload();
    selectAttendance(code);
    message.value = 'Asistencia guardada.';
  });
}

function loadParameterHistory() {
  return api.payrollRequest<PayrollParameterRevision[]>('parameters');
}

async function saveParameter(entry: PayrollParameterEntry, expected_revision: number) {
  const { kind, period, ...values } = entry;
  await api.payrollRequest(`parameters/${kind}/${period}`, 'PUT', { ...values, expected_revision });
  try {
    await reload();
  } catch {
    error.value = 'Se registró la versión. Recarga la página para actualizar los parámetros del periodo.';
  }
}

function calculate() {
  void run(async () => {
    await api.payrollRequest(`periods/${month.value}/calculation`, 'PUT', {
      expected_revision: monthly.value?.revision ?? 0, health_minimum: minimum.value, source: source.value,
    });
    await reload();
    message.value = 'Cálculo actualizado.';
  });
}

function approve() {
  const revision = monthly.value?.revision;
  if (!revision || !window.confirm('¿Aprobar y congelar la planilla del periodo?')) return;
  void run(async () => {
    await api.payrollRequest(`periods/${month.value}/approval`, 'POST', { expected_revision: revision });
    await reload();
    message.value = 'Planilla aprobada.';
  });
}

function download(format: 'jor' | 'afp') {
  void run(async () => {
    const { blob, filename } = await api.downloadPayroll(month.value, format);
    const href = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = href;
    const period = month.value.replace('-', '');
    anchor.download = filename || (format === 'jor' ? `0601${period}${props.ruc}.jor` : `AFPNET-${props.ruc}-${period}.txt`);
    anchor.click();
    window.setTimeout(() => URL.revokeObjectURL(href), 1000);
  });
}

function saveVacation() {
  const { worker: code, ...payload } = vacation.value;
  if (!code) return;
  void run(async () => {
    await api.payrollRequest(`workers/${code}/vacations`, 'POST', payload);
    vacation.value = { worker: code, from: '', to: '', reference: '' };
    await reload();
    message.value = 'Descanso registrado.';
  });
}

function selectBenefit(code: string, kind: Benefit['kind']) {
  const existing = workspace.value?.benefits.find((entry) => entry.data.worker === code && entry.data.kind === kind);
  benefit.value = {
    worker: code, kind, base: existing?.data.base ?? '', months: existing?.data.months ?? 6, days: existing?.data.days ?? 0,
    health: existing?.data.health ?? 'ESSALUD', previous_gratification: existing?.data.previous_gratification ?? '0',
    posting_date: existing?.data.posting_date ?? month.value + '-01', withholding: existing?.data.withholding ?? '0',
    reference: existing?.data.reference ?? '', expected_revision: existing?.revision ?? 0,
  };
}

function saveBenefit() {
  const { worker: code, kind, ...payload } = benefit.value;
  if (!code) return;
  void run(async () => {
    await api.payrollRequest(`periods/${month.value}/benefits/${code}/${kind}`, 'PUT', payload);
    await reload();
    selectBenefit(code, kind);
    message.value = 'Beneficio calculado y guardado.';
  });
}

function vacationBalance(person: Worker) {
  return person.vacation_granted - (workspace.value?.vacations.filter(({ data }) => data.worker === person.code)
    .reduce((days, { data }) => days + data.days, 0) ?? 0);
}
watch(() => props.section, () => {
  message.value = '';
  if (workspace.value) error.value = '';
});
void run(reload);
</script>

<template>
  <div class="space-y-4">
    <header class="flex flex-wrap items-end justify-between gap-3">
      <div><h1 class="text-xl font-bold">{{ payrollRoutes[section].label }}</h1><p class="text-sm text-muted">Régimen general privado · Remuneración mensual en soles</p></div>
      <label class="ui-label">Periodo <input type="month" :value="month" :disabled="busy" @change="selectMonth(($event.target as HTMLInputElement).value)"></label>
    </header>
    <p v-if="busy" role="status" class="text-sm text-muted">Procesando planillas…</p>
    <div v-if="error" role="alert" class="rounded-md border border-line bg-surf p-3 text-sm text-neg">
      {{ error }} <button v-if="!workspace" class="ui-btn ui-btn-ghost ui-btn-sm" @click="run(reload)">Reintentar</button>
    </div>
    <p v-if="message" role="status" class="text-sm text-pos">{{ message }}</p>

    <section v-if="workspace && section === 'payroll-workers'" class="space-y-3 rounded-lg border border-line bg-surf p-4">
      <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="font-semibold">{{ workspace.workers.length }} trabajadores</h2>
        <button type="button" class="ui-btn ui-btn-primary" :disabled="busy" @click="editWorker()"><Plus class="size-4" /> Trabajador</button></div>
      <form v-if="worker" class="space-y-3 rounded-md border border-line p-3" @submit.prevent="saveWorker">
        <fieldset class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3" :disabled="busy">
          <label v-for="[key, label] in workerText" :key="key" class="ui-label">{{ label }}
            <input v-model.trim="worker[key]" class="w-full" :required="key !== 'second_surname'"
              :readonly="worker.expected_revision > 0 && (key === 'code' || key === 'dni')" :pattern="key === 'code' ? workerCodePattern : key === 'dni' ? '[0-9]{8}' : undefined"
              :maxlength="key === 'code' ? 20 : key === 'dni' ? 8 : undefined"
              :title="key === 'code' ? 'Usa hasta 20 caracteres: letras mayúsculas, números y guiones. Empieza con una letra o un número.' : undefined">
          </label>
          <label class="ui-label">Fecha de ingreso<input v-model="worker.hired_on" type="date" class="w-full" required></label>
          <label class="ui-label">Fecha de cese<input v-model="worker.ended_on" type="date" class="w-full"></label>
          <label class="ui-label">Vigente desde<input v-model="worker.effective_month" type="month" class="w-full" required></label>
          <label class="ui-label">Remuneración mensual<input v-model.trim="worker.salary" class="w-full" inputmode="decimal" required></label>
          <label class="ui-label">Horas de jornada diaria<input v-model.number="worker.daily_hours" type="number" min="4" max="8" class="w-full" required></label>
          <label class="ui-label">Días vacacionales reconocidos acumulados<input v-model.number="worker.vacation_granted" type="number" min="0" max="1000" class="w-full" required></label>
          <div><label for="payroll-pension" class="ui-label">Pensión</label><UiSelect id="payroll-pension" v-model="worker.pension" :options="pensionOptions" /></div>
          <label class="space-x-2 text-sm">
            <input v-model="worker.family_allowance" type="checkbox"> Derecho a asignación familiar acreditado
            <span class="block text-xs text-muted">La planilla agrega este concepto al sueldo de la ficha.</span>
          </label>
          <label class="flex items-center gap-2 text-sm"><input v-model="worker.night_shift" type="checkbox"> Jornada completa exclusivamente nocturna</label>
          <template v-if="worker.pension === 'AFP'">
            <div><label for="payroll-afp" class="ui-label">AFP</label><UiSelect id="payroll-afp" :model-value="worker.afp ?? ''" :options="afpOptions" @update:model-value="worker.afp = $event" /></div>
            <div>
              <label for="payroll-afp-commission" class="ui-label">Esquema de comisión AFP</label>
              <UiSelect id="payroll-afp-commission" :model-value="worker.afp_commission ?? ''" placeholder="Seleccionar esquema" :options="afpCommissionOptions"
                @update:model-value="worker.afp_commission = $event || null" />
            </div>
            <label class="ui-label">CUSPP
              <input v-model.trim="worker.cuspp" minlength="12" maxlength="12" pattern="[A-Z0-9]{12}"
                class="w-full" aria-describedby="payroll-cuspp-hint" required>
              <span id="payroll-cuspp-hint" class="text-xs text-muted">12 caracteres alfanuméricos en mayúsculas.</span>
            </label>
          </template>
          <label class="ui-label sm:col-span-2">Sustento de la ficha<input v-model.trim="worker.reference" class="w-full" required maxlength="500"></label>
        </fieldset>
        <button class="ui-btn ui-btn-primary" :disabled="busy" type="submit">Guardar ficha</button>
        <button class="ui-btn ui-btn-ghost" type="button" @click="worker = undefined">Cancelar</button>
      </form>
      <p v-if="!workspace.workers.length" class="text-sm text-muted">Registre el primer trabajador para preparar la asistencia.</p>
      <ul v-else class="divide-y divide-line">
        <li v-for="entry in workspace.workers" :key="entry.reference" class="flex flex-wrap items-center justify-between gap-2 py-3">
          <div><p class="font-medium">{{ entry.data.given_names }} {{ entry.data.surname }} {{ entry.data.second_surname }}</p>
            <p class="text-xs text-muted">{{ entry.reference }} · {{ entry.data.position }} · {{ entry.data.ended_on ? 'Cese ' + formatAccountingDate(entry.data.ended_on) : 'Ingreso ' + formatAccountingDate(entry.data.hired_on) }}</p></div>
          <button type="button" class="ui-btn ui-btn-ghost ui-btn-sm" @click="editWorker(entry)">Editar ficha</button>
        </li>
      </ul>
    </section>

    <section v-if="workspace && section === 'payroll-attendance'" class="space-y-3 rounded-lg border border-line bg-surf p-4">
      <h2 class="font-semibold">Cierre de asistencia · {{ workspace.attendance.length }} / {{ workspace.eligible.length }}</h2>
      <p class="text-sm text-muted">Registre los días equivalentes de pago y los minutos trabajados según el control diario. Incluya los días de descanso remunerado que correspondan.</p>
      <div class="max-w-sm"><UiSelect aria-label="Trabajador vigente" :model-value="selectedAttendance" :options="currentWorkers" @update:model-value="selectAttendance" /></div>
      <details v-if="attendance" class="space-y-2 rounded-md border border-line p-3">
        <summary class="cursor-pointer font-medium">Control diario · {{ workerDays.length }} días registrados</summary>
        <form class="space-y-2" @submit.prevent="saveDay">
          <fieldset :disabled="busy || !!monthly?.data.approved" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            <label class="ui-label">Fecha<input v-model="day.date" type="date" class="w-full" :readonly="day.expected_revision > 0" required></label>
            <div><label for="payroll-day-status" class="ui-label">Estado</label><UiSelect id="payroll-day-status" v-model="day.status" :options="dayStatus" /></div>
            <label class="ui-label">Minutos ordinarios<input v-model.number="day.ordinary" type="number" min="0" max="480" class="w-full" required></label>
            <label class="ui-label">Minutos extra<input v-model.number="day.extra" type="number" min="0" max="960" class="w-full" required></label>
            <label class="ui-label sm:col-span-2">Sustento<input v-model.trim="day.reference" class="w-full" maxlength="500" required></label>
          </fieldset>
          <button class="ui-btn ui-btn-primary ui-btn-sm" type="submit" :disabled="busy || !!monthly?.data.approved">Guardar día</button>
          <button class="ui-btn ui-btn-ghost ui-btn-sm" type="button" @click="editDay()">Nuevo día</button>
        </form>
        <ul class="divide-y divide-line">
          <li v-for="entry in workerDays" :key="entry.reference" class="flex flex-wrap items-center justify-between gap-2 py-1 text-sm">
            <span>{{ formatAccountingDate(entry.data.date) }} · {{ dayStatus.find(option => option.value === entry.data.status)?.label }} · {{ entry.data.ordinary }} min + {{ entry.data.extra }} extra</span>
            <button type="button" class="ui-btn ui-btn-ghost ui-btn-sm" @click="editDay(entry)">Ver día</button>
          </li>
        </ul>
        <button v-if="workerDays.length" type="button" class="ui-btn ui-btn-secondary ui-btn-sm" :disabled="busy || !!monthly?.data.approved" @click="bringMinutes">Aplicar minutos al cierre</button>
      </details>
      <form v-if="attendance" class="space-y-3" @submit.prevent="saveAttendance">
        <fieldset class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3" :disabled="busy || !!monthly?.data.approved">
          <label v-for="[key,label,max] in minutesFields" :key="key" class="ui-label">{{ label }}<input v-model.number="attendance[key]" class="w-full" type="number" min="0" :max="max" required></label>
          <label v-for="[key,label] in extraFields" :key="key" class="ui-label">{{ label }}<input v-model.trim="attendance[key]" class="w-full" inputmode="decimal" required></label>
          <template v-if="attendance.afp_rates">
            <label v-for="[key,label] in afpFields" :key="key" class="ui-label">{{ label }}<input v-model.trim="attendance.afp_rates[key]" class="w-full"
              :readonly="!!workspace.parameters?.afp || (key === 'commission' && attendanceCommissionOnBalance)" :required="attendance.paid_days > 0"></label>
          </template>
          <label class="ui-label sm:col-span-2">Referencia de asistencia y conceptos validados<input v-model.trim="attendance.reference" class="w-full" required maxlength="500"></label>
        </fieldset>
        <fieldset class="space-y-2 rounded-md border border-line p-3" :disabled="busy || !!monthly?.data.approved">
          <legend class="px-1 text-sm font-semibold">Provisiones del mes</legend>
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" :checked="!!attendance.provisions" @change="toggleProvisions"> Estimar con bases y tiempo computables validados
          </label>
          <div v-if="attendance.provisions" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            <label v-for="[key, label] in provisionFields" :key="key" class="ui-label">{{ label }}
              <input v-model.trim="attendance.provisions[key]" class="w-full" inputmode="decimal" required>
            </label>
            <label class="ui-label">Días equivalentes computables CTS
              <input v-model.number="attendance.provisions.cts_days" type="number" min="0" max="30" required>
            </label>
            <label class="ui-label">Días equivalentes computables de vacaciones
              <input v-model.number="attendance.provisions.vacation_days" type="number" min="0" max="30" required>
            </label>
            <div>
              <label for="payroll-provision-coverage" class="ui-label">Cobertura para bonificación</label>
              <UiSelect id="payroll-provision-coverage" v-model="attendance.provisions.coverage" :options="coverageOptions"
                :disabled="busy || !!monthly?.data.approved" />
            </div>
            <label class="flex items-center gap-2 text-sm">
              <input v-model="attendance.provisions.gratification_month" type="checkbox"> Mes completo computable para gratificación
            </label>
            <label class="ui-label sm:col-span-2">Sustento de las bases y días computables
              <input v-model.trim="attendance.provisions.reference" class="w-full" maxlength="500" required>
            </label>
          </div>
        </fieldset>
        <button type="submit" class="ui-btn ui-btn-primary" :disabled="busy || !!monthly?.data.approved">Guardar cierre de asistencia</button>
      </form>
    </section>

    <PayrollParametersView v-if="workspace && section === 'payroll-parameters'"
      :parameters="workspace.parameters" :month="month"
      :load-history="loadParameterHistory" :save-parameter="saveParameter" />

    <PayrollMonthlyView
      v-if="workspace && section === 'payroll-monthly'"
      v-model:minimum="minimum"
      v-model:source="source"
      :workspace="workspace"
      :busy="busy"
      @calculate="calculate"
      @approve="approve"
      @download="download"
      @journal="monthly?.data.journal && emit('journal', monthly.data.journal)"
      @workers="emit('navigate', 'payroll-workers')"
      @attendance="openAttendance"
    />

    <section v-if="workspace && section === 'payroll-benefits'" class="space-y-3 rounded-lg border border-line bg-surf p-4">
      <form class="space-y-3" @submit.prevent="saveBenefit">
        <fieldset :disabled="busy" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          <div><label class="ui-label" for="benefit-worker">Trabajador</label><UiSelect id="benefit-worker" :model-value="benefit.worker" :options="currentWorkers" @update:model-value="selectBenefit($event, benefit.kind)" /></div>
          <div><label class="ui-label" for="benefit-kind">Beneficio</label><UiSelect id="benefit-kind" :model-value="benefit.kind" :options="kindOptions" @update:model-value="selectBenefit(benefit.worker, $event)" /></div>
          <label class="ui-label">Remuneración computable validada<input v-model.trim="benefit.base" class="w-full" inputmode="decimal" required></label>
          <label class="ui-label">Meses computables<input v-model.number="benefit.months" class="w-full" type="number" min="0" max="6" required></label>
          <label class="ui-label">{{ benefit.kind === 'CTS' ? 'Días computables adicionales' : 'Días no computables dentro de esos meses' }}<input v-model.number="benefit.days" class="w-full" type="number" min="0" :max="benefit.kind === 'CTS' ? 29 : benefit.months * 30" required></label>
          <label v-if="benefit.kind === 'CTS'" class="ui-label">Gratificación previa computable<input v-model.trim="benefit.previous_gratification" class="w-full" inputmode="decimal" required></label>
          <template v-else>
            <div><label class="ui-label" for="benefit-health">Cobertura</label><UiSelect id="benefit-health" v-model="benefit.health" :options="[{value:'ESSALUD',label:'EsSalud'},{value:'EPS',label:'EPS'}]" /></div>
            <label class="ui-label">Retención de quinta validada<input v-model.trim="benefit.withholding" class="w-full" inputmode="decimal" required></label>
          </template>
          <label class="ui-label">Fecha contable<input v-model="benefit.posting_date" class="w-full" type="date" required></label>
          <label class="ui-label">Sustento del cálculo<input v-model.trim="benefit.reference" class="w-full" required maxlength="500"></label>
        </fieldset>
        <button type="submit" class="ui-btn ui-btn-primary" :disabled="busy || !benefit.worker">Calcular y guardar beneficio</button>
      </form>
      <ul class="divide-y divide-line">
        <li v-for="{ reference, data } in workspace.benefits" :key="reference" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
          <span>{{ data.worker }} · {{ data.kind === 'CTS' ? 'CTS' : 'Gratificación' }} · Neto {{ money(data.result.net) }}</span>
          <button v-if="data.journal" type="button" class="ui-btn ui-btn-ghost ui-btn-sm" @click="emit('journal', data.journal)">Preparar asiento</button>
        </li>
      </ul>
    </section>
    <section v-if="workspace && section === 'payroll-vacations'" class="space-y-3 rounded-lg border border-line bg-surf p-4">
      <form class="space-y-3" @submit.prevent="saveVacation">
        <fieldset :disabled="busy" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
          <div><label class="ui-label" for="vacation-worker">Trabajador</label><UiSelect id="vacation-worker" v-model="vacation.worker" :options="workforce" /></div>
          <label class="ui-label">Inicio<input v-model="vacation.from" type="date" class="w-full" required></label>
          <label class="ui-label">Fin<input v-model="vacation.to" type="date" class="w-full" required></label>
          <label class="ui-label sm:col-span-2">Acuerdo o referencia<input v-model.trim="vacation.reference" class="w-full" required maxlength="500"></label>
        </fieldset>
        <p v-if="vacation.worker" class="text-sm text-muted">Saldo reconocido disponible · {{ vacationBalance(workspace.workers.find(entry => entry.reference === vacation.worker)!.data) }} días</p>
        <button type="submit" class="ui-btn ui-btn-primary" :disabled="busy || !vacation.worker">Registrar descanso</button>
      </form>
      <ul class="divide-y divide-line">
        <li v-for="{ reference, data } in workspace.vacations" :key="reference" class="py-2 text-sm">{{ data.worker }} · {{ formatAccountingDate(data.from) }} a {{ formatAccountingDate(data.to) }} · {{ data.days }} días</li>
      </ul>
    </section>
  </div>
</template>
