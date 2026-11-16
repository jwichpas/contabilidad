<script setup lang="ts">
import { computed, onScopeDispose, reactive, ref, shallowRef, watch } from 'vue';
import { AlertTriangle, Plus, RefreshCw, Save } from 'lucide-vue-next';
import { AccountingApiClient } from '../api/httpClient';
import type { CompanyId } from '../types/accountingAccount.types';
import type {
  CashFlowActivity, CashFlowDirection, CashFlowPresentationSettings,
  CashFlowPresentationResponse,
} from '../types/cashFlow.types';

type MutableSettings = {
  hide_zero: boolean;
  labels: Record<string, string>;
  hidden_codes: string[];
  custom_lines: Array<{code:string;label:string;section:CashFlowActivity;direction:CashFlowDirection}>;
  account_rules: Array<{account_code:string;section:CashFlowActivity;direction:CashFlowDirection;target_code:string}>;
  area_rules: Array<{area_code:string;section:CashFlowActivity;direction:CashFlowDirection;target_code:string}>;
};

const props = defineProps<{
  readonly activeCompanyId: CompanyId;
  readonly getAccessToken: () => string | null;
  readonly canEdit: boolean;
}>();
const emit = defineEmits<{saved: []}>();
const client = computed(() => new AccountingApiClient('', {
  getAccessToken: props.getAccessToken,
  getActiveCompanyId: () => props.activeCompanyId,
}));
const empty = (): MutableSettings => ({
  hide_zero: false, labels: {}, hidden_codes: [], custom_lines: [], account_rules: [], area_rules: [],
});
const draft = ref<MutableSettings>(empty());
const fetched = shallowRef<CashFlowPresentationResponse | null>(null);
const pending = shallowRef(false);
const saving = shallowRef(false);
const error = shallowRef<string | null>(null);
const notice = shallowRef<string | null>(null);
let version = 0;

const standardLines = computed(() => Object.entries(fetched.value?.standard ?? {})
  .filter(([, line]) => line.kind === 'detail')
  .map(([code, line]) => ({code, ...line})));
const targets = computed(() => [
  ...standardLines.value.map(({code,label,section,direction}) => ({code,label,section,direction})),
  ...draft.value.custom_lines.map((line) => ({...line})),
]);
const activities: {value:CashFlowActivity;label:string}[] = [
  {value:'OPERATING', label:'Operación'},
  {value:'INVESTING', label:'Inversión'},
  {value:'FINANCING', label:'Financiación'},
];
const directions: {value:CashFlowDirection;label:string}[] = [
  {value:'INFLOW',label:'Cobro'}, {value:'OUTFLOW',label:'Pago'},
];
const newLine = reactive({
  code:'',label:'',section:'OPERATING' as CashFlowActivity,direction:'INFLOW' as CashFlowDirection,
});
const newAccount = reactive({
  account_code:'',section:'OPERATING' as CashFlowActivity,direction:'INFLOW' as CashFlowDirection,target_code:'1102',
});
const newArea = reactive({
  area_code:'',section:'OPERATING' as CashFlowActivity,direction:'INFLOW' as CashFlowDirection,target_code:'1102',
});
function eligibleTargets(section: CashFlowActivity, direction: CashFlowDirection) {
  return targets.value.filter((item) => item.section === section && item.direction === direction);
}
function cloneSettings(value: CashFlowPresentationSettings): MutableSettings {
  return {
    hide_zero:value.hide_zero,
    labels:{...value.labels},
    hidden_codes:[...value.hidden_codes],
    custom_lines:value.custom_lines.map((item)=>({...item})),
    account_rules:value.account_rules.map((item)=>({...item})),
    area_rules:value.area_rules.map((item)=>({...item})),
  };
}
async function reload(): Promise<void> {
  const request = ++version;
  const tenant = props.activeCompanyId;
  pending.value = true;
  fetched.value = null;
  error.value = null;
  notice.value = null;
  draft.value = empty();
  try {
    const data = await client.value.fetchCashFlowPresentation();
    if (request === version && tenant === props.activeCompanyId) {
      fetched.value = data;
      draft.value = cloneSettings(data.data.settings);
    }
  } catch (cause) {
    if (request === version && tenant === props.activeCompanyId) {
      error.value = cause instanceof Error ? cause.message : 'No se pudo consultar la configuración.';
    }
  } finally {
    if (request === version) pending.value = false;
  }
}
function toggleHidden(code: string, checked: boolean): void {
  draft.value.hidden_codes = checked
    ? [...new Set([...draft.value.hidden_codes, code])]
    : draft.value.hidden_codes.filter((value) => value !== code);
}
function addLine(): void {
  const code = newLine.code.trim().toUpperCase();
  const label = newLine.label.trim();
  if (!/^C[0-9]{4}$/.test(code) || label.length < 2
    || targets.value.some((item) => item.code === code)) {
    error.value = 'El concepto necesita un código interno único C0001–C9999 y una descripción.';
    return;
  }
  draft.value.custom_lines.push({code,label,section:newLine.section,direction:newLine.direction});
  newLine.code = '';newLine.label = '';error.value = null;
}
function addRule(kind: 'account' | 'area'): void {
  const input = kind === 'account' ? newAccount : newArea;
  const source = (kind === 'account' ? newAccount.account_code : newArea.area_code).trim().toUpperCase();
  const target = eligibleTargets(input.section,input.direction).find((item) => item.code === input.target_code);
  if (!target || (kind === 'account' ? !/^[0-9]{3,8}$/.test(source) : !/^[A-Z0-9_-]{2,32}$/.test(source))) {
    error.value = 'Indica una cuenta/área válida y un concepto de la misma actividad y dirección.';
    return;
  }
  if (kind === 'account') {
    if (draft.value.account_rules.some((r) => r.account_code === source
      && r.section === input.section && r.direction === input.direction)) {
      error.value = 'La regla PCGE ya existe.';return;
    }
    draft.value.account_rules.push({account_code:source,section:input.section,direction:input.direction,target_code:target.code});
    newAccount.account_code = '';
  } else {
    if (draft.value.area_rules.some((r) => r.area_code === source
      && r.section === input.section && r.direction === input.direction)) {
      error.value = 'La regla del área ya existe.';return;
    }
    draft.value.area_rules.push({area_code:source,section:input.section,direction:input.direction,target_code:target.code});
    newArea.area_code = '';
  }
  error.value = null;
}
async function save(): Promise<void> {
  if (!props.canEdit || !fetched.value || saving.value) return;
  const tenant = props.activeCompanyId;
  const request = version;
  saving.value = true;error.value = null;notice.value = null;
  try {
    const data = await client.value.saveCashFlowPresentation(draft.value, fetched.value.data.revision);
    if (request === version && tenant === props.activeCompanyId) {
      emit('saved');
      await reload();
      if (tenant === props.activeCompanyId) {
        notice.value = 'Configuración guardada. Revisión ' + data.revision + '.';
      }
    }
  } catch(cause) {
    if (request === version && tenant === props.activeCompanyId) {
      error.value = cause instanceof Error ? cause.message : 'No se pudo guardar. Revise los datos.';
    }
  } finally { if (tenant === props.activeCompanyId) saving.value = false; }
}
watch(() => props.activeCompanyId, () => { saving.value = false; void reload(); }, { immediate:true, flush:'sync' });
onScopeDispose(() => { version++; });
</script>

<template>
  <section class="space-y-5 rounded-xl border border-border bg-surface p-4 sm:p-5" aria-label="Configuración del flujo de efectivo">
    <div class="flex flex-wrap justify-between gap-3">
      <div>
        <h3 class="text-lg font-semibold">Configuración del Flujo de Efectivo</h3>
        <p class="mt-1 text-xs leading-relaxed text-muted">
          El catálogo estándar está protegido. Estos ajustes solo se aplican a la empresa activa y no editan el Libro Diario.
        </p>
        <p v-if="fetched" class="mt-1 text-xs text-muted">
          Revisión actual: {{ fetched.data.revision }} · {{ fetched.history.length }} revisión(es) recientes
        </p>
      </div>
      <button type="button" @click="reload" :disabled="pending || saving" class="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-xs font-medium disabled:opacity-40">
        <RefreshCw class="h-4 w-4" /> Actualizar
      </button>
    </div>
    <div v-if="pending" role="status" class="rounded-lg bg-soft p-4 text-sm text-muted">Cargando configuración de la empresa…</div>
    <p v-if="error" role="alert" class="rounded-lg border border-danger p-3 text-sm text-danger">{{ error }}</p>
    <p v-if="notice" role="status" class="rounded-lg border border-border p-3 text-sm">{{ notice }}</p>
    <template v-if="fetched && !pending">
      <label class="flex items-center gap-3 text-sm">
        <input v-model="draft.hide_zero" type="checkbox" :disabled="!canEdit" />
        Ocultar conceptos de detalle sin movimientos en los doce meses
      </label>
      <section>
        <h4 class="mb-2 text-sm font-semibold">Conceptos estándar · nombre y visibilidad</h4>
        <p class="mb-3 text-xs text-muted">No se modifica el código oficial de referencia. Un concepto con importe nunca se oculta, aunque esté marcado.</p>
        <div class="max-h-72 overflow-auto rounded-lg border border-border">
          <table class="w-full min-w-[550px] text-xs">
            <thead class="sticky top-0 bg-soft"><tr><th class="p-2 text-left">Código</th><th class="p-2 text-left">Nombre en esta empresa</th><th class="p-2 text-left">Ocultar si vacío</th></tr></thead>
            <tbody><tr v-for="line in standardLines" :key="line.code" class="border-t border-border">
              <td class="p-2 font-mono">{{ line.code }}</td>
              <td class="p-2"><input :value="draft.labels[line.code] ?? line.label" :disabled="!canEdit"
                @input="draft.labels[line.code] = ($event.target as HTMLInputElement).value"
                class="w-full rounded border border-border bg-surface px-2 py-1.5 text-foreground" :aria-label="'Nombre '+line.code" /></td>
              <td class="p-2 text-center"><input type="checkbox" :disabled="!canEdit"
                :checked="draft.hidden_codes.includes(line.code)"
                @change="toggleHidden(line.code, ($event.target as HTMLInputElement).checked)" /></td>
            </tr></tbody>
          </table>
        </div>
      </section>

      <section>
        <h4 class="mb-2 text-sm font-semibold">Conceptos adicionales para uso interno</h4>
        <p class="mb-3 text-xs text-muted">Usa códigos propios C0001–C9999; no se presentarán como códigos SUNAT.</p>
        <div class="space-y-2">
          <div v-for="(line,index) in draft.custom_lines" :key="line.code" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border p-2 text-xs">
            <span>{{ line.code }} · {{ line.label }} · {{ line.section }} · {{ line.direction }}</span>
            <button type="button" :disabled="!canEdit" @click="draft.custom_lines.splice(index,1)" class="text-danger underline">Quitar</button>
          </div>
        </div>
        <div v-if="canEdit" class="mt-3 flex flex-wrap items-end gap-2">
          <label class="text-xs">Código<input v-model="newLine.code" maxlength="5" placeholder="C0001" class="mt-1 block w-24 rounded border border-border bg-surface p-2" /></label>
          <label class="text-xs">Descripción<input v-model="newLine.label" maxlength="120" placeholder="Cobro de franquicia" class="mt-1 block w-52 rounded border border-border bg-surface p-2" /></label>
          <label class="text-xs">Actividad<select v-model="newLine.section" class="mt-1 block rounded border border-border bg-surface p-2"><option v-for="item in activities" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
          <label class="text-xs">Sentido<select v-model="newLine.direction" class="mt-1 block rounded border border-border bg-surface p-2"><option v-for="item in directions" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
          <button type="button" @click="addLine" class="inline-flex items-center gap-1 rounded border border-border px-3 py-2 text-xs"><Plus class="h-4 w-4"/> Agregar</button>
        </div>
      </section>

      <section v-for="kind in (['account','area'] as const)" :key="kind" class="space-y-2">
        <h4 class="text-sm font-semibold">Reglas por {{ kind === 'account' ? 'cuenta PCGE' : 'área de flujo' }}</h4>
        <p class="text-xs text-muted">
          {{ kind === 'account' ? 'La cuenta debe existir y ser operativa en esta empresa. Se usa solo en asientos con una contrapartida inequívoca.' : 'El área debe existir en la empresa y tener la misma actividad y sentido.' }}
        </p>
        <div v-for="(rule,index) in (kind === 'account' ? draft.account_rules : draft.area_rules)"
          :key="index" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border p-2 text-xs">
          <span>{{ 'account_code' in rule ? rule.account_code : rule.area_code }} · {{ rule.section }} · {{ rule.direction }} → {{ rule.target_code }}</span>
          <button type="button" :disabled="!canEdit" @click="kind === 'account' ? draft.account_rules.splice(index,1) : draft.area_rules.splice(index,1)" class="text-danger underline">Quitar</button>
        </div>
        <div v-if="canEdit" class="flex flex-wrap items-end gap-2">
          <template v-if="kind === 'account'">
            <label class="text-xs">Cuenta PCGE<input v-model="newAccount.account_code" placeholder="121200" class="mt-1 block w-28 rounded border border-border bg-surface p-2" /></label>
            <label class="text-xs">Actividad<select v-model="newAccount.section" class="mt-1 block rounded border border-border bg-surface p-2"><option v-for="item in activities" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
            <label class="text-xs">Sentido<select v-model="newAccount.direction" class="mt-1 block rounded border border-border bg-surface p-2"><option v-for="item in directions" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
            <label class="text-xs">Concepto<select v-model="newAccount.target_code" class="mt-1 block max-w-64 rounded border border-border bg-surface p-2">
              <option v-for="item in eligibleTargets(newAccount.section,newAccount.direction)" :key="item.code" :value="item.code">{{ item.code }} · {{ item.label }}</option>
            </select></label>
          </template>
          <template v-else>
            <label class="text-xs">Área<input v-model="newArea.area_code" placeholder="OPE-IN" class="mt-1 block w-28 rounded border border-border bg-surface p-2" /></label>
            <label class="text-xs">Actividad<select v-model="newArea.section" class="mt-1 block rounded border border-border bg-surface p-2"><option v-for="item in activities" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
            <label class="text-xs">Sentido<select v-model="newArea.direction" class="mt-1 block rounded border border-border bg-surface p-2"><option v-for="item in directions" :key="item.value" :value="item.value">{{ item.label }}</option></select></label>
            <label class="text-xs">Concepto<select v-model="newArea.target_code" class="mt-1 block max-w-64 rounded border border-border bg-surface p-2">
              <option v-for="item in eligibleTargets(newArea.section,newArea.direction)" :key="item.code" :value="item.code">{{ item.code }} · {{ item.label }}</option>
            </select></label>
          </template>
          <button type="button" @click="addRule(kind)" class="inline-flex items-center gap-1 rounded border border-border px-3 py-2 text-xs"><Plus class="h-4 w-4" /> Agregar regla</button>
        </div>
      </section>

      <div v-if="canEdit" class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
        <p class="inline-flex items-start gap-1 text-xs text-muted"><AlertTriangle class="h-4 w-4 shrink-0"/>Los ajustes se aplican a la presentación de informes históricos; ninguna partida registrada se reescribe.</p>
        <button type="button" :disabled="saving" @click="save" class="inline-flex items-center gap-2 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white disabled:opacity-40">
          <Save class="h-4 w-4"/> {{ saving ? 'Guardando...' : 'Guardar configuración' }}
        </button>
      </div>
      <section v-if="fetched.history.length" class="text-xs text-muted">
        <h4 class="mb-2 font-semibold text-foreground">Historial inmutable de cambios</h4>
        <p v-for="record in fetched.history" :key="record.revision" class="py-1">
          Revisión {{ record.revision }} · {{ record.created_at }} · Usuario #{{ record.created_by }}
        </p>
      </section>
    </template>
  </section>
</template>
