<script setup lang="ts">
import { computed, reactive, shallowRef } from 'vue';
import type { CostCenterAccount, CostCenterInput, CostCenterMaster } from '../types/costCenterMaster.types';
import { costCenterPayload } from '../utils/costCenterDraft';

const props = defineProps<{
  center: CostCenterMaster | null;
  accounts: readonly CostCenterAccount[];
  saving: boolean;
  error: string;
}>();
const emit = defineEmits<{ save: [payload: CostCenterInput]; cancel: [] }>();
const draft = reactive({
  code: props.center?.code ?? '', name: props.center?.name ?? '',
  destination_debit_account_id: props.center?.destination_debit_account_id ?? 0,
  destination_credit_account_id: props.center?.destination_credit_account_id ?? 0,
});
const localError = shallowRef('');
const debits = computed(() => props.accounts.filter(a => a.code === '941100' || a.code === '951100'));
const credits = computed(() => props.accounts.filter(a => a.code === '791000'));
const missingDebit = computed(() => props.center != null && !debits.value.some(a => a.id === props.center?.destination_debit_account_id));
const missingCredit = computed(() => props.center != null && !credits.value.some(a => a.id === props.center?.destination_credit_account_id));
function submit(): void {
  if (props.saving) return;
  localError.value = '';
  try { emit('save', costCenterPayload(draft)); }
  catch (error) { localError.value = error instanceof Error ? error.message : 'Revise los datos.'; }
}
</script>

<template>
  <form class="rounded-lg border border-line bg-surface p-4" @submit.prevent="submit">
    <h2 class="mb-3 font-semibold">{{ center ? 'Editar centro de costo' : 'Nuevo centro de costo' }}</h2>
    <fieldset :disabled="saving" class="grid gap-3 sm:grid-cols-2">
      <label class="text-xs">Código *<input v-model="draft.code" required maxlength="20" class="mt-1 block w-full rounded border border-line bg-background p-2" /></label>
      <label class="text-xs">Nombre *<input v-model="draft.name" required maxlength="150" class="mt-1 block w-full rounded border border-line bg-background p-2" /></label>
      <label class="text-xs">Cuenta de destino · Debe *
        <select v-model.number="draft.destination_debit_account_id" required class="mt-1 block w-full rounded border border-line bg-background p-2">
          <option :value="0" disabled>Seleccionar cuenta</option>
          <option v-if="missingDebit && center" :value="center.destination_debit_account_id">{{ center.destination_debit_account?.code ?? center.destination_debit_account_id }} · Actual, no disponible para nuevas asignaciones</option>
          <option v-for="a in debits" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
        </select>
      </label>
      <label class="text-xs">Contrapartida · Haber *
        <select v-model.number="draft.destination_credit_account_id" required class="mt-1 block w-full rounded border border-line bg-background p-2">
          <option :value="0" disabled>Seleccionar cuenta</option>
          <option v-if="missingCredit && center" :value="center.destination_credit_account_id">{{ center.destination_credit_account?.code ?? center.destination_credit_account_id }} · Actual, no disponible para nuevas asignaciones</option>
          <option v-for="a in credits" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
        </select>
      </label>
      <p class="text-xs text-muted sm:col-span-2">Los destinos con movimientos o rutas registradas se conservan. Para usar otro destino, cree un centro nuevo. La distribución automática requiere una ruta aprobada para este centro.</p>
      <p v-if="debits.length === 0 || credits.length === 0" role="status" class="text-xs text-amber-700 sm:col-span-2">Faltan cuentas operativas de destino en esta empresa. Revise su plan de cuentas antes de crear el centro.</p>
      <p v-if="localError || error" role="alert" class="text-xs text-red-600 sm:col-span-2">{{ localError || error }}</p>
      <div class="flex justify-end gap-2 sm:col-span-2">
        <button type="button" class="rounded border border-line px-3 py-2 text-xs" @click="emit('cancel')">Cancelar</button>
        <button type="submit" class="rounded bg-brand px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">{{ saving ? 'Guardando…' : 'Guardar centro' }}</button>
      </div>
    </fieldset>
  </form>
</template>
