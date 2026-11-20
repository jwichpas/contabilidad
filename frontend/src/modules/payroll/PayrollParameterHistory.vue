<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ApiHttpError } from '@/modules/accounting/api/httpClient';
import { formatExactLocalAmount } from '@/modules/accounting/utils/formatExactLocalAmount';
import type { PayrollAfpRates, PayrollParameterEntry, PayrollParameterKind, PayrollParameterRevision } from './payroll.types';

const props = defineProps<{
  month: string;
  loadHistory: () => Promise<PayrollParameterRevision[]>;
  saveParameter: (entry: PayrollParameterEntry, revision: number) => Promise<void>;
}>();

const labels = { rmv: 'RMV', uit: 'UIT', afp: 'Tasas AFP' } as const;
const kinds = ['rmv', 'uit', 'afp'] as const;
const administrators = [['HA', 'Hábitat'], ['IN', 'Integra'], ['PR', 'Profuturo'], ['RI', 'Prima']] as const;
const history = ref<PayrollParameterRevision[]>([]);
const draft = ref<PayrollParameterEntry>();
const baseRevision = ref<PayrollParameterRevision>();
const busy = ref(false);
const failedLoad = ref(false);
const error = ref('');
const confirmation = ref('');
const latest = computed(() => {
  const entries = new Map<string, PayrollParameterRevision>();
  for (const entry of history.value) {
    if (!entries.has(entry.reference)) entries.set(entry.reference, entry);
  }
  return entries;
});
const existing = computed(() => draft.value && latest.value.get(`${draft.value.kind}:${draft.value.period}`));

function emptyRates(): PayrollAfpRates {
  return {
    contribution: '', insurance: '', insurance_ceiling: '',
    administrators: { HA: { flow: '', balance: '' }, IN: { flow: '', balance: '' }, PR: { flow: '', balance: '' }, RI: { flow: '', balance: '' } },
  };
}

function start(kind: PayrollParameterKind) {
  baseRevision.value = undefined;
  const fields = { period: kind === 'uit' ? props.month.slice(0, 4) : props.month, source: '', url: '' };
  draft.value = kind === 'afp' ? { kind, ...fields, ...emptyRates() } : { kind, ...fields, amount: '' };
  error.value = '';
  confirmation.value = '';
}

function correct(revision: PayrollParameterRevision) {
  baseRevision.value = revision;
  const data = revision.data;
  draft.value = data.kind === 'afp' ? {
    ...data,
    administrators: {
      HA: { ...data.administrators.HA }, IN: { ...data.administrators.IN },
      PR: { ...data.administrators.PR }, RI: { ...data.administrators.RI },
    },
  } : { ...data };
  error.value = '';
  confirmation.value = '';
}

function money(amount: string) {
  return formatExactLocalAmount(amount, 'PEN').replace(/^PEN (.*,[0-9]{2})00$/, 'S/ $1');
}

function readableTimestamp(created: string) {
  return new Intl.DateTimeFormat('es-PE', {
    dateStyle: 'short', timeStyle: 'short', timeZone: 'America/Lima',
  }).format(new Date(created));
}

async function refresh() {
  busy.value = true;
  error.value = '';
  failedLoad.value = false;
  try {
    history.value = await props.loadHistory();
  } catch {
    failedLoad.value = true;
    error.value = 'No se pudo cargar el historial de parámetros.';
  } finally {
    busy.value = false;
  }
}

async function publish() {
  const entry = draft.value;
  if (!entry || (!baseRevision.value && existing.value) || busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    await props.saveParameter(entry, baseRevision.value?.revision ?? 0);
    draft.value = undefined;
    baseRevision.value = undefined;
    confirmation.value = 'Versión registrada.';
    await refresh();
  } catch (reason) {
    error.value = reason instanceof ApiHttpError && reason.status < 500
      ? (Object.values(reason.validationErrors).flat()[0] ?? reason.message)
      : 'No se pudo registrar la versión.';
  } finally {
    busy.value = false;
  }
}

onMounted(refresh);
</script>

<template>
  <section aria-labelledby="payroll-history-title" class="space-y-4 rounded-lg border border-line bg-surf p-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 id="payroll-history-title" class="font-semibold">Vigencias e historial</h2>
        <p class="text-sm text-muted">RMV por mes de vigencia, UIT por ejercicio y AFP por devengue. Los cambios se registran en la empresa activa.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button v-for="kind in kinds" :key="kind" type="button" class="ui-btn ui-btn-sm ui-btn-secondary"
          :disabled="busy || failedLoad" @click="start(kind)">Registrar {{ labels[kind] }}</button>
      </div>
    </div>
    <p v-if="busy" role="status" class="text-sm text-muted">Procesando parámetros…</p>
    <p v-if="error" role="alert" class="text-sm text-neg">
      {{ error }}
      <button v-if="failedLoad" type="button" class="ml-2 underline" @click="refresh">Reintentar</button>
      <button v-else-if="draft" type="button" class="ml-2 underline" @click="refresh">Actualizar historial</button>
    </p>
    <p v-if="confirmation" role="status" class="text-sm text-pos">{{ confirmation }}</p>

    <form v-if="draft" class="space-y-3 rounded-lg border border-line bg-soft p-3" @submit.prevent="publish">
      <h3 class="font-semibold">{{ baseRevision ? 'Nueva revisión' : 'Nueva vigencia' }} · {{ labels[draft.kind] }}</h3>
      <fieldset :disabled="busy" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <label v-if="draft.kind === 'uit'" class="ui-label">Ejercicio
          <input v-model.trim="draft.period" type="text" inputmode="numeric" pattern="20[0-9]{2}" maxlength="4" :readonly="!!baseRevision" required>
        </label>
        <label v-else class="ui-label">{{ draft.kind === 'rmv' ? 'Vigente desde' : 'Mes de devengue' }}
          <input v-model="draft.period" type="month" min="2000-01" max="2099-12" :readonly="!!baseRevision" required>
        </label>
        <label v-if="draft.kind !== 'afp'" class="ui-label">Importe en soles
          <input v-model.trim="draft.amount" inputmode="decimal" pattern="[0-9]{1,9}(\.[0-9]{1,2})?" required>
        </label>
        <template v-else>
          <label class="ui-label">Aporte obligatorio al fondo (%)
            <input v-model.trim="draft.contribution" inputmode="decimal" required>
          </label>
          <label class="ui-label">Prima de seguro (%)
            <input v-model.trim="draft.insurance" inputmode="decimal" required>
          </label>
          <label class="ui-label">Tope asegurable (S/)
            <input v-model.trim="draft.insurance_ceiling" inputmode="decimal" required>
          </label>
          <div v-for="[code, name] in administrators" :key="code" class="sm:col-span-2 lg:col-span-3 grid gap-2 sm:grid-cols-[7rem_1fr_1fr] sm:items-end">
            <p class="py-1 text-sm font-semibold">{{ name }}</p>
            <label class="ui-label">Flujo mensual (%)<input v-model.trim="draft.administrators[code].flow" inputmode="decimal" required></label>
            <label class="ui-label">Saldo anual (%)<input v-model.trim="draft.administrators[code].balance" inputmode="decimal" required></label>
          </div>
        </template>
        <label class="ui-label">Norma, publicación o referencia<input v-model.trim="draft.source" maxlength="250" required></label>
        <label class="ui-label sm:col-span-2">Enlace a la fuente (opcional)
          <input v-model.trim="draft.url" type="url" maxlength="500" placeholder="https://">
        </label>
      </fieldset>
      <p v-if="existing && !baseRevision" class="text-sm text-warn">
        Ya existe una versión para ese periodo.
        <button type="button" class="font-medium underline" @click="correct(existing)">Preparar nueva revisión</button>
      </p>
      <div class="flex flex-wrap gap-2">
        <button type="submit" class="ui-btn ui-btn-sm ui-btn-primary" :disabled="busy || (!!existing && !baseRevision)">Guardar versión</button>
        <button type="button" class="ui-btn ui-btn-sm ui-btn-ghost" :disabled="busy" @click="draft = undefined">Cancelar</button>
      </div>
    </form>

    <div v-if="history.length" class="divide-y divide-line">
      <details v-for="revision in history" :key="`${revision.reference}:${revision.revision}`" class="py-2">
        <summary class="cursor-pointer text-sm">
          <span class="font-semibold">{{ labels[revision.data.kind] }} · {{ revision.data.period }}</span>
          <span class="ml-2 text-muted">{{ revision.revision ? 'Revisión ' + revision.revision : 'Referencia inicial' }}</span>
          <span v-if="revision.data.kind !== 'afp'" class="ml-2 font-medium tabular-nums">{{ money(revision.data.amount) }}</span>
        </summary>
        <div class="space-y-2 px-2 py-2 text-sm">
          <p v-if="revision.created_at" class="text-xs text-muted">
            Registrado el {{ readableTimestamp(revision.created_at) }} · Usuario #{{ revision.created_by }}
          </p>
          <a v-if="revision.data.url" class="text-brand underline wrap-anywhere" :href="revision.data.url" target="_blank" rel="noopener noreferrer">{{ revision.data.source }}</a>
          <p v-else class="wrap-anywhere">{{ revision.data.source }}</p>
          <template v-if="revision.data.kind === 'afp'">
            <p>Aporte {{ revision.data.contribution }}% · Prima {{ revision.data.insurance }}% · Tope {{ money(revision.data.insurance_ceiling) }}</p>
            <dl class="divide-y divide-line">
              <div v-for="[code, name] in administrators" :key="code" class="flex flex-wrap justify-between gap-2 py-1">
                <dt>{{ name }}</dt><dd>Flujo {{ revision.data.administrators[code].flow }}% · Saldo {{ revision.data.administrators[code].balance }}%</dd>
              </div>
            </dl>
          </template>
          <button v-if="latest.get(revision.reference)?.revision === revision.revision" type="button"
            class="ui-btn ui-btn-sm ui-btn-secondary" :disabled="busy" @click="correct(revision)">Nueva revisión</button>
        </div>
      </details>
    </div>
  </section>
</template>
