<script setup lang="ts">
import { computed, onScopeDispose, ref, shallowRef, watch } from 'vue';
import { ArrowDown, ArrowUp, RefreshCw, Save, ShieldCheck } from 'lucide-vue-next';
import { AccountingApiClient } from '../api/httpClient';
import type { CompanyId } from '../types/accountingAccount.types';
import type {
  FinancialReportCode, FinancialReportLayoutResponse, FinancialReportLayoutSettings,
} from '../types/financialLayout.types';

const props = defineProps<{
  readonly statement: FinancialReportCode;
  readonly activeCompanyId: CompanyId;
  readonly canEdit: boolean;
  readonly getAccessToken: () => string | null;
}>();
const emit = defineEmits<{ saved: [settings: FinancialReportLayoutSettings, revision: number] }>();
type Draft = {
  labels: Record<string,string>;
  hidden_codes: string[];
  line_order: string[];
  hide_zero: boolean;
};
const empty = (): Draft => ({ labels:{},hidden_codes:[],line_order:[],hide_zero:false });
const current = shallowRef<FinancialReportLayoutResponse | null>(null);
const draft = ref<Draft>(empty());
const pending = shallowRef(false);
const saving = shallowRef(false);
const error = shallowRef<string | null>(null);
const message = shallowRef<string | null>(null);
let requestId = 0;
const client = computed(() => new AccountingApiClient('', {
  getActiveCompanyId: () => props.activeCompanyId,
  getAccessToken: props.getAccessToken,
}));
const title = computed(() => ({
  ESF:'Estado de Situación Financiera', ERI:'Estado de Resultados Integrales',
  EFE:'Flujo de Efectivo', ECP:'Cambios en el Patrimonio',
})[props.statement]);
const standard = computed(() => Object.entries(current.value?.catalog ?? {})
  .filter(([,line]) => line.kind === 'detail'));
const groups = computed(() => {
  const grouped = new Map<string, Array<{ code:string; label:string }>>();
  for (const [code, item] of standard.value) {
    const group = item.group;
    grouped.set(group, [...(grouped.get(group) ?? []), {code,label:item.label}]);
  }
  const index = new Map(draft.value.line_order.map((code, i) => [code,i]));
  return [...grouped].map(([name,lines]) => ({
    name,
    rows: [...lines].sort((a,b) =>
      (index.get(a.code) ?? Number.MAX_SAFE_INTEGER)
        - (index.get(b.code) ?? Number.MAX_SAFE_INTEGER)),
  }));
});
function clone(source: FinancialReportLayoutSettings): Draft {
  return {
    labels: {...source.labels},hidden_codes:[...source.hidden_codes],
    line_order:[...source.line_order],hide_zero:source.hide_zero,
  };
}
async function reload(): Promise<void> {
  const id = ++requestId;
  const company = props.activeCompanyId;
  const statement = props.statement;
  pending.value = true;error.value = null;message.value = null;
  current.value = null;draft.value = empty();
  try {
    const data = await client.value.fetchFinancialReportLayout(statement);
    if (id === requestId && company === props.activeCompanyId && statement === props.statement) {
      current.value = data;
      draft.value = clone(data.data.settings);
    }
  } catch(cause) {
    if (id === requestId && company === props.activeCompanyId && statement === props.statement) {
      error.value = cause instanceof Error ? cause.message : 'No se pudo recuperar el diseño de la empresa.';
    }
  } finally { if (id === requestId) pending.value = false; }
}
function toggle(code: string, yes: boolean) {
  draft.value.hidden_codes = yes
    ? [...new Set([...draft.value.hidden_codes,code])]
    : draft.value.hidden_codes.filter((x) => x !== code);
}
function move(group: string, code: string, direction: -1 | 1): void {
  const codes = groups.value.find((item) => item.name === group)?.rows.map((item) => item.code) ?? [];
  const at = codes.indexOf(code);
  if (at < 0 || at + direction < 0 || at + direction >= codes.length) return;
  [codes[at],codes[at+direction]] = [codes[at+direction]!, codes[at]!];
  const existing = draft.value.line_order.filter((x) =>
    !codes.includes(x) && (current.value?.catalog[x]?.kind === 'detail'));
  draft.value.line_order = [...existing,...codes];
}
async function save(): Promise<void> {
  if (!props.canEdit || saving.value || !current.value) return;
  saving.value = true;error.value = null;message.value = null;
  const company = props.activeCompanyId;
  const statement = props.statement;
  const id = requestId;
  try {
    const result = await client.value.saveFinancialReportLayout(statement,draft.value,current.value.data.revision);
    if (id === requestId && company === props.activeCompanyId && statement === props.statement) {
      emit('saved', result.settings, result.revision);
      await reload();
      if (company === props.activeCompanyId && statement === props.statement) {
        message.value = 'Se guardó la revisión ' + result.revision + ' para esta empresa.';
      }
    }
  } catch(cause) {
    if (id === requestId && company === props.activeCompanyId && statement === props.statement) {
      error.value = cause instanceof Error ? cause.message : 'Error al guardar. Recargue la configuración.';
    }
  } finally { if (company === props.activeCompanyId) saving.value = false; }
}
watch([() => props.activeCompanyId, () => props.statement], () => {
  saving.value = false;void reload();
}, {immediate:true,flush:'sync'});
onScopeDispose(() => { requestId++; });
</script>

<template>
  <section class="space-y-4 rounded-xl border border-border bg-surface p-4 sm:p-5" aria-label="Personalizar estructura financiera">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h3 class="text-base font-semibold">Estructura de {{ title }}</h3>
        <p class="mt-1 text-xs text-muted">
          Configuración exclusiva de la empresa activa. Se permite personalizar detalles;
          los cálculos, grupos, subtotales y saldos están protegidos.
        </p>
      </div>
      <button type="button" @click="reload" :disabled="saving || pending"
        class="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-xs disabled:opacity-40">
        <RefreshCw class="h-4 w-4"/> Recargar
      </button>
    </div>
    <p v-if="error" role="alert" class="rounded-lg border border-danger p-3 text-sm text-danger">{{ error }}</p>
    <p v-if="message" role="status" class="rounded-lg border border-border p-3 text-sm">{{ message }}</p>
    <p v-if="pending" role="status" class="text-sm text-muted">Consultando estructura y permisos de la empresa…</p>
    <template v-if="current && !pending">
      <p class="flex flex-wrap items-center gap-2 text-xs text-muted">
        <ShieldCheck class="h-4 w-4"/> Revisión {{ current.data.revision }} · {{ title }}
        · {{ canEdit ? 'Edición autorizada' : 'Solo lectura' }}
      </p>
      <label class="flex items-center gap-2 text-sm">
        <input v-model="draft.hide_zero" type="checkbox" :disabled="!canEdit" />
        Ocultar detalles sin movimientos (los totales se conservan)
      </label>
      <div v-for="group in groups" :key="group.name" class="overflow-hidden rounded-lg border border-border">
        <h4 class="bg-soft px-3 py-2 text-xs font-semibold">{{ group.name }}</h4>
        <div v-for="(row,index) in group.rows" :key="row.code"
          class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-2 border-t border-border px-3 py-2 sm:grid-cols-[90px_minmax(0,1fr)_auto_auto]">
          <span class="font-mono text-xs text-muted">{{ row.code }}</span>
          <input :value="draft.labels[row.code] ?? row.label" :disabled="!canEdit"
            :aria-label="'Descripción del rubro '+row.code"
            @input="draft.labels[row.code] = ($event.target as HTMLInputElement).value"
            class="w-full min-w-0 rounded-lg border border-border bg-surface px-3 py-2 text-xs" maxlength="120"/>
          <label class="flex items-center gap-1 whitespace-nowrap text-[11px] text-muted">
            <input type="checkbox" :checked="draft.hidden_codes.includes(row.code)" :disabled="!canEdit"
              @change="toggle(row.code,($event.target as HTMLInputElement).checked)" />
            Si vacío
          </label>
          <div class="flex gap-1">
            <button type="button" :disabled="!canEdit || index === 0" @click="move(group.name,row.code,-1)"
              :aria-label="'Subir '+row.code" class="rounded border border-border p-1 disabled:opacity-30">
              <ArrowUp class="h-4 w-4"/>
            </button>
            <button type="button" :disabled="!canEdit || index === group.rows.length-1" @click="move(group.name,row.code,1)"
              :aria-label="'Bajar '+row.code" class="rounded border border-border p-1 disabled:opacity-30">
              <ArrowDown class="h-4 w-4"/>
            </button>
          </div>
        </div>
      </div>
      <div v-if="canEdit" class="flex justify-end">
        <button type="button" :disabled="saving" @click="save"
          class="inline-flex items-center gap-2 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
          <Save class="h-4 w-4"/> {{ saving ? 'Guardando…' : 'Guardar estructura de esta empresa' }}
        </button>
      </div>
      <div v-if="current.history.length" class="text-xs text-muted">
        <h4 class="font-semibold">Historial inmutable</h4>
        <p v-for="item in current.history" :key="item.revision" class="mt-1">
          Revisión {{ item.revision }} · {{ item.created_at }} · usuario #{{ item.created_by }}
        </p>
      </div>
    </template>
  </section>
</template>
