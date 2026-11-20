<script setup lang="ts">
import { computed, ref } from 'vue';
import { Calculator, ChevronDown, Download, FileCheck, Settings2, UsersRound } from 'lucide-vue-next';
import { formatExactLocalAmount } from '@/modules/accounting/utils/formatExactLocalAmount';
import type { PayrollRow, PayrollWorkspace } from './payroll.types';

const props = defineProps<{ workspace: PayrollWorkspace; busy: boolean }>();
const emit = defineEmits<{
  calculate: [];
  approve: [];
  journal: [];
  download: [format: 'jor' | 'afp'];
  workers: [];
  attendance: [worker: string];
}>();
const minimum = defineModel<string>('minimum', { required: true });
const source = defineModel<string>('source', { required: true });

const monthly = computed(() => props.workspace.payroll);
const approved = computed(() => monthly.value?.data.approved ?? false);
const rows = computed(() => monthly.value?.data.rows ?? []);
const rowsByWorker = computed(() => new Map(rows.value.map((row) => [row.worker.code, row])));
const attendanceByWorker = computed(() => new Map(props.workspace.attendance.map((entry) => [entry.data.worker, entry])));
const pending = computed(() => new Map(
  (props.workspace.attendance_pending ?? props.workspace.eligible
    .filter(({ reference }) => !attendanceByWorker.value.has(reference))
    .map(({ reference }) => ({ worker: reference, message: 'Falta confirmar la asistencia.' })))
    .map(({ worker, message }) => [worker, message]),
));
const needsRecalculation = computed(() => !!monthly.value && !approved.value && (
  props.workspace.parameters_changed
  || (props.workspace.parameters?.minimum && Number(monthly.value.data.health_minimum) !== Number(props.workspace.parameters.minimum.amount))
  || pending.value.size > 0
  || rows.value.length !== props.workspace.eligible.length
  || props.workspace.eligible.some(({ reference, revision }) => {
    const row = rowsByWorker.value.get(reference);
    return row?.worker_revision !== revision || row?.attendance_revision !== attendanceByWorker.value.get(reference)?.revision;
  })
));
const roster = computed(() => approved.value
  ? rows.value.map((row) => ({ worker: row.worker, attendance: row.attendance, row }))
  : props.workspace.eligible.map(({ data: worker }) => ({
    worker,
    attendance: attendanceByWorker.value.get(worker.code)?.data,
    row: rowsByWorker.value.get(worker.code),
  })),
);
const state = computed(() => {
  if (approved.value) return { label: 'Aprobada', color: 'text-pos' };
  if (needsRecalculation.value) return { label: 'Requiere recálculo', color: 'text-warn' };
  if (monthly.value) return { label: 'Borrador calculado', color: 'text-brand' };
  if (!roster.value.length) return { label: 'Sin trabajadores vigentes', color: 'text-muted' };
  if (pending.value.size) return { label: 'Asistencia pendiente', color: 'text-warn' };
  return { label: 'Asistencia confirmada', color: 'text-brand' };
});
const indicators = [
  { key: 'gross', label: 'Total ingresos' },
  { key: 'net', label: 'Neto a pagar' },
  { key: 'essalud', label: 'Aporte EsSalud' },
  { key: 'employer_cost', label: 'Costo del empleador' },
] as const;

const openParameters = ref(false);
const selected = ref<string>();
const canCalculate = computed(() => roster.value.length > 0 && pending.value.size === 0 && !approved.value);

function money(value?: string) {
  return value === undefined ? '—' : formatExactLocalAmount(value, 'PEN').replace(/^PEN (.*,[0-9]{2})00$/, 'S/ $1');
}

function detail(row: PayrollRow) {
  return [
    { label: 'Ingresos', items: [
      ['Básico devengado', row.amounts.basic],
      ['Remuneración vacacional incluida', row.amounts.vacation_pay],
      ...(row.amounts.family_allowance ? [['Asignación familiar', row.amounts.family_allowance]] : []),
      ['Horas extra', row.amounts.overtime],
      ['Otros ingresos afectos', row.attendance.taxable_extra],
      ['Otros ingresos inafectos', row.attendance.nontaxable_extra],
      ['Total ingresos', row.amounts.gross],
    ] },
    { label: 'Descuentos del trabajador', items: [
      [row.worker.pension === 'AFP' ? 'AFP total' : 'ONP', row.amounts.pension],
      ...(row.amounts.afp_contribution ? [
        ['Aporte al fondo AFP', row.amounts.afp_contribution],
        ['Prima AFP', row.amounts.afp_insurance],
        ['Comisión AFP sobre remuneración', row.amounts.afp_commission],
      ] : []),
      ['Retención de quinta categoría registrada', row.amounts.income_tax],
      ['Otros descuentos', row.amounts.other_deductions],
      ['Neto a pagar', row.amounts.net],
    ] },
    { label: 'Aportes y costo del empleador', items: [
      ['EsSalud', row.amounts.essalud],
      ['Costo del empleador', row.amounts.employer_cost],
      ...(row.amounts.provision_total ? [
        ['Provisión de gratificación', row.amounts.provision_gratification],
        ['Provisión de bonificación extraordinaria', row.amounts.provision_gratification_bonus],
        ['Provisión de CTS', row.amounts.provision_cts],
        ['Provisión de vacaciones', row.amounts.provision_vacation],
        ['Total estimado de provisiones', row.amounts.provision_total],
      ] : []),
    ] },
  ];
}

function calculate() {
  if (canCalculate.value) emit('calculate');
}
</script>

<template>
  <div class="space-y-4">
    <section aria-label="Resumen de la planilla" class="overflow-hidden rounded-lg border border-line bg-surf">
      <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-4 py-3">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
          <span class="rounded-md bg-soft px-2 py-1 text-xs font-semibold" :class="state.color">{{ state.label }}</span>
          <span class="text-sm text-muted">{{ roster.length }} {{ roster.length === 1 ? 'trabajador' : 'trabajadores' }}</span>
          <span v-if="monthly" class="text-xs text-muted">Revisión {{ monthly.revision }}</span>
        </div>
        <div v-if="roster.length" class="flex flex-wrap gap-2">
          <button v-if="!approved" type="button" class="ui-btn ui-btn-sm ui-btn-secondary"
            :aria-expanded="openParameters" aria-controls="payroll-calculation-parameters"
            @click="openParameters = !openParameters">
            <Settings2 class="size-4" aria-hidden="true" /> Parámetros
          </button>
          <button v-if="monthly && !approved && !needsRecalculation" type="button" class="ui-btn ui-btn-sm ui-btn-primary"
            :disabled="busy" @click="emit('approve')"><FileCheck class="size-4" aria-hidden="true" /> Aprobar planilla</button>
          <button v-else-if="canCalculate" type="button" class="ui-btn ui-btn-sm ui-btn-primary"
            :disabled="busy || openParameters" @click="openParameters = true">
            <Calculator class="size-4" aria-hidden="true" /> {{ monthly ? 'Recalcular planilla' : 'Calcular planilla' }}
          </button>
          <template v-if="approved">
            <button v-if="monthly?.data.journal" type="button" class="ui-btn ui-btn-sm ui-btn-primary" @click="emit('journal')">Preparar asiento</button>
            <button type="button" class="ui-btn ui-btn-sm ui-btn-secondary" :disabled="busy" @click="emit('download', 'jor')">
              <Download class="size-4" aria-hidden="true" /> Jornada PLAME
            </button>
            <button v-if="rows.some(({ worker }) => worker.pension === 'AFP')" type="button" class="ui-btn ui-btn-sm ui-btn-secondary"
              :disabled="busy" @click="emit('download', 'afp')"><Download class="size-4" aria-hidden="true" /> AFPnet TXT</button>
          </template>
        </div>
      </div>
      <dl class="grid grid-cols-2 gap-px bg-line lg:grid-cols-4">
        <div v-for="{ key, label } in indicators" :key="key" class="min-w-0 bg-surf px-3 py-3 sm:px-4">
          <dt class="text-xs text-muted sm:text-sm">{{ label }}</dt>
          <dd class="mt-0.5 break-words text-base font-semibold tabular-nums sm:text-lg" :class="{ 'text-brand': key === 'net' }">
            {{ money(monthly?.data.totals[key]) }}
          </dd>
        </div>
      </dl>
      <p v-if="monthly?.data.totals.provision_total" class="border-t border-line px-4 py-2 text-sm">
        Provisiones estimadas <strong class="tabular-nums">{{ money(monthly.data.totals.provision_total) }}</strong>
        <span class="text-muted"> · {{ rows.filter(row => row.amounts.provision_total).length }} de {{ rows.length }} trabajadores</span>
      </p>
    </section>

    <form v-if="openParameters && !approved" id="payroll-calculation-parameters" class="space-y-3 rounded-lg border border-line bg-surf p-4"
      @submit.prevent="calculate">
      <h2 class="text-sm font-semibold">Parámetros del cálculo</h2>
      <div class="grid gap-3 sm:grid-cols-[minmax(10rem,14rem)_minmax(0,1fr)]">
        <label class="ui-label">Base mínima EsSalud (S/)<input v-model.trim="minimum" class="w-full" inputmode="decimal" :readonly="!!workspace.parameters?.minimum" pattern="[0-9]{1,9}(\.[0-9]{1,2})?" required></label>
        <label class="ui-label">Sustento del devengue y de la base mínima<input v-model.trim="source" class="w-full" maxlength="500" required></label>
      </div>
      <button type="submit" class="ui-btn ui-btn-sm ui-btn-primary" :disabled="busy || !canCalculate">
        {{ monthly ? 'Recalcular planilla' : 'Calcular planilla' }}
      </button>
    </form>

    <p v-if="!approved && pending.size" role="status" class="rounded-lg border border-line bg-soft px-4 py-3 text-sm">
      <strong>{{ pending.size }} {{ pending.size === 1 ? 'trabajador con asistencia pendiente' : 'trabajadores con asistencia pendiente' }}.</strong>
      Revisa los pendientes en la nómina antes de calcular.
    </p>
    <p v-if="needsRecalculation" role="status" class="rounded-lg border border-line bg-soft px-4 py-3 text-sm">
      <template v-if="workspace.parameters_changed">Cambió un parámetro del periodo. </template>
      Los importes corresponden a la revisión {{ monthly?.revision }}. Vuelve a calcular antes de aprobar.
    </p>

    <details v-if="approved && monthly?.data.parameters" class="rounded-lg border border-line bg-surf px-4 py-3 text-sm">
      <summary class="cursor-pointer font-semibold">Parámetros de la planilla aprobada</summary>
      <dl class="mt-2 grid gap-2 text-muted sm:grid-cols-3">
        <div><dt>RMV aplicada</dt><dd>{{ money(monthly.data.parameters.minimum?.amount) }} · {{ monthly.data.parameters.minimum?.rule ?? 'Sin fuente registrada' }}</dd></div>
        <div><dt>UIT del ejercicio</dt><dd>{{ money(monthly.data.parameters.uit ?? undefined) }}</dd></div>
        <div><dt>Tasas AFP</dt><dd>{{ monthly.data.parameters.afp?.source ?? 'Tasas registradas en Asistencia' }}</dd></div>
      </dl>
    </details>

    <section aria-labelledby="payroll-roster-title" class="min-w-0 overflow-hidden rounded-lg border border-line bg-surf">
      <h2 id="payroll-roster-title" class="px-4 py-3 text-sm font-semibold">Nómina del periodo</h2>
      <div v-if="!roster.length" class="border-t border-line px-4 py-8 text-center">
        <UsersRound class="mx-auto mb-2 size-6 text-muted" aria-hidden="true" />
        <p class="font-semibold">No hay trabajadores vigentes en este periodo.</p>
        <p class="mt-1 text-sm text-muted">Registra un trabajador o revisa las fechas de su vínculo laboral.</p>
        <button type="button" class="ui-btn ui-btn-sm ui-btn-secondary mt-3" @click="emit('workers')">Ir a Trabajadores</button>
      </div>
      <div v-else class="overflow-x-auto" tabindex="0" role="region" aria-label="Importes de la nómina por trabajador">
        <table class="ui-table w-full">
          <thead><tr>
            <th scope="col">Trabajador</th>
            <th scope="col" class="hidden text-right md:table-cell">Días pagados</th>
            <th scope="col" class="hidden text-right lg:table-cell">Ingresos</th>
            <th scope="col" class="hidden text-right xl:table-cell">Pensión</th>
            <th scope="col" class="hidden text-right xl:table-cell">Quinta categoría</th>
            <th scope="col" class="hidden text-right xl:table-cell">Otros descuentos</th>
            <th scope="col" class="text-right">Neto a pagar</th>
            <th scope="col" class="hidden text-right lg:table-cell">Costo empleador</th>
          </tr></thead>
          <tbody>
            <template v-for="{ worker, attendance, row } in roster" :key="worker.code">
              <tr>
                <th scope="row" class="min-w-0 border-b border-line px-3 py-2 text-left font-normal">
                  <button v-if="row" type="button" class="flex items-center gap-1 text-left font-semibold hover:text-brand"
                    :aria-expanded="selected === worker.code" :aria-controls="`payroll-worker-${worker.code}`"
                    @click="selected = selected === worker.code ? undefined : worker.code">
                    {{ worker.given_names }} {{ worker.surname }} {{ worker.second_surname }}
                    <ChevronDown class="size-3.5 shrink-0" :class="{ 'rotate-180': selected === worker.code }" aria-hidden="true" />
                  </button>
                  <span v-else class="block font-semibold">{{ worker.given_names }} {{ worker.surname }} {{ worker.second_surname }}</span>
                  <span class="block text-xs text-muted">{{ worker.code }} · {{ worker.pension }}</span>
                  <template v-if="!approved && pending.has(worker.code)">
                    <p class="mt-1 text-xs text-warn">{{ pending.get(worker.code) }}</p>
                    <button type="button" class="mt-0.5 text-xs font-medium text-brand underline" @click="emit('attendance', worker.code)">Revisar asistencia</button>
                  </template>
                </th>
                <td class="hidden text-right tabular-nums md:table-cell">{{ (row ? row.attendance : attendance)?.paid_days ?? '—' }}</td>
                <td class="hidden whitespace-nowrap text-right tabular-nums lg:table-cell">{{ money(row?.amounts.gross) }}</td>
                <td class="hidden whitespace-nowrap text-right tabular-nums xl:table-cell">{{ money(row?.amounts.pension) }}</td>
                <td class="hidden whitespace-nowrap text-right tabular-nums xl:table-cell">{{ money(row?.amounts.income_tax) }}</td>
                <td class="hidden whitespace-nowrap text-right tabular-nums xl:table-cell">{{ money(row?.amounts.other_deductions) }}</td>
                <td class="whitespace-nowrap text-right font-semibold tabular-nums">{{ money(row?.amounts.net) }}</td>
                <td class="hidden whitespace-nowrap text-right tabular-nums lg:table-cell">{{ money(row?.amounts.employer_cost) }}</td>
              </tr>
              <tr v-if="row" v-show="selected === worker.code" :id="`payroll-worker-${worker.code}`">
                <td colspan="8" class="bg-soft">
                  <p class="mb-2 text-xs text-muted">Días pagados {{ row.attendance.paid_days }} · Días de vacaciones {{ row.attendance.vacation_paid_days }}</p>
                  <div class="grid gap-4 lg:grid-cols-3">
                    <section v-for="group in detail(row)" :key="group.label">
                      <h3 class="mb-1 text-xs font-semibold">{{ group.label }}</h3>
                      <dl class="space-y-1 text-xs">
                        <div v-for="[label, amount] in group.items" :key="label" class="flex justify-between gap-2">
                          <dt class="text-muted">{{ label }}</dt><dd class="shrink-0 tabular-nums">{{ money(amount) }}</dd>
                        </div>
                      </dl>
                    </section>
                  </div>
                </td>
              </tr>
            </template>
          </tbody>
          <tfoot v-if="monthly" class="border-t border-line bg-soft font-semibold tabular-nums">
            <tr>
              <th scope="row" class="px-3 py-2 text-left">Total de la revisión</th>
              <td class="hidden text-right md:table-cell"></td>
              <td class="hidden whitespace-nowrap px-3 py-2 text-right lg:table-cell">{{ money(monthly.data.totals.gross) }}</td>
              <td class="hidden whitespace-nowrap px-3 py-2 text-right xl:table-cell">{{ money(monthly.data.totals.pension) }}</td>
              <td class="hidden whitespace-nowrap px-3 py-2 text-right xl:table-cell">{{ money(monthly.data.totals.income_tax) }}</td>
              <td class="hidden whitespace-nowrap px-3 py-2 text-right xl:table-cell">{{ money(monthly.data.totals.other_deductions) }}</td>
              <td class="whitespace-nowrap px-3 py-2 text-right">{{ money(monthly.data.totals.net) }}</td>
              <td class="hidden whitespace-nowrap px-3 py-2 text-right lg:table-cell">{{ money(monthly.data.totals.employer_cost) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>
  </div>
</template>
