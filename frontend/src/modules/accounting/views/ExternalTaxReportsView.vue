<script setup lang="ts">
import { computed, ref } from 'vue';
import { Download, Plus, Trash2 } from 'lucide-vue-next';
import UiSelect from '@/shared/ui/UiSelect.vue';
import { AccountingApiClient, ApiHttpError } from '../api/httpClient';
import type { CompanyId } from '../types/accountingAccount.types';
import type { DaotDocument, DaotReport, PdbInput, PdbReport } from '../types/externalTaxReports.types';
import { formatAccountingDate, peruAccountingDate } from '../utils/journalPresentation';
import { formatExactLocalAmount } from '../utils/formatExactLocalAmount';

const props = defineProps<{ activeCompanyId: CompanyId; getAccessToken: () => string | null; canEdit: boolean }>();
const emit = defineEmits<{ (e: 'calendar'): void }>();
const client = new AccountingApiClient('', { getActiveCompanyId: () => props.activeCompanyId, getAccessToken: props.getAccessToken });
const tab = ref<'daot' | 'pdb'>('daot');
const year = ref(Number(peruAccountingDate().slice(0, 4)) >= 2027 ? '2026' : '2025');
const years = ['2026','2025'].map((value) => ({ value, label: value }));
const prior = new Date(peruAccountingDate().slice(0, 7) + '-01T00:00:00Z');
prior.setUTCDate(0);
const month = ref(prior.toISOString().slice(0, 7));
const page = ref(1);
const scope = ref('all');
const busy = ref(false);
const error = ref('');
const daot = ref<DaotReport>();
const pdb = ref<PdbReport>();
const preparedInput = ref('');
const isPrepared = computed(() => preparedInput.value === JSON.stringify(supplement.value));
const editing = ref<DaotDocument>();
const decision = ref('included');
const reason = ref('');
const basis = ref('');
const supplement = ref<PdbInput[]>([]);
const previousId = ref<number>();
const pendingStatus = { pending: 'Por revisar', included: 'Incluido', excluded: 'Excluido' };
const companyStatus = { REQUIRED: 'Obligada', NOT_REQUIRED: 'No obligada', UNCONFIRMED: 'Obligación por confirmar' };
const paymentFields = [['method', 'Medio de pago'], ['bank', 'Código de entidad'], ['number', 'Operación'],
  ['date', 'Fecha de pago', 'date'], ['amount', 'Importe en PEN']] as const;
const transaction = { SALE: 'Ingresos', PURCHASE: 'Costos y gastos' };
const totals = computed(() => daot.value?.rows.filter((row) => row.over_threshold) ?? []);
const personalFields = [['surname', 'Apellido paterno'], ['second_surname', 'Apellido materno'], ['first_name', 'Primer nombre'],
  ['second_name', 'Segundo nombre'], ['customs_reference', 'DAM de importación · 13 dígitos'], ['total_pen', 'Total en PEN para pagos de compra en USD']] as const;
const supplementGroups = [
  { key: 'reference', label: 'Comprobante de referencia', fields: [
    ['type', 'Tipo'], ['series', 'Serie'], ['number', 'Número'], ['date', 'Fecha', 'date'],
    ['base', 'Base del ajuste'], ['tax', 'IGV del ajuste'], ['currency', 'Moneda PEN o USD'],
  ] },
  { key: 'detraction', label: 'Detracción', fields: [['rate', 'Código de tasa'], ['voucher', 'Constancia']] },
  { key: 'perception', label: 'Percepción', fields: [['rate', 'Código de tasa'], ['series', 'Serie'], ['number', 'Número']] },
  { key: 'shipment', label: 'Exportación de bienes', fields: [
    ['office', 'Aduana'], ['year', 'Año de la DAM'], ['number', 'Correlativo DAM'],
    ['shipped', 'Fecha de embarque', 'date'], ['regularized', 'Regularización', 'date'],
    ['dam_fob', 'FOB de la DAM en USD'], ['invoice_fob', 'FOB facturado en USD'], ['type', 'Tipo 01 o 05'],
  ] },
  { key: 'service', label: 'Exportación de servicios', fields: [
    ['detail', 'Código del servicio'], ['country', 'Código de país PDB'], ['payment_method', 'Medio de pago · 098 si está pendiente'],
    ['paid_on', 'Fecha de pago', 'date'], ['paid_amount', 'Monto pagado'], ['payment_number', 'Operación bancaria'], ['bank', 'Código del banco'],
  ] },
] as const;

const money = (value: string | number) => formatExactLocalAmount(String(value), 'PEN');
async function run(action: () => Promise<void>) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    await action();
  } catch (reason) {
    error.value = reason instanceof ApiHttpError ? Object.values(reason.validationErrors).flat()[0] ?? reason.message : 'No se pudo completar la consulta.';
  } finally {
    busy.value = false;
  }
}

function loadDaot(target = 1) {
  void run(async () => {
    const report = await client.fetchDaot(year.value, target, scope.value);
    daot.value = report;
    page.value = target;
    editing.value = undefined;
  });
}

function changeYear(value: string) {
  year.value = value;
  daot.value = undefined;
  loadDaot();
}

function edit(row: DaotDocument) {
  editing.value = row;
  decision.value = row.status === 'excluded' ? 'excluded' : 'included';
  reason.value = row.revision ? row.reason : '';
  basis.value = row.amount_pen?.replace(/^-/, '') ?? '';
}

function saveReview() {
  const row = editing.value;
  if (!row) return;
  void run(async () => {
    await client.reviewDaot(year.value, row.id, {
      included: decision.value === 'included', reference: reason.value, base_pen: basis.value || null, expected_revision: row.revision,
    });
    daot.value = await client.fetchDaot(year.value, page.value, scope.value);
    editing.value = undefined;
  });
}

function saveFile(blob: Blob, name: string) {
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = name;
  anchor.click();
  window.setTimeout(() => URL.revokeObjectURL(url), 1_000);
}

function downloadDaot(format: 'resumen' | 'detalle') {
  const report = daot.value;
  if (report) void run(async () => saveFile(await client.downloadDaot(year.value, format), `DAOT-${format}-${report.ruc}-${year.value}.csv`));
}

function preparePdb(include: boolean) {
  void run(async () => {
    const documents = supplement.value.map((row) => Object.fromEntries(Object.entries(row)
      .filter(([, value]) => value !== '').map(([key, value]) => [
        key, Array.isArray(value) ? value : value && typeof value === 'object'
          ? Object.fromEntries(Object.entries(value).filter(([, field]) => field !== ''))
          : value,
      ]))) as PdbInput[];
    pdb.value = await client.buildPdb(month.value, documents, include);
    preparedInput.value = JSON.stringify(supplement.value);
  });
}

function addSupplement(id: number) {
  if (Number.isInteger(id) && id > 0 && !supplement.value.some((row) => row.id === id)) {
    supplement.value.push({ id });
    previousId.value = undefined;
  }
}

function resetPdb(value: string) {
  if (!value || (supplement.value.length && !window.confirm('¿Cambiar de periodo y descartar los datos complementarios?'))) return;
  month.value = value;
  supplement.value = [];
  pdb.value = undefined;
}

function downloadPdb(file: PdbReport['files'][number]) {
  if (!file.base64) return;
  const bytes = Uint8Array.from(atob(file.base64), (byte) => byte.charCodeAt(0));
  saveFile(new Blob([bytes], { type: 'application/octet-stream' }), file.name);
}

loadDaot();
</script>

<template>
  <div class="space-y-4">
    <header>
      <h1 class="text-xl font-bold">DAOT y PDB Exportadores</h1>
      <div class="mt-3 inline-flex flex-wrap gap-1 rounded-lg border border-line bg-surf p-1" aria-label="Reporte tributario">
        <button type="button" class="ui-btn ui-btn-sm" :class="tab === 'daot' ? 'ui-btn-primary' : 'ui-btn-ghost'"
          :aria-pressed="tab === 'daot'" :disabled="busy" @click="tab = 'daot'">DAOT</button>
        <button type="button" class="ui-btn ui-btn-sm" :class="tab === 'pdb' ? 'ui-btn-primary' : 'ui-btn-ghost'"
          :aria-pressed="tab === 'pdb'" :disabled="busy" @click="tab = 'pdb'">PDB Exportadores</button>
      </div>
    </header>
    <p v-if="busy" role="status" class="text-sm text-muted">Procesando el reporte…</p>
    <p v-if="error" role="alert" class="rounded-md border border-line bg-surf p-3 text-sm text-neg">{{ error }}</p>

    <template v-if="tab === 'daot'">
      <section class="flex flex-wrap items-end gap-3 rounded-lg border border-line bg-surf p-3">
        <div class="w-36"><label for="daot-year" class="ui-label">Ejercicio</label><UiSelect id="daot-year" :model-value="year" :options="years" :disabled="busy" @update:model-value="changeYear" /></div>
        <button type="button" class="ui-btn ui-btn-secondary" :disabled="busy" @click="loadDaot()">Consultar</button>
        <button type="button" class="ui-btn ui-btn-ghost" @click="emit('calendar')">Configurar vencimiento</button>
      </section>
      <template v-if="daot">
        <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
          <div class="rounded-lg border border-line bg-surf p-3"><p class="text-xs text-muted">{{ daot.status === 'REQUIRED' ? 'Vence el' : 'Fecha ordinaria por RUC' }}</p><p class="font-semibold">{{ formatAccountingDate(daot.due) }}</p><p class="text-xs text-muted">{{ companyStatus[daot.status] }}</p></div>
          <div class="rounded-lg border border-line bg-surf p-3"><p class="text-xs text-muted">Umbral por tercero</p><p class="font-semibold">Más de {{ money(daot.threshold) }}</p></div>
          <div class="rounded-lg border border-line bg-surf p-3"><p class="text-xs text-muted">Operaciones sobre 2 UIT</p><p class="font-semibold">{{ totals.length }}</p></div>
          <div class="rounded-lg border border-line bg-surf p-3"><p class="text-xs text-muted">Comprobantes por revisar</p><p class="font-semibold">{{ daot.counts.pending }}</p></div>
        </div>
        <section class="space-y-2 rounded-lg border border-line bg-surf p-3 sm:p-4">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold">Totales por tercero</h2>
            <div class="flex flex-wrap gap-2">
              <button type="button" class="ui-btn ui-btn-secondary" :disabled="busy" @click="downloadDaot('resumen')"><Download class="size-4" /> Resumen CSV</button>
              <button type="button" class="ui-btn ui-btn-ghost" :disabled="busy" @click="downloadDaot('detalle')">Detalle CSV</button>
            </div>
          </div>
          <p v-if="daot.counts.pending" class="text-sm text-warn">El cálculo incluye las transacciones revisadas. Complete las pendientes antes de preparar la declaración o constancia.</p>
          <p v-else-if="!totals.length && daot.status === 'REQUIRED'" class="text-sm text-muted">Verifique que los libros estén completos y que corresponda presentar la constancia en SOL.</p>
          <div v-if="totals.length" class="overflow-x-auto">
            <table class="w-full min-w-[30rem] text-left text-sm">
              <thead><tr class="border-b border-line"><th class="p-2">Tercero</th><th class="p-2">Movimiento</th><th class="p-2 text-right">Base en PEN</th></tr></thead>
              <tbody class="divide-y divide-line"><tr v-for="row in totals.slice(0, 40)" :key="row.module + '-'+ row.identity_type + '-' + row.identity">
                <td class="p-2">{{ row.name }} <span class="block text-xs text-muted">{{ row.identity }}</span></td><td class="p-2">{{ transaction[row.module] }}</td>
                <td class="p-2 text-right tabular-nums">{{ money(row.amount_pen) }}</td>
              </tr></tbody>
            </table>
          </div>
          <p v-if="totals.length > 40" class="text-xs text-muted">Mostrando 40 resultados. El CSV contiene el resumen completo.</p>
        </section>
        <section class="space-y-3 rounded-lg border border-line bg-surf p-3 sm:p-4">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="font-semibold">Revisión de comprobantes</h2>
            <div class="w-40"><UiSelect v-model="scope" aria-label="Estado de comprobantes" :options="[{value:'all',label:'Todos'}, {value:'pending',label:'Por revisar'}]" :disabled="busy" @update:model-value="loadDaot()" /></div>
          </div>
          <form v-if="editing" class="grid gap-2 rounded-lg border border-line p-3 sm:grid-cols-2" @submit.prevent="saveReview">
            <p class="font-semibold sm:col-span-2">{{ editing.document }} · {{ editing.name }}</p>
            <div><label for="daot-decision" class="ui-label">Criterio DAOT</label><UiSelect id="daot-decision" v-model="decision" :options="[{value:'included',label:'Incluir'}, {value:'excluded',label:'Excluir'}]" /></div>
            <label v-if="decision === 'included'" class="ui-label">Base validada en PEN
              <input v-model.trim="basis" class="w-full" inputmode="decimal" :required="editing.currency !== 'PEN'" :placeholder="editing.currency === 'PEN' ? editing.basis + ' · base del comprobante' : 'Obligatoria para moneda extranjera'">
            </label>
            <label class="ui-label sm:col-span-2">Sustento de la decisión<input v-model.trim="reason" class="w-full" required maxlength="500"></label>
            <div class="flex gap-2"><button type="submit" class="ui-btn ui-btn-primary" :disabled="busy">Guardar revisión</button><button type="button" class="ui-btn ui-btn-ghost" @click="editing = undefined">Cancelar</button></div>
          </form>
          <p v-if="!daot.documents.data.length" class="text-sm text-muted">Sin comprobantes para el filtro.</p>
          <ul class="divide-y divide-line">
            <li v-for="row in daot.documents.data" :key="row.id" class="flex flex-wrap items-center justify-between gap-2 py-3">
              <div><p class="text-sm font-semibold">{{ row.document }} · {{ row.name }}</p><p class="text-xs text-muted">{{ row.reason }}</p></div>
              <div class="flex items-center gap-2 text-xs"><span>{{ pendingStatus[row.status] }}</span>
                <button v-if="canEdit" type="button" class="ui-btn ui-btn-ghost ui-btn-sm" :disabled="busy" @click="edit(row)">Revisar</button></div>
            </li>
          </ul>
          <div class="flex items-center gap-2 text-sm">
            <button type="button" class="ui-btn ui-btn-ghost" :disabled="page <= 1 || busy" @click="loadDaot(page - 1)">Anterior</button>
            <span>Página {{ page }}</span>
            <button type="button" class="ui-btn ui-btn-ghost" :disabled="!daot.documents.next_page_url || busy" @click="loadDaot(page + 1)">Siguiente</button>
          </div>
        </section>
      </template>
    </template>

    <template v-else>
      <section class="flex flex-wrap items-end gap-3 rounded-lg border border-line bg-surf p-3">
        <label class="ui-label">Periodo PDB
          <input :value="month" type="month" :disabled="busy" @change="resetPdb(($event.target as HTMLInputElement).value)"></label>
        <button type="button" class="ui-btn ui-btn-secondary" :disabled="busy" @click="preparePdb(false)">Revisar comprobantes</button>
        <button type="button" class="ui-btn ui-btn-primary" :disabled="busy" @click="preparePdb(true)">Preparar descargas</button>
      </section>
      <p class="text-sm text-muted">Revise los datos complementarios y cargue los archivos en PDB Exportadores 2.4. Los archivos corresponden al RUC activo y al periodo elegido.</p>
      <section v-if="pdb" class="space-y-3 rounded-lg border border-line bg-surf p-3 sm:p-4">
        <h2 class="font-semibold">{{ pdb.documents }} comprobantes del periodo</h2>
        <p v-if="!pdb.files.length" class="text-sm text-muted">Sin archivos disponibles para la selección actual.</p>
        <ul class="flex flex-wrap gap-2">
          <li v-for="file in pdb.files" :key="file.name">
            <button v-if="file.base64 && isPrepared" type="button" class="ui-btn ui-btn-secondary" @click="downloadPdb(file)"><Download class="size-4" /> {{ file.name }}</button>
            <span v-else class="inline-flex rounded-md border border-line px-3 py-2 text-sm">{{ file.name }} · {{ file.rows }}</span>
            <span v-if="file.review_required" class="block text-xs text-warn">Importación pendiente de validar en el PDB</span>
          </li>
        </ul>
        <ul v-if="pdb.problems.length" class="divide-y divide-line">
          <li v-for="(item, index) in pdb.problems" :key="index" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
            <span><b>{{ item.document }}</b> · {{ item.message }}</span>
            <button v-if="item.document_id" type="button" class="ui-btn ui-btn-ghost ui-btn-sm" @click="addSupplement(item.document_id)">Completar datos</button>
          </li>
        </ul>
        <div v-if="pdb.export_candidates.length" class="space-y-1">
          <h3 class="font-semibold">Comprobantes de exportación</h3>
          <div v-for="item in pdb.export_candidates" :key="item.id" class="flex flex-wrap items-center gap-2 text-sm">
            <span>{{ item.document }} · {{ item.name }}</span>
            <button type="button" class="ui-btn ui-btn-ghost ui-btn-sm" @click="addSupplement(item.id)">Vincular exportación</button>
          </div>
        </div>
        <a :href="pdb.source" target="_blank" rel="noopener noreferrer" class="text-xs text-brand underline">Aplicativo y ayuda de SUNAT</a>
      </section>
      <section class="space-y-3 rounded-lg border border-line bg-surf p-3 sm:p-4">
        <h2 class="font-semibold">Datos complementarios de la descarga</h2>
        <form class="flex flex-wrap items-end gap-2" @submit.prevent="previousId && addSupplement(previousId)">
          <label class="ui-label">ID del comprobante actual o anterior<input v-model.number="previousId" type="number" min="1" required class="block"></label>
          <button type="submit" class="ui-btn ui-btn-secondary" :disabled="busy"><Plus class="size-4" /> Agregar datos</button>
        </form>
        <fieldset v-for="row in supplement" :key="row.id" :disabled="busy" class="space-y-2 rounded-md border border-line p-3">
          <legend class="px-1 font-medium">Comprobante {{ row.id }}</legend>
          <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            <label v-for="[key, label] in personalFields" :key="key" class="ui-label">{{ label }}<input v-model.trim="row[key]" class="w-full"></label>
            <label class="ui-label">Pago del impuesto de la compra externa<input v-model="row.tax_paid_on" type="date" class="w-full"></label>
            <label class="text-sm"><input v-model="row.foreign_without_id" type="checkbox"> No domiciliado sin documento</label>
            <label class="text-sm"><input v-model="row.retention" type="checkbox"> Retención aplicable a la compra</label>
          </div>
          <div v-for="group in supplementGroups" :key="group.key" class="space-y-2 border-t border-line pt-2">
            <label class="text-sm font-medium"><input type="checkbox" :checked="!!row[group.key]"
              @change="($event.target as HTMLInputElement).checked ? row[group.key] = {} : delete row[group.key]"> {{ group.label }}</label>
            <div v-if="row[group.key]" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
              <label v-for="field in group.fields" :key="field[0]" class="ui-label">{{ field[1] }}<input v-model.trim="row[group.key]![field[0]]" :type="field[2] ?? 'text'" class="w-full"></label>
            </div>
          </div>
          <div class="space-y-2 border-t border-line pt-2">
            <button type="button" class="ui-btn ui-btn-secondary ui-btn-sm" @click="(row.payments ??= []).push({ method: '' })"><Plus class="size-4" /> Agregar pago de compra</button>
            <div v-for="(payment, index) in row.payments" :key="index" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
              <label v-for="field in paymentFields" :key="field[0]" class="ui-label">{{ field[1] }}<input v-model.trim="payment[field[0]]" :type="field[2] ?? 'text'" class="w-full"></label>
              <button type="button" class="ui-btn ui-btn-ghost justify-self-start" @click="row.payments?.splice(index, 1); if (!row.payments?.length) delete row.payments">Quitar pago</button>
            </div>
          </div>
          <button type="button" class="ui-btn ui-btn-ghost" @click="supplement = supplement.filter((item) => item.id !== row.id)"><Trash2 class="size-4" /> Quitar datos</button>
        </fieldset>
      </section>
    </template>
  </div>
</template>
