<script setup lang="ts">
import { computed, ref } from 'vue';
import { Bell, ClipboardList, Plus, Trash2 } from 'lucide-vue-next';
import UiSelect from '@/shared/ui/UiSelect.vue';
import { AccountingApiClient, ApiHttpError } from '../api/httpClient';
import type { TaxJournalDraft } from '../types/annualTaxClose.types';
import type { CompanyId } from '../types/accountingAccount.types';
import type { DepreciationPaper, MonthlyPaper, MonthlyProfile, MonthlyTask, MonthlyWork, PayrollPaper, PayrollRow } from '../types/monthlyWork.types';
import { formatAccountingDate } from '../utils/journalPresentation';
import { formatExactLocalAmount } from '../utils/formatExactLocalAmount';

const props = defineProps<{
  activeCompanyId: CompanyId;
  getAccessToken: () => string | null;
  initialMonth: string;
  canEdit: boolean;
  canConfirm: boolean;
}>();
const emit = defineEmits<{ (e: 'updated'): void; (e: 'calendar'): void; (e: 'assets'): void; (e: 'prepare-journal', draft: TaxJournalDraft): void }>();
const api = new AccountingApiClient('', {
  getActiveCompanyId: () => props.activeCompanyId, getAccessToken: props.getAccessToken,
});
const month = ref(props.initialMonth);
const board = ref<MonthlyWork>();
const profile = ref<Record<keyof MonthlyProfile, string>>();
const busy = ref(false);
const error = ref('');
const saved = ref('');
const showProfile = ref(false);
const taskChange = ref<MonthlyTask>();
const evidence = ref('');
const focus = ref<'agenda' | 'depreciation' | 'payroll'>('agenda');
const filter = ref('all');
const depreciation = ref<MonthlyPaper<DepreciationPaper>>();
const payroll = ref<MonthlyPaper<PayrollPaper>>();
const source = ref('');
const payrollRows = ref<PayrollRow[]>([]);
const profileFields = [
  ['prico', 'Principal contribuyente (PRICO)'],
  ['retention', 'Agente de retención del IGV'],
  ['perception', 'Agente de percepción del IGV'],
  ['workers', 'Tiene trabajadores en planilla'],
  ['assets', 'Tiene activos fijos sujetos a revisión'],
] as const;
const laborFields = [['afp', 'Trabajadores afiliados al SPP (AFP)'], ['cts', 'Depósito de CTS aplicable'],
  ['gratification', 'Gratificaciones legales aplicables']] as const;
const taskKinds = { internal: 'Control interno', tax: 'Tributo', books: 'Libro o registro', labor: 'Obligación laboral' };
const payrollFields = [
  ['worker', 'Código interno'], ['regular', 'Remuneración'], ['other', 'Otros ingresos'],
  ['pension', 'AFP u ONP'], ['income_tax', 'Renta de quinta'], ['other_deductions', 'Otros descuentos'],
  ['employer_contributions', 'Aportes del empleador'],
] as const;
const choice = [{ value: '', label: 'Por confirmar' }, { value: 'yes', label: 'Sí' }, { value: 'no', label: 'No' }];
const statusLabels = { pending: 'Pendiente', undated: 'Fecha por registrar', overdue: 'Vencida', upcoming: 'Próxima', done: 'Completada' };
const tasks = computed(() => board.value?.tasks.filter((task) => filter.value === 'all' || task.status === filter.value) ?? []);
const money = (value: string) => formatExactLocalAmount(value, 'PEN');

async function run(action: () => Promise<void>) {
  if (busy.value) return;
  busy.value = true;
  saved.value = '';
  error.value = '';
  try {
    await action();
  } catch (reason) {
    error.value = reason instanceof ApiHttpError ? Object.values(reason.validationErrors).flat()[0] ?? reason.message : 'No se pudo completar la operación.';
  } finally {
    busy.value = false;
  }
}

function apply(next: MonthlyWork) {
  board.value = next;
  const current = next.profile;
  profile.value = {
    prico: current ? current.prico ? 'yes' : 'no' : '',
    retention: current ? current.retention ? 'yes' : 'no' : '',
    perception: current ? current.perception ? 'yes' : 'no' : '',
    workers: current ? current.workers ? 'yes' : 'no' : '',
    assets: current ? current.assets ? 'yes' : 'no' : '',
    afp: current ? current.afp ? 'yes' : 'no' : '',
    cts: current ? current.cts ? 'yes' : 'no' : '',
    gratification: current ? current.gratification ? 'yes' : 'no' : '',
    internal_due: current?.internal_due ?? '',
    evidence: current?.evidence ?? '',
  };
}

function load(value: string) {
  void run(async () => {
    board.value = undefined;
    depreciation.value = undefined;
    payroll.value = undefined;
    taskChange.value = undefined;
    focus.value = 'agenda';
    month.value = value;
    const next = await api.fetchMonthlyWork(value);
    apply(next);
    showProfile.value = !next.profile;
  });
}

function confirm() {
  const input = profile.value;
  if (!input || !board.value) return;
  if ([...profileFields, ...(input.workers === 'yes' ? laborFields : [])].some(([key]) => !input[key])) {
    error.value = 'Confirme todas las condiciones de la empresa.';
    return;
  }
  const values: MonthlyProfile = {
    prico: input.prico === 'yes', retention: input.retention === 'yes', perception: input.perception === 'yes',
    afp: input.workers === 'yes' && input.afp === 'yes',
    cts: input.workers === 'yes' && input.cts === 'yes',
    gratification: input.workers === 'yes' && input.gratification === 'yes',
    workers: input.workers === 'yes', assets: input.assets === 'yes', internal_due: input.internal_due, evidence: input.evidence,
  };
  const revision = board.value.revision;
  void run(async () => {
    apply(await api.confirmMonthlyWork(month.value, values, revision));
    showProfile.value = false;
    saved.value = 'Periodo confirmado.';
    emit('updated');
  });
}

function updateTask() {
  const task = taskChange.value;
  if (!task || !board.value) return;
  void run(async () => {
    board.value = await api.saveMonthlyTask(month.value, task.code, task.status !== 'done', evidence.value, task.revision);
    taskChange.value = undefined;
    saved.value = 'Se guardó el estado de la tarea.';
    emit('updated');
  });
}

function openPaper(value: 'depreciation' | 'payroll') {
  void run(async () => {
    if (value === 'depreciation') {
      depreciation.value = await api.fetchMonthlyPaper<DepreciationPaper>(month.value, value);
    } else {
      payroll.value = await api.fetchMonthlyPaper<PayrollPaper>(month.value, value);
      source.value = payroll.value.data?.source ?? '';
      payrollRows.value = payroll.value.data?.rows.map((row) => ({
        worker: row.worker, regular: row.regular, other: row.other, pension: row.pension, income_tax: row.income_tax,
        other_deductions: row.other_deductions, employer_contributions: row.employer_contributions,
      })) ?? [];
    }
    focus.value = value;
  });
}

function generate() {
  if (!depreciation.value) return;
  const revision = depreciation.value.revision;
  void run(async () => {
    depreciation.value = await api.generateMonthlyDepreciation<DepreciationPaper>(month.value, revision);
    board.value = await api.fetchMonthlyWork(month.value);
    saved.value = 'Se generó el papel de trabajo de depreciación.';
    emit('updated');
  });
}

function prepareDepreciationJournal() {
  const amount = depreciation.value?.data?.totals.book;
  if (!amount || amount === '0.00') return;
  const [year, periodMonth] = month.value.split('-').map(Number);
  const date = new Date(Date.UTC(year!, periodMonth!, 0)).toISOString().slice(0, 10);
  emit('prepare-journal', {
    date, type: 'ADJUSTMENT', description: `Depreciación contable ${month.value} · Revisión ${depreciation.value?.revision}`,
    lines: [{ account: '68', debit: amount, credit: '0.00' }, { account: '39', debit: '0.00', credit: amount }],
  });
}

function addWorker() {
  payrollRows.value.push({
    worker: '', regular: '0', other: '0', pension: '0', income_tax: '0', other_deductions: '0', employer_contributions: '0',
  });
}

function savePayroll() {
  if (!payroll.value) return;
  const revision = payroll.value.revision;
  void run(async () => {
    payroll.value = await api.saveMonthlyPayroll<PayrollPaper>(month.value, revision, payrollRows.value, source.value);
    board.value = await api.fetchMonthlyWork(month.value);
    saved.value = 'Se guardó la planilla del periodo.';
    emit('updated');
  });
}

load(month.value);
</script>

<template>
  <div class="space-y-4">
    <header class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-xl font-bold">Agenda contable mensual</h1>
        <p class="text-sm text-muted">Obligaciones, papeles de trabajo y seguimiento del cierre.</p>
      </div>
      <label class="ui-label">Periodo contable
        <input :value="month" type="month" min="2025-01" :disabled="busy" @change="load(($event.target as HTMLInputElement).value || month)">
      </label>
    </header>

    <p v-if="busy" role="status" class="text-sm text-muted">Consultando el periodo…</p>
    <div v-if="error" class="flex flex-wrap items-center gap-2 text-sm text-neg" role="alert">
      <p>{{ error }}</p>
      <button v-if="!board" type="button" class="ui-btn ui-btn-secondary" @click="load(month)">Reintentar</button>
    </div>
    <p v-if="saved" role="status" class="text-sm text-pos">{{ saved }}</p>

    <template v-if="board && profile">
      <section v-if="!board.revision || showProfile" class="space-y-3 rounded-lg border border-line bg-surf p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="font-semibold">Confirmar obligaciones y fechas</h2>
          <button type="button" class="ui-btn ui-btn-secondary" @click="emit('calendar')">Vencimientos SUNAT</button>
        </div>
        <p v-if="!board.revision" class="text-sm text-warn">El Diario, las compras y las ventas requieren que este periodo esté confirmado.</p>
        <form v-if="canConfirm" class="space-y-3" @submit.prevent="confirm">
          <fieldset class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" :disabled="busy">
            <div v-for="[key, label] in [...profileFields, ...(profile.workers === 'yes' ? laborFields : [])]" :key="key">
              <label :for="'monthly-' + key" class="ui-label">{{ label }}</label>
              <UiSelect :id="'monthly-' + key" v-model="profile[key]" :options="choice" :disabled="busy" />
            </div>
            <label class="ui-label">Fecha interna de cierre
              <input v-model="profile.internal_due" type="date" required class="mt-1 w-full">
            </label>
            <label class="ui-label sm:col-span-2 lg:col-span-3">Sustento de la verificación del RUC, padrones y obligaciones
              <input v-model.trim="profile.evidence" required maxlength="500" class="mt-1 w-full">
            </label>
          </fieldset>
          <button type="submit" class="ui-btn ui-btn-primary" :disabled="busy">Confirmar periodo</button>
        </form>
        <div class="flex flex-wrap gap-3 text-xs">
          <a href="https://www.sunat.gob.pe/padronesnotificaciones/" target="_blank" rel="noopener noreferrer" class="text-brand underline">Padrones y notificaciones SUNAT</a>
          <a href="https://www.sunat.gob.pe/orientacion/regimenEspIGV/agentesRetencion/padronAgentesRetencion.htm" target="_blank" rel="noopener noreferrer" class="text-brand underline">Padrón de retención</a>
          <a href="https://ww3.sunat.gob.pe/descarga/AgentRet/AgenPerc1.html" target="_blank" rel="noopener noreferrer" class="text-brand underline">Padrón de percepción</a>
        </div>
        <p v-if="!canConfirm" class="text-sm text-muted">Solicite a un administrador o contador jefe que confirme las obligaciones.</p>
      </section>

      <div v-if="board.revision" class="flex flex-wrap items-center gap-2">
        <button v-if="canConfirm && !showProfile" type="button" class="ui-btn ui-btn-secondary" @click="showProfile = true">Revisar configuración</button>
        <button v-if="board.profile?.assets" type="button" class="ui-btn ui-btn-secondary" :disabled="busy" @click="openPaper('depreciation')">Depreciación mensual</button>
        <button v-if="canConfirm && board.profile?.workers" type="button" class="ui-btn ui-btn-secondary" :disabled="busy" @click="openPaper('payroll')">Planilla mensual</button>
      </div>

      <dl class="grid grid-cols-2 gap-2 lg:grid-cols-4">
        <div class="rounded-lg border border-line bg-surf p-3"><dt class="text-sm text-muted">Completadas</dt><dd class="text-lg font-semibold tabular-nums">{{ board.kpi.done }} / {{ board.kpi.total }}</dd></div>
        <div class="rounded-lg border border-line bg-surf p-3"><dt class="text-sm text-muted">Vencidas</dt><dd class="text-lg font-semibold tabular-nums text-neg">{{ board.kpi.overdue }}</dd></div>
        <div class="rounded-lg border border-line bg-surf p-3"><dt class="text-sm text-muted">Próximos 7 días</dt><dd class="text-lg font-semibold tabular-nums text-warn">{{ board.kpi.upcoming }}</dd></div>
        <div class="rounded-lg border border-line bg-surf p-3"><dt class="text-sm text-muted">Fechas por registrar</dt><dd class="text-lg font-semibold tabular-nums">{{ board.kpi.undated }}</dd></div>
        <div v-if="board.workpapers.depreciation" class="rounded-lg border border-line bg-surf p-3"><dt class="text-sm text-muted">Depreciación contable</dt><dd class="font-semibold tabular-nums">{{ money(board.workpapers.depreciation.book) }}</dd></div>
        <div v-if="board.workpapers.depreciation" class="rounded-lg border border-line bg-surf p-3">
          <dt class="text-sm text-muted">Depreciación tributaria</dt>
          <dd class="font-semibold tabular-nums">{{ money(board.workpapers.depreciation.tax) }}</dd>
          <dd v-if="board.workpapers.depreciation.tax_pending" class="text-xs text-warn">{{ board.workpapers.depreciation.tax_pending }} fichas por confirmar</dd>
        </div>
        <div v-if="board.workpapers.payroll" class="rounded-lg border border-line bg-surf p-3"><dt class="text-sm text-muted">Costo de planilla</dt><dd class="font-semibold tabular-nums">{{ money(board.workpapers.payroll.employer_cost) }}</dd></div>
      </dl>

      <section v-if="focus === 'depreciation' && depreciation" class="space-y-3 rounded-lg border border-line bg-surf p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="font-semibold">Depreciación mensual</h2>
          <div class="flex flex-wrap gap-2">
            <button type="button" class="ui-btn ui-btn-secondary" @click="emit('assets')">Fichas de activos</button>
            <button type="button" class="ui-btn ui-btn-ghost" @click="focus = 'agenda'">Cerrar</button>
          </div>
        </div>
        <p class="text-sm text-muted">Línea recta en PEN. Las fichas requieren datos fiscales del ejercicio para calcular la columna tributaria.</p>
        <button v-if="canEdit" type="button" class="ui-btn ui-btn-primary" :disabled="busy" @click="generate">{{ depreciation.revision ? 'Generar nueva revisión' : 'Generar depreciación' }}</button>
        <button v-if="canEdit && depreciation.data && depreciation.data.totals.book !== '0.00'" type="button"
          class="ui-btn ui-btn-secondary" :disabled="busy" @click="prepareDepreciationJournal">Preparar asiento en el Diario</button>
        <div v-if="depreciation.data" class="overflow-x-auto">
          <table class="w-full min-w-[34rem] text-left text-sm">
            <thead><tr class="border-b border-line"><th class="p-2">Activo</th><th class="p-2 text-right">Contable</th><th class="p-2 text-right">Tributaria</th><th class="p-2 text-right">Valor en libros</th></tr></thead>
            <tbody class="divide-y divide-line">
              <tr v-for="asset in depreciation.data.rows" :key="asset.reference">
                <th scope="row" class="p-2 font-medium">{{ asset.label }} <span class="block text-xs text-muted">{{ asset.reference }} · Rev. {{ asset.source_revision }}</span></th>
                <td class="p-2 text-right tabular-nums">{{ money(asset.book) }}</td>
                <td class="p-2 text-right tabular-nums">{{ asset.tax === null ? 'Por confirmar' : money(asset.tax) }}</td>
                <td class="p-2 text-right tabular-nums">{{ money(asset.carrying_amount) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="focus === 'payroll' && payroll" class="space-y-3 rounded-lg border border-line bg-surf p-4">
        <div class="flex items-center justify-between gap-2">
          <h2 class="font-semibold">Planilla mensual de trabajadores</h2>
          <button type="button" class="ui-btn ui-btn-ghost" @click="focus = 'agenda'">Cerrar</button>
        </div>
        <p class="text-sm text-muted">Registre en PEN los importes validados de ingresos, descuentos y aportes. La planilla calcula bruto, neto y costo por trabajador.</p>
        <form class="space-y-3" @submit.prevent="savePayroll">
          <fieldset :disabled="busy" class="space-y-3">
            <label class="ui-label">Referencia de las incidencias y cálculos del mes
              <input v-model.trim="source" maxlength="250" required class="mt-1 w-full">
            </label>
            <div v-for="(row, index) in payrollRows" :key="index" class="grid gap-2 rounded-lg border border-line p-3 sm:grid-cols-2 xl:grid-cols-4">
              <label v-for="[key, label] in payrollFields" :key="key" class="ui-label">{{ label }}
                <input v-model.trim="row[key]" :inputmode="key === 'worker' ? 'text' : 'decimal'" :maxlength="key === 'worker' ? 80 : 13" required class="mt-1 w-full">
              </label>
              <button type="button" class="ui-btn ui-btn-ghost self-end justify-self-start" @click="payrollRows.splice(index, 1)"><Trash2 class="size-4" /> Quitar fila</button>
            </div>
            <div class="flex flex-wrap gap-2">
              <button type="button" class="ui-btn ui-btn-secondary" @click="addWorker"><Plus class="size-4" /> Agregar trabajador</button>
              <button type="submit" class="ui-btn ui-btn-primary" :disabled="!payrollRows.length">Guardar planilla</button>
            </div>
          </fieldset>
        </form>
        <div v-if="payroll.data" class="space-y-2 text-sm">
          <p class="font-semibold">Revisión {{ payroll.revision }} · Neto {{ money(payroll.data.totals.net) }} · Costo {{ money(payroll.data.totals.employer_cost) }}</p>
          <ul class="divide-y divide-line">
            <li v-for="row in payroll.data.rows" :key="row.worker" class="flex flex-wrap justify-between gap-2 py-2"><span>{{ row.worker }}</span><span class="tabular-nums">Bruto {{ money(row.gross) }} · Neto {{ money(row.net) }}</span></li>
          </ul>
        </div>
      </section>

      <section class="space-y-3 rounded-lg border border-line bg-surf p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="flex items-center gap-2 font-semibold"><ClipboardList class="size-4" /> Tareas del periodo</h2>
          <div class="w-44"><UiSelect v-model="filter" aria-label="Estado de las tareas" :options="[{ value: 'all', label: 'Todos los estados' }, ...Object.entries(statusLabels).map(([value, label]) => ({ value, label }))]" /></div>
        </div>
        <form v-if="taskChange" class="space-y-2 rounded-lg border border-line p-3" @submit.prevent="updateTask">
          <p class="font-medium">{{ taskChange.label }}</p>
          <label class="ui-label">{{ taskChange.status === 'done' ? 'Motivo de reapertura' : 'Constancia o evidencia de la tarea' }}
            <input v-model.trim="evidence" required maxlength="500" class="mt-1 w-full">
          </label>
          <div class="flex gap-2">
            <button type="submit" class="ui-btn ui-btn-primary" :disabled="busy">{{ taskChange.status === 'done' ? 'Reabrir' : 'Completar' }}</button>
            <button type="button" class="ui-btn ui-btn-ghost" @click="taskChange = undefined">Cancelar</button>
          </div>
        </form>
        <p v-if="!tasks.length" class="text-sm text-muted">Sin tareas para este filtro.</p>
        <ul v-else class="divide-y divide-line">
          <li v-for="task in tasks" :key="task.code" class="flex flex-wrap items-center justify-between gap-2 py-3">
            <div class="min-w-0">
              <p class="font-medium">{{ task.label }}</p>
              <p class="text-xs text-muted">{{ task.due ? formatAccountingDate(task.due) : 'Fecha por registrar' }} · {{ taskKinds[task.kind] }}</p>
              <a v-if="task.source" :href="task.source" target="_blank" rel="noopener noreferrer" class="text-xs text-brand underline">Fuente</a>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-xs">
              <span class="flex items-center gap-1 font-semibold" :class="{ 'text-neg': task.status === 'overdue', 'text-warn': task.status === 'upcoming', 'text-pos': task.status === 'done' }">
                <Bell v-if="task.status === 'upcoming' || task.status === 'overdue'" class="size-3" aria-hidden="true" />{{ statusLabels[task.status] }}
              </span>
              <button v-if="canEdit && board.revision" type="button" class="ui-btn ui-btn-ghost ui-btn-sm" :disabled="busy"
                @click="taskChange = task; evidence = ''">{{ task.status === 'done' ? 'Reabrir' : 'Registrar cumplimiento' }}</button>
            </div>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>
