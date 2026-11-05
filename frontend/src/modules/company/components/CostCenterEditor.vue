<script setup lang="ts">
import { computed, reactive, shallowRef } from 'vue';
import type { CostCenterAccount, CostCenterInput, CostCenterMaster } from '../types/costCenterMaster.types';
import { costCenterPayload } from '../utils/costCenterDraft';
import UiButton from '@/shared/ui/UiButton.vue';

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
  <form class="workspace-section space-y-3 p-4 sm:p-5" @submit.prevent="submit">
    <h2 class="text-base font-semibold text-foreground">{{ center ? 'Editar centro de costo' : 'Nuevo centro de costo' }}</h2>
    <fieldset :disabled="saving" class="grid gap-3 sm:grid-cols-2">
      <label class="workspace-field">Código *<input v-model="draft.code" required maxlength="20" class="mt-1 block w-full rounded-md border border-line bg-surf px-3 py-2 text-foreground" /></label>
      <label class="workspace-field">Nombre *<input v-model="draft.name" required maxlength="150" class="mt-1 block w-full rounded-md border border-line bg-surf px-3 py-2 text-foreground" /></label>
      <label class="workspace-field">Cuenta de destino · Debe *
        <select v-model.number="draft.destination_debit_account_id" required class="mt-1 block w-full rounded-md border border-line bg-surf px-3 py-2 text-foreground">
          <option :value="0" disabled>Seleccionar cuenta</option>
          <option v-if="missingDebit && center" :value="center.destination_debit_account_id">{{ center.destination_debit_account?.code ?? center.destination_debit_account_id }} · Actual, no disponible para nuevas asignaciones</option>
          <option v-for="a in debits" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
        </select>
      </label>
      <label class="workspace-field">Contrapartida · Haber *
        <select v-model.number="draft.destination_credit_account_id" required class="mt-1 block w-full rounded-md border border-line bg-surf px-3 py-2 text-foreground">
          <option :value="0" disabled>Seleccionar cuenta</option>
          <option v-if="missingCredit && center" :value="center.destination_credit_account_id">{{ center.destination_credit_account?.code ?? center.destination_credit_account_id }} · Actual, no disponible para nuevas asignaciones</option>
          <option v-for="a in credits" :key="a.id" :value="a.id">{{ a.code }} · {{ a.name }}</option>
        </select>
      </label>
      <p class="text-xs text-muted sm:col-span-2">Los destinos con movimientos o rutas registradas se conservan. Para usar otro destino, cree un centro nuevo. La distribución automática requiere una ruta aprobada para este centro.</p>
      <p v-if="debits.length === 0 || credits.length === 0" role="status" class="rounded-md border border-warn/30 bg-warn/5 p-2 text-sm text-warn sm:col-span-2">Faltan cuentas operativas de destino en esta empresa. Revise su plan de cuentas antes de crear el centro.</p>
      <p v-if="localError || error" role="alert" class="rounded-md border border-neg/30 bg-neg/5 p-2 text-sm text-neg sm:col-span-2">{{ localError || error }}</p>
      <div class="flex justify-end gap-2 sm:col-span-2">
        <UiButton variant="secondary" :disabled="saving" @click="emit('cancel')">Cancelar</UiButton>
        <UiButton variant="primary" type="submit" :loading="saving">{{ saving ? 'Guardando…' : 'Guardar centro' }}</UiButton>
      </div>
    </fieldset>
  </form>
</template>
