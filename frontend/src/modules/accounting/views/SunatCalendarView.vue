<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';
import { CalendarDays, Save, Plus, Trash2 } from 'lucide-vue-next';
import UiSelect from '@/shared/ui/UiSelect.vue';
import { AccountingApiClient, ApiHttpError } from '../api/httpClient';
import type { CompanyId } from '../types/accountingAccount.types';
import type { CalendarOverride, SunatCalendarProfile, SunatCalendarReport } from '../types/sunatCalendar.types';
import { peruAccountingDate, formatAccountingDate } from '../utils/journalPresentation';

const props = defineProps<{ activeCompanyId: CompanyId; getAccessToken: () => string | null; canEdit: boolean }>();
const client = new AccountingApiClient('', {
  getActiveCompanyId: () => props.activeCompanyId, getAccessToken: props.getAccessToken,
});
const today = peruAccountingDate();
const previousMonth = new Date(today.slice(0, 7) + '-01T00:00:00Z');
previousMonth.setUTCDate(0);
const years = Array.from({ length: Math.max(3, Number(today.slice(0, 4)) - 2024) }, (_, index) => 2025 + index)
  .reverse().map((value) => ({ value: String(value), label: String(value) }));
const year = ref(years.some((option) => option.value === String(previousMonth.getUTCFullYear()))
  ? String(previousMonth.getUTCFullYear()) : '2026');
const months = [
  { value: 'all', label: 'Todo el ejercicio' },
  ...Array.from({ length: 12 }, (_, i) => ({
    value: String(i+1).padStart(2, '0'),
    label: new Intl.DateTimeFormat('es-PE', { month: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(2026, i, 1))),
  })),
  { value: 'annual', label: 'Obligaciones anuales' },
];
const month = ref(String(previousMonth.getUTCMonth() + 1).padStart(2, '0'));
const scope = ref('all');
const bookChoices = [
  { value: 'auto', label: 'Según el perfil' }, { value: 'include', label: 'Incluir' }, { value: 'exclude', label: 'Excluir' },
];
const selectorFields = [
  ['income_band', 'Tramo aplicable a libros', [
    ['UNCONFIRMED', 'Por confirmar'], ['UP_TO_300', 'Hasta 300 UIT'], ['UP_TO_500', 'Más de 300 hasta 500 UIT'],
    ['UP_TO_1700', 'Más de 500 hasta 1 700 UIT'], ['OVER_1700', 'Más de 1 700 UIT'],
  ]],
  ['monthly_group', 'Columna del cronograma mensual', [['RUC', 'Según último dígito'], ['BC', 'Buen contribuyente o UESP']]],
  ['annual_schedule', 'Cronograma anual FV 710', [['UNCONFIRMED', 'Por confirmar'], ['GENERAL', 'General'], ['EXTENDED', 'Ampliado · Ley 31940']]],
  ['itan', 'Modalidad ITAN', [['NONE', 'Sin ITAN confirmado'], ['FULL', 'Al contado'], ['INSTALLMENTS', 'En cuotas']]],
] as const;
const report = ref<SunatCalendarReport>();
const profile = ref<SunatCalendarProfile>();
const editor = ref(false);
const overridesPanel = ref<HTMLElement>();
const busy = ref(false);
const error = ref('');
const dirty = computed(() => JSON.stringify(profile.value) !== JSON.stringify(report.value?.profile));
const period = computed(() => `${year.value}-${month.value}`);
const events = computed(() => report.value?.events.filter((row) =>
  (month.value === 'all' || (month.value === 'annual' ? row.period === year.value : row.period === period.value)) &&
  (scope.value === 'all' || row.type === scope.value)) ?? []);
const regime = computed(() => month.value === 'all' || month.value === 'annual' ? report.value?.regime : report.value?.regimes[Number(month.value)]);
const rce = computed(() => report.value?.events.find((event) => event.period === period.value && event.code === '8'));
const monthly = computed(() => report.value?.events.find((event) => event.period === period.value && event.code === 'MONTHLY'));

async function run(task: () => Promise<SunatCalendarReport>) {
  busy.value = true;
  error.value = '';
  try {
    report.value = await task();
    profile.value = structuredCopy(report.value.profile);
  } catch (reason) {
    error.value = reason instanceof ApiHttpError ? Object.values(reason.validationErrors).flat()[0] ?? reason.message : 'No se pudo consultar el calendario.';
  } finally {
    busy.value = false;
  }
}

function structuredCopy(value: SunatCalendarProfile): SunatCalendarProfile {
  return JSON.parse(JSON.stringify(value));
}

function selectYear(value: string) {
  if (busy.value || value === year.value) return;
  if (dirty.value && !window.confirm('¿Descartar los cambios sin guardar?')) return;
  year.value = value;
  editor.value = false;
  report.value = undefined;
  profile.value = undefined;
  void run(() => client.fetchSunatCalendar(value));
}

function setBook(code: string, choice: string) {
  if (!profile.value) return;
  profile.value.extra_books = profile.value.extra_books.filter((item) => item !== code);
  profile.value.excluded_books = profile.value.excluded_books.filter((item) => item !== code);
  if (choice === 'include') profile.value.extra_books.push(code);
  if (choice === 'exclude') profile.value.excluded_books.push(code);
}

function save() {
  if (!profile.value || !report.value) return;
  const settings = profile.value;
  const revision = report.value.revision;
  void run(() => client.saveSunatCalendar(year.value, settings, revision));
}

async function addOverride(event?: SunatCalendarReport['events'][number]) {
  if (!profile.value || !props.canEdit || busy.value) return;
  editor.value = true;
  const existing = profile.value.overrides.find((row) => event && row.code === event.code && row.period === event.period);
  if (!existing) {
    const row: CalendarOverride = {
      code: event?.code ?? '', label: event?.label ?? '', type: event?.type ?? 'tax',
      period: event?.period ?? (month.value === 'all' || month.value === 'annual' ? year.value : period.value),
      date: event?.date ?? '', reference: '',
    };
    profile.value.overrides.push(row);
  }
  if (event) {
    await nextTick();
    overridesPanel.value?.scrollIntoView({ block: 'start' });
  }
}

const date = (value: string | null | undefined) => value ? formatAccountingDate(value) : 'Por confirmar';
void run(() => client.fetchSunatCalendar(year.value));
</script>

<template>
  <div class="space-y-4">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-xl font-bold">Vencimientos SUNAT</h1>
        <p v-if="report" class="text-sm text-muted">RUC {{ report.ruc }} · {{ regime ?? 'Régimen por configurar' }}
          · {{ report.profile.monthly_group === 'BC' ? 'Buenos contribuyentes y UESP' : 'Último dígito ' + report.ruc.slice(-1) }}</p>
      </div>
      <button v-if="canEdit && report" class="ui-btn ui-btn-secondary" @click="editor = !editor">
        {{ editor ? 'Ocultar configuración' : 'Configurar obligaciones' }}
      </button>
    </header>

    <div class="grid gap-3 rounded-lg border border-border bg-surface p-3 sm:grid-cols-3">
      <div><label class="ui-label" for="sunat-year">Ejercicio</label><UiSelect id="sunat-year" :model-value="year" :options="years" :disabled="busy" @update:model-value="selectYear" /></div>
      <div><label class="ui-label" for="sunat-period">Periodo de la obligación</label><UiSelect id="sunat-period" v-model="month" :options="months" /></div>
      <div><label class="ui-label" for="sunat-scope">Obligaciones</label><UiSelect id="sunat-scope" v-model="scope" :options="[{value:'all',label:'Todas'}, {value:'books',label:'Libros y registros'}, {value:'tax',label:'Tributos'}]" /></div>
    </div>
    <p v-if="busy" role="status" class="text-sm text-muted">Consultando cronograma…</p>
    <div v-if="error" class="flex flex-wrap items-center gap-2">
      <p role="alert" class="text-sm text-danger">{{ error }}</p>
      <button v-if="!report" type="button" class="ui-btn ui-btn-secondary" :disabled="busy"
        @click="run(() => client.fetchSunatCalendar(year))">Volver a consultar</button>
    </div>

    <template v-if="report && profile">
      <div v-if="rce || monthly" class="grid gap-3 sm:grid-cols-2">
        <div v-if="rce" class="rounded-lg border border-border bg-surface p-4"><p class="text-sm text-muted">{{ rce.label }}</p><p class="mt-1 text-xl font-semibold tabular-nums">{{ date(rce.date) }}</p></div>
        <div v-if="monthly" class="rounded-lg border border-border bg-surface p-4"><p class="text-sm text-muted">{{ monthly.label }}</p><p class="mt-1 text-xl font-semibold tabular-nums">{{ date(monthly.date) }}</p></div>
      </div>
      <p v-if="report.profile.income_band === 'UNCONFIRMED' && ['GENERAL','MYPE_TRIBUTARIO','MYPE'].includes(regime ?? '')" class="rounded-lg border border-border bg-surface p-3 text-sm">
        Confirme el tramo de ingresos para mostrar los libros contables obligatorios.
      </p>
      <p class="text-xs text-muted">Cronogramas ordinarios. Registre fechas particulares con sustento para prórrogas por emergencia, F. 0625, ITAN sectorial u otras disposiciones.</p>

      <form v-if="editor && canEdit" class="space-y-4 rounded-lg border border-border bg-surface p-4" @submit.prevent="save">
        <h2 class="font-semibold">Obligaciones del ejercicio {{ year }}</h2>
        <fieldset :disabled="busy" class="space-y-4">
          <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div v-for="[key, label, options] in selectorFields" :key="key">
              <label class="ui-label" :for="'sunat-' + key">{{ label }}</label>
              <UiSelect :id="'sunat-' + key" v-model="profile[key]" :options="options.map(([value, label]) => ({value, label}))" :disabled="busy" />
            </div>
          </div>
          <p class="text-xs text-muted">El cronograma anual ampliado se confirma por los requisitos de la Ley 31940, con independencia del régimen tributario.</p>
          <div class="flex flex-wrap gap-3 text-sm">
            <label><input v-model="profile.annual_good" type="checkbox"> Buen contribuyente en el cronograma anual</label>
            <label><input v-model="profile.plame" type="checkbox"> Presenta PLAME</label>
            <label><input v-model="profile.sales_electronic" type="checkbox"> Compras y ventas electrónicas</label>
            <label><input v-model="profile.inventory_electronic" type="checkbox"> Inventario valorizado electrónico semestral</label>
          </div>
          <details class="space-y-2">
            <summary class="cursor-pointer font-semibold">DAOT del ejercicio</summary>
            <div class="grid gap-2 sm:grid-cols-2">
              <div><label class="ui-label" for="sunat-daot">Obligación DAOT</label><UiSelect id="sunat-daot" v-model="profile.daot_status"
                :options="[{value:'UNCONFIRMED',label:'Por confirmar'}, {value:'REQUIRED',label:'Obligada'}, {value:'NOT_REQUIRED',label:'No obligada'}]" :disabled="busy" /></div>
              <label class="ui-label">Sustento de la condición
                <input v-model.trim="profile.daot_reference" class="w-full" maxlength="500" :required="profile.daot_status !== 'UNCONFIRMED'">
              </label>
              <label class="text-sm"><input v-model="profile.daot_good" type="checkbox"> Buen contribuyente para el DAOT</label>
            </div>
            <p class="text-xs text-muted">Revise si aplica por PRICO al 31/12, obligación mensual de IGV y más de 75 UIT, u otros supuestos del artículo 3.</p>
          </details>
          <details>
            <summary class="cursor-pointer font-semibold">Libros y registros adicionales o exceptuados</summary>
            <div class="mt-3 grid gap-3 md:grid-cols-2">
              <div v-for="book in report.books" :key="book.code">
                <label class="ui-label" :for="'sunat-book-' + book.code">{{ book.code }} · {{ book.label }}</label>
                <UiSelect :id="'sunat-book-' + book.code" :options="bookChoices" :disabled="busy"
                  :model-value="profile.excluded_books.includes(book.code) ? 'exclude' : profile.extra_books.includes(book.code) ? 'include' : 'auto'"
                  @update:model-value="setBook(book.code, $event)" />
              </div>
            </div>
          </details>
          <section ref="overridesPanel" class="scroll-mt-16 space-y-2">
            <h3 class="font-semibold">Fechas particulares</h3>
            <div v-for="(row, i) in profile.overrides" :key="i" class="grid gap-2 rounded-md border border-border p-3 sm:grid-cols-2 lg:grid-cols-3">
              <label class="ui-label">Código <input v-model.trim="row.code" class="w-full" required maxlength="30"></label>
              <label class="ui-label">Obligación <input v-model.trim="row.label" class="w-full" required maxlength="160"></label>
              <div><label class="ui-label" :for="'override-type-' + i">Tipo</label><UiSelect :id="'override-type-' + i" v-model="row.type"
                :options="[{ value: 'tax', label: 'Tributo' }, { value: 'books', label: 'Libro o registro' }]" :disabled="busy" /></div>
              <label class="ui-label">Periodo AAAA-MM o AAAA <input v-model.trim="row.period" class="w-full" required maxlength="7"></label>
              <label class="ui-label">Vencimiento <input v-model="row.date" class="w-full" type="date" required></label>
              <label class="ui-label">Resolución, constancia o sustento <input v-model.trim="row.reference" class="w-full" required maxlength="500"></label>
              <button type="button" class="ui-btn ui-btn-ghost justify-self-start" @click="profile.overrides.splice(i,1)"><Trash2 /> Quitar fecha</button>
            </div>
            <button type="button" class="ui-btn ui-btn-secondary" @click="addOverride()"><Plus /> Agregar fecha</button>
          </section>
          <button class="ui-btn ui-btn-primary" type="submit" :disabled="busy"><Save /> Guardar configuración</button>
        </fieldset>
      </form>

      <div class="space-y-2 rounded-lg border border-border bg-surface p-3 sm:p-4">
        <div class="flex items-center gap-2 font-semibold"><CalendarDays class="size-4" /> Agenda del periodo</div>
        <p v-if="!events.length" class="text-sm text-muted">No hay obligaciones para los filtros seleccionados.</p>
        <ul v-else class="divide-y divide-border">
          <li v-for="event in events" :key="event.code+':'+event.period" class="grid gap-1 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start sm:gap-x-4">
            <div class="min-w-0">
              <p class="text-sm font-semibold">{{ event.label }}</p>
              <p class="break-words text-xs text-muted">{{ event.period }} · {{ event.basis }}</p>
              <a v-if="event.source" :href="event.source" class="text-xs text-brand underline" target="_blank" rel="noopener noreferrer">Fuente SUNAT</a>
            </div>
            <div class="flex flex-wrap items-center gap-2 sm:justify-end">
              <span class="text-sm font-semibold tabular-nums">{{ date(event.date) }}</span>
              <button v-if="canEdit" type="button" class="ui-btn ui-btn-ghost ui-btn-sm" :disabled="busy" @click="addOverride(event)">Anotar fecha particular</button>
            </div>
          </li>
        </ul>
      </div>
    </template>
  </div>
</template>
