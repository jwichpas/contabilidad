<script setup lang="ts">
import { computed, onScopeDispose, ref } from 'vue';
import { Download, Plus, Printer, RefreshCw, Save, Trash2 } from 'lucide-vue-next';
import { AccountingApiClient, ApiHttpError } from '../api/httpClient';
import type { CompanyId } from '../types/accountingAccount.types';
import type { DeferredTaxLine, TaxAdjustment, TaxClose, TaxCloseAction, TaxCloseInputs, TaxCloseResult } from '../types/annualTaxClose.types';
import { formatExactLocalAmount } from '../utils/formatExactLocalAmount';
import { openStatementTablePrint } from '../utils/printStatementTable';
import UiSelect from '@/shared/ui/UiSelect.vue';

const props = defineProps<{
  activeCompanyId: CompanyId;
  companyName: string;
  companyTaxId: string;
  getAccessToken: () => string | null;
  canEdit: boolean;
  canReview: boolean;
}>();

const year = 2025;
const client = new AccountingApiClient('', {
  getActiveCompanyId: () => props.activeCompanyId,
  getAccessToken: props.getAccessToken,
});
const emptyDraft = (): TaxCloseInputs => ({
  book_profit: '0', book_reference: '', prior_losses_used: '0', participation_rate: '0',
  participation_deductible: '0', participation_admin: '0', participation_sales: '0', participation_reference: '', adjustments: [],
  itan_assets: '0', itan_additions: '0', itan_deductions: '0', itan_paid: '0',
  itan_at_close: '0', itan_reference: '', advances: [], ciprl_available: '0',
  ciprl_applied: '0', ciprl_reference: '', deferred: [],
});

const bookFields = [
  ['book_profit', 'Resultado antes de participación e impuesto'],
  ['prior_losses_used', 'Pérdida anterior aplicada'],
  ['participation_rate', 'Tasa de participación (%)'],
  ['participation_deductible', 'Participación deducible'],
  ['participation_admin', 'Participación a administración (94)'],
  ['participation_sales', 'Participación a ventas (95)'],
] as const;
const creditFields = [
  ['itan_assets', 'Activo del ejercicio anterior'], ['itan_additions', 'Adiciones ITAN'],
  ['itan_deductions', 'Deducciones ITAN'], ['itan_paid', 'ITAN efectivamente pagado'],
  ['itan_at_close', 'ITAN aplicado a la regularización'], ['ciprl_available', 'CIPRL disponible'],
  ['ciprl_applied', 'CIPRL aplicado'],
] as const;
const summaryFields: [keyof Omit<TaxCloseResult, 'adjustment_amounts' | 'journal_proposals'>, string][] = [
  ['casilla_103', 'Adiciones · FV 710, casilla 103'],
  ['casilla_105', 'Deducciones · FV 710, casilla 105'],
  ['adjusted_income', 'Resultado conciliado'],
  ['participation', 'Participación de trabajadores calculada'],
  ['taxable_income', 'Renta neta imponible'],
  ['current_tax', 'Impuesto a la Renta corriente'],
  ['itan_base', 'Base del ITAN'], ['itan_assessed', 'ITAN determinado'],
  ['advances_cash', 'Pagos a cuenta en efectivo'], ['itan_in_advances', 'ITAN aplicado a pagos a cuenta'],
  ['advances_total', 'Total de pagos a cuenta aplicados'],
  ['settlement_before_ciprl', 'Saldo por regularizar antes de CIPRL'],
  ['credit_balance', 'Saldo a favor del ejercicio'],
  ['cash_to_pay', 'Saldo luego de aplicar CIPRL'],
  ['asset_opening', 'Activo diferido inicial'], ['asset_closing', 'Activo diferido final'],
  ['liability_opening', 'Pasivo diferido inicial'], ['liability_closing', 'Pasivo diferido final'],
  ['deferred_expense', 'Efecto del impuesto diferido'],
  ['tax_expense', 'Gasto neto por impuesto'], ['net_profit', 'Resultado neto del ejercicio'],
];

const directions: { value: TaxAdjustment['direction']; label: string }[] = [
  { value: 'ADDITION', label: 'Adición' }, { value: 'DEDUCTION', label: 'Deducción' },
];
const kinds: { value: TaxAdjustment['kind']; label: string }[] = [
  { value: 'PERMANENT', label: 'Permanente' }, { value: 'TEMPORARY', label: 'Temporal' },
];
const deferredKinds: { value: DeferredTaxLine['kind']; label: string }[] = [
  { value: 'ASSET', label: 'Activo' }, { value: 'LIABILITY', label: 'Pasivo' },
];
const statuses = { DRAFT: 'Borrador', IN_REVIEW: 'En revisión', APPROVED: 'Aprobado' };
const draft = ref(emptyDraft());
const savedDraft = ref('');
const data = ref<TaxClose | null>(null);
const reason = ref('');
const busy = ref(false);
const errors = ref<string[]>([]);
const notice = ref('');
let disposed = false;
onScopeDispose(() => { disposed = true; });

const current = computed(() => data.value !== null && data.value.revision === data.value.latest_revision);
const editable = computed(() => props.canEdit && current.value && data.value?.status === 'DRAFT');
const dirty = computed(() => JSON.stringify(draft.value) !== savedDraft.value);
const result = computed(() => dirty.value ? null : data.value?.result);
const summary = computed(() => summaryFields.map(([key, label]) => [label, result.value?.[key] ?? '']));
const money = (amount: string) => formatExactLocalAmount(amount, 'PEN');

async function run(task: () => Promise<void>) {
  if (busy.value) return;
  busy.value = true;
  errors.value = [];
  notice.value = '';
  try {
    await task();
  } catch (error) {
    if (disposed) return;
    const details = error instanceof ApiHttpError ? Object.values(error.validationErrors).flat() : [];
    errors.value = details.length ? [...new Set(details)] : [
      error instanceof Error ? error.message : 'No se pudo procesar el expediente.',
    ];
  } finally {
    busy.value = false;
  }
}

async function load(revision?: number) {
  const response = await client.fetchAnnualTaxClose(year, revision);
  if (disposed) return;
  data.value = response;
  draft.value = response.inputs ?? emptyDraft();
  savedDraft.value = JSON.stringify(draft.value);
  reason.value = '';
}

function reload() {
  if (dirty.value && data.value && !window.confirm('¿Descartar los cambios sin guardar y cargar la revisión vigente?')) return;
  void run(() => load());
}

function save() {
  if (!editable.value || !data.value) return;
  const revision = data.value.revision;
  void run(async () => {
    await client.saveAnnualTaxClose(year, draft.value, revision);
    await load();
    notice.value = 'Borrador guardado y recalculado.';
  });
}

function transition(action: TaxCloseAction) {
  if (!current.value || dirty.value || !data.value) return;
  const revision = data.value.revision;
  void run(async () => {
    await client.transitionAnnualTaxClose(year, action, revision, reason.value.trim() || undefined);
    await load();
    notice.value = 'Revisión registrada.';
  });
}

function addAdvance() {
  const month = Array.from({ length: 12 }, (_, i) => i + 1)
    .find((value) => draft.value.advances.every((line) => line.month !== value));
  if (month !== undefined) {
    draft.value.advances.push({ month, assessed: '0', cash_paid: '0', itan_used: '0', reference: '' });
  }
}

function print() {
  if (!result.value || !data.value) return;
  const rows = [
    ...summary.value.map(([label, value]) => [label!, money(value!)]),
    ...draft.value.adjustments.map((line, i) => [
      `${line.label} · ${line.reference ?? ''}`, money(result.value!.adjustment_amounts[i]!),
    ]),
  ];
  if (!openStatementTablePrint({
    company: props.companyName, taxId: props.companyTaxId, title: 'Papel de trabajo del cierre tributario',
    period: `${year} · revisión ${data.value.revision} · ${statuses[data.value.status]}`,
    currency: 'PEN', columns: ['Concepto', 'Importe'], rows,
    notices: ['Traslado asistido de casillas 103 y 105. Verifique los datos en el FV 710 oficial.'],
  })) errors.value = ['El navegador bloqueó la ventana de impresión.'];
}

function exportWorkingPaper() {
  if (!result.value || !data.value) return;
  const rows = [
    ['Empresa', props.companyName, 'RUC', props.companyTaxId],
    ['Papel de trabajo', String(year), 'Revisión', String(data.value.revision), statuses[data.value.status]],
    ['Versión', data.value.rule_version],
    ['Cálculo', 'Importe PEN'], ...summary.value, [],
    ['Concepto', 'Sentido', 'Tipo', 'Base contable', 'Base fiscal', 'Importe', 'Cuenta', 'Sustento'],
    ...draft.value.adjustments.map((line, i) => [
      line.label, line.direction, line.kind, line.book_base, line.tax_base,
      result.value!.adjustment_amounts[i]!, line.account ?? '', line.reference ?? '',
    ]),
    [], ['Periodo mensual', 'Determinado', 'Pagado', 'ITAN compensado', 'Sustento'],
    ...draft.value.advances.map((line) => [String(line.month), line.assessed, line.cash_paid, line.itan_used, line.reference ?? '']),
    [], ['Diferido', 'Tipo', 'Impuesto inicial', 'Diferencia final', 'Tasa', 'Reconocer cierre', 'Sustento'],
    ...draft.value.deferred.map((line) => [
      line.label, line.kind, line.opening_tax, line.closing_difference, line.rate,
      line.recognize_closing ? 'Sí' : 'No', line.reference ?? '',
    ]),
    [], ['Propuesta contable', 'Cuenta de referencia', 'Debe', 'Haber'],
    ...result.value.journal_proposals.flatMap((journal) =>
      journal.lines.map((line) => [journal.title, line.account, line.debit, line.credit])),
  ];
  const csvCell = (value: string) => {
    const unsafe = /^[\s\uFEFF]*[=+\-@]/.test(value) && !/^-?\d+(?:\.\d+)?$/.test(value);
    return '"' + (unsafe ? "'" : '') + value.replaceAll('"', '""') + '"';
  };
  const csv = '\uFEFF' + rows.map((row) => row.map(csvCell).join(',')).join('\r\n');
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
  const link = document.createElement('a');
  link.href = url;
  link.download = `Cierre-tributario-${year}-r${data.value.revision}.csv`;
  link.click();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

void run(load);
</script>

<template>
  <div class="space-y-4">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-xl font-bold text-foreground">Cierre tributario anual</h1>
        <p class="text-sm text-muted">Régimen General · {{ year }} · {{ data?.rule_version ?? 'PEN' }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button class="ui-btn ui-btn-secondary" :disabled="busy" @click="reload">
          <RefreshCw /> {{ current ? 'Actualizar' : 'Ver revisión vigente' }}
        </button>
        <button v-if="editable" class="ui-btn ui-btn-primary" :disabled="busy" @click="save"><Save /> Guardar y calcular</button>
      </div>
    </header>
    <p v-if="busy" role="status" class="text-sm text-muted">Procesando expediente…</p>
    <ul v-if="errors.length" role="alert" class="rounded-lg border border-danger/30 bg-danger/5 p-3 text-sm text-danger">
      <li v-for="error in errors" :key="error">{{ error }}</li>
    </ul>
    <p v-if="notice" role="status" class="text-sm text-foreground">{{ notice }}</p>

    <template v-if="data">
      <div class="flex flex-wrap items-center gap-2 rounded-lg border border-border bg-surface p-3">
        <span class="mr-auto text-sm font-semibold">{{ statuses[data.status] }} · Revisión {{ data.revision }}</span>
        <template v-if="result">
          <button class="ui-btn ui-btn-secondary" @click="exportWorkingPaper"><Download /> Descargar CSV</button>
          <button class="ui-btn ui-btn-secondary" @click="print"><Printer /> Imprimir</button>
        </template>
        <button v-if="editable && data.revision > 0" class="ui-btn ui-btn-primary" :disabled="busy || dirty" @click="transition('submit')">Enviar a revisión</button>
        <template v-if="canReview && current && data.status !== 'DRAFT'">
          <button v-if="data.status === 'IN_REVIEW'" class="ui-btn ui-btn-primary" :disabled="busy" @click="transition('approve')">Aprobar expediente</button>
          <label class="text-xs text-muted">Motivo
            <input v-model="reason" class="mt-1 w-full min-w-0" maxlength="500">
          </label>
          <button class="ui-btn ui-btn-secondary" :disabled="busy || !reason.trim()" @click="transition(data.status === 'APPROVED' ? 'rectify' : 'return')">
            {{ data.status === 'APPROVED' ? 'Abrir rectificación' : 'Devolver a borrador' }}
          </button>
        </template>
      </div>

      <section v-if="result" class="rounded-lg border border-border bg-surface p-4">
        <h2 class="font-semibold">Liquidación y conciliación</h2>
        <dl class="mt-2 grid gap-x-6 md:grid-cols-2">
          <div v-for="([label, value], index) in summary" :key="index" class="flex justify-between gap-3 border-b border-border py-2 text-sm">
            <dt>{{ label }}</dt><dd class="whitespace-nowrap font-semibold tabular-nums">{{ money(value!) }}</dd>
          </div>
        </dl>
      </section>
      <p v-else-if="dirty" class="text-sm text-muted">Guarde los cambios para ver el cálculo actualizado.</p>

      <fieldset :disabled="!editable || busy" class="min-w-0 space-y-3">
        <section class="rounded-lg border border-border bg-surface p-4">
          <h2 class="font-semibold">Base contable y participación</h2>
          <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label v-for="[key, label] in bookFields" :key="key" class="ui-label">{{ label }}
              <input v-model.trim="draft[key]" class="mt-1 w-full min-w-0" inputmode="decimal">
            </label>
            <label class="ui-label sm:col-span-2">Referencia del resultado contable
              <input v-model.trim="draft.book_reference" class="mt-1 w-full min-w-0" maxlength="500">
            </label>
            <label class="ui-label sm:col-span-2">Sustento de participación y pérdidas aplicadas
              <input v-model.trim="draft.participation_reference" class="mt-1 w-full min-w-0" maxlength="500">
            </label>
          </div>
        </section>

        <details open class="rounded-lg border border-border bg-surface p-4">
          <summary class="cursor-pointer font-semibold">Adiciones y deducciones · {{ draft.adjustments.length }}</summary>
          <div v-for="(row, i) in draft.adjustments" :key="i" class="mt-3 grid gap-2 rounded-md border border-border p-3 sm:grid-cols-2 xl:grid-cols-4">
            <label class="ui-label sm:col-span-2">Concepto <input v-model.trim="row.label" class="mt-1 w-full min-w-0" maxlength="150"></label>
            <div><label :for="'tax-direction-' + i" class="ui-label">Sentido</label><UiSelect :id="'tax-direction-' + i" v-model="row.direction" :options="directions" :disabled="!editable || busy" /></div>
            <div><label :for="'tax-kind-' + i" class="ui-label">Diferencia</label><UiSelect :id="'tax-kind-' + i" v-model="row.kind" :options="kinds" :disabled="!editable || busy" /></div>
            <label class="ui-label">Base contable <input v-model.trim="row.book_base" class="mt-1 w-full min-w-0" inputmode="decimal"></label>
            <label class="ui-label">Base tributaria <input v-model.trim="row.tax_base" class="mt-1 w-full min-w-0" inputmode="decimal"></label>
            <label class="ui-label">Cuenta PCGE <input v-model.trim="row.account" class="mt-1 w-full min-w-0" inputmode="numeric" maxlength="8"></label>
            <label class="ui-label">Sustento <input v-model.trim="row.reference" class="mt-1 w-full min-w-0" maxlength="500"></label>
            <button v-if="editable" class="ui-btn ui-btn-ghost justify-self-start" :aria-label="'Quitar reparo ' + (i + 1)" @click="draft.adjustments.splice(i, 1)"><Trash2 /> Quitar</button>
          </div>
          <button v-if="editable" class="ui-btn ui-btn-secondary mt-3" @click="draft.adjustments.push({ label: '', direction: 'ADDITION', kind: 'PERMANENT', book_base: '0', tax_base: '0' })"><Plus /> Agregar reparo</button>
        </details>

        <details class="rounded-lg border border-border bg-surface p-4">
          <summary class="cursor-pointer font-semibold">Pagos a cuenta, ITAN y CIPRL</summary>
          <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label v-for="[key, label] in creditFields" :key="key" class="ui-label">{{ label }}
              <input v-model.trim="draft[key]" class="mt-1 w-full min-w-0" inputmode="decimal">
            </label>
            <label class="ui-label">Sustento ITAN <input v-model.trim="draft.itan_reference" class="mt-1 w-full min-w-0" maxlength="500"></label>
            <label class="ui-label">Sustento CIPRL <input v-model.trim="draft.ciprl_reference" class="mt-1 w-full min-w-0" maxlength="500"></label>
          </div>
          <div v-for="(row, i) in draft.advances" :key="i" class="mt-3 grid gap-2 rounded-md border border-border p-3 sm:grid-cols-2 xl:grid-cols-5">
            <label class="ui-label">Mes <input v-model.number="row.month" class="mt-1 w-full min-w-0" type="number" min="1" max="12"></label>
            <label class="ui-label">Determinado <input v-model.trim="row.assessed" class="mt-1 w-full min-w-0" inputmode="decimal"></label>
            <label class="ui-label">Pago efectivo <input v-model.trim="row.cash_paid" class="mt-1 w-full min-w-0" inputmode="decimal"></label>
            <label class="ui-label">ITAN utilizado <input v-model.trim="row.itan_used" class="mt-1 w-full min-w-0" inputmode="decimal"></label>
            <label class="ui-label">Constancia <input v-model.trim="row.reference" class="mt-1 w-full min-w-0" maxlength="500"></label>
            <button v-if="editable" class="ui-btn ui-btn-ghost justify-self-start" :aria-label="'Quitar pago ' + (i + 1)" @click="draft.advances.splice(i, 1)"><Trash2 /> Quitar</button>
          </div>
          <button v-if="editable" class="ui-btn ui-btn-secondary mt-3" :disabled="draft.advances.length >= 12" @click="addAdvance"><Plus /> Agregar periodo</button>
        </details>

        <details class="rounded-lg border border-border bg-surface p-4">
          <summary class="cursor-pointer font-semibold">Impuesto diferido · {{ draft.deferred.length }}</summary>
          <p class="mt-2 text-xs text-muted">Partidas con efecto en resultados. Registre la diferencia al cierre y el impuesto reconocido al inicio.</p>
          <div v-for="(row, i) in draft.deferred" :key="i" class="mt-3 grid gap-2 rounded-md border border-border p-3 sm:grid-cols-2 xl:grid-cols-4">
            <label class="ui-label sm:col-span-2">Partida <input v-model.trim="row.label" class="mt-1 w-full min-w-0" maxlength="150"></label>
            <div><label :for="'deferred-kind-' + i" class="ui-label">Tipo</label><UiSelect :id="'deferred-kind-' + i" v-model="row.kind" :options="deferredKinds" :disabled="!editable || busy" /></div>
            <label class="ui-label">Impuesto inicial <input v-model.trim="row.opening_tax" class="mt-1 w-full min-w-0" inputmode="decimal"></label>
            <label class="ui-label">Diferencia final <input v-model.trim="row.closing_difference" class="mt-1 w-full min-w-0" inputmode="decimal"></label>
            <label class="ui-label">Tasa (%) <input v-model.trim="row.rate" class="mt-1 w-full min-w-0" inputmode="decimal"></label>
            <label class="ui-label">Sustento <input v-model.trim="row.reference" class="mt-1 w-full min-w-0" maxlength="500"></label>
            <label v-if="row.kind === 'ASSET'" class="flex items-center gap-2 text-sm"><input v-model="row.recognize_closing" type="checkbox"> Reconocer activo recuperable</label>
            <button v-if="editable" class="ui-btn ui-btn-ghost justify-self-start" :aria-label="'Quitar diferencia ' + (i + 1)" @click="draft.deferred.splice(i, 1)"><Trash2 /> Quitar</button>
          </div>
          <button v-if="editable" class="ui-btn ui-btn-secondary mt-3" @click="draft.deferred.push({ label: '', kind: 'ASSET', opening_tax: '0', closing_difference: '0', rate: '29.5', recognize_closing: false })"><Plus /> Agregar diferencia</button>
        </details>
      </fieldset>

      <details v-if="result?.journal_proposals.length" class="rounded-lg border border-border bg-surface p-4">
        <summary class="cursor-pointer font-semibold">Propuestas de asientos</summary>
        <p class="mt-2 text-sm text-muted">Revise saldos, subcuentas y dimensiones antes de contabilizar desde el Diario.</p>
        <div v-for="journal in result.journal_proposals" :key="journal.code" class="mt-3 overflow-x-auto rounded-md border border-border p-3">
          <table class="w-full min-w-64 text-sm">
            <caption class="pb-2 text-left font-semibold">{{ journal.title }}</caption>
            <thead><tr class="text-muted"><th scope="col" class="text-left">Cuenta</th><th scope="col" class="text-right">Debe</th><th scope="col" class="text-right">Haber</th></tr></thead>
            <tbody>
              <tr v-for="line in journal.lines" :key="line.account" class="border-t border-border">
                <td class="py-1">{{ line.account }}</td><td class="text-right tabular-nums">{{ money(line.debit) }}</td><td class="text-right tabular-nums">{{ money(line.credit) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </details>

      <details v-if="data.history.length" class="rounded-lg border border-border bg-surface p-4">
        <summary class="cursor-pointer font-semibold">Historial de revisiones</summary>
        <ul class="mt-2 divide-y divide-border">
          <li v-for="entry in data.history" :key="entry.revision" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
            <span>Revisión {{ entry.revision }} · {{ statuses[entry.status] }}<template v-if="entry.reason"> · {{ entry.reason }}</template></span>
            <button class="ui-btn ui-btn-ghost" :disabled="busy || dirty || entry.revision === data.revision" @click="run(() => load(entry.revision))">Abrir revisión</button>
          </li>
        </ul>
      </details>
    </template>
  </div>
</template>
