<script setup lang="ts">
import { CASH_FLOW_LABELS, CASH_FLOW_REASONS } from '../types/cashFlow.types';
import type { CashFlowReport } from '../types/cashFlow.types';
import { formatExactLocalAmount } from '../utils/formatExactLocalAmount';

const props = defineProps<{ readonly details: CashFlowReport['details']; readonly currency: string }>();
const emit = defineEmits<{ page: [value: number] }>();
const money = (value: string): string => formatExactLocalAmount(value, props.currency);
</script>

<template>
  <div>
    <p v-if="details.total === 0" role="status" class="py-6 text-sm text-muted">Sin movimientos para este filtro.</p>
    <template v-else>
      <ul class="divide-y divide-border sm:hidden">
        <li v-for="row in details.rows" :key="row.id" class="py-4">
          <div class="flex flex-wrap justify-between gap-2 text-sm"><span>{{ row.entry_date }}</span><strong class="font-mono tabular-nums">{{ money(row.amount_local) }}</strong></div>
          <p class="mt-1 break-words text-sm font-medium">{{ row.description }}</p>
          <p class="mt-1 break-words text-xs text-muted">Asiento {{ row.entry_number }} · {{ row.account_code }} · {{ row.account_name }}</p>
          <p class="mt-1 text-xs text-brand">{{ CASH_FLOW_LABELS[row.section] }}<span v-if="row.area_name"> · {{ row.area_name }}</span></p>
          <p v-if="row.reason" class="mt-2 text-xs text-danger">{{ CASH_FLOW_REASONS[row.reason] ?? row.reason }}</p>
          <p v-if="row.entry_type === 'REVERSAL'" class="mt-1 text-xs text-muted">Extorno del movimiento original</p>
        </li>
      </ul>
      <div class="hidden overflow-x-auto sm:block">
        <table class="w-full min-w-[840px] table-fixed text-left text-sm">
          <caption class="sr-only">Partidas de efectivo del período. Importes positivos: entradas; negativos: salidas.</caption>
          <thead class="bg-soft text-xs text-muted"><tr><th scope="col" class="w-[12%] p-3">Fecha / asiento</th><th scope="col" class="w-[21%] p-3">Cuenta de efectivo</th><th scope="col" class="w-[29%] p-3">Glosa</th><th scope="col" class="w-[20%] p-3">Clasificación</th><th scope="col" class="w-[18%] p-3 text-right">Importe {{ currency }}</th></tr></thead>
          <tbody>
            <tr v-for="row in details.rows" :key="row.id" class="border-t border-border align-top">
              <td class="break-words p-3 text-xs">{{ row.entry_date }}<p class="mt-1 font-mono text-muted">{{ row.entry_number }}</p></td>
              <td class="break-words p-3 text-xs"><strong>{{ row.account_code }}</strong><p class="mt-1 text-muted">{{ row.account_name }}</p></td>
              <td class="break-words p-3">{{ row.description }}<p v-if="row.reason" class="mt-2 text-xs text-danger">{{ CASH_FLOW_REASONS[row.reason] ?? row.reason }}</p><p v-if="row.entry_type === 'REVERSAL'" class="mt-1 text-xs text-muted">Extorno</p></td>
              <td class="break-words p-3 text-xs"><span class="text-brand">{{ CASH_FLOW_LABELS[row.section] }}</span><p v-if="row.area_name" class="mt-1 text-muted">{{ row.area_code }} · {{ row.area_name }}</p></td>
              <td class="p-3 text-right font-mono tabular-nums">{{ money(row.amount_local) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4 text-xs text-muted">
      <span>{{ details.total }} partidas · página {{ details.page }} de {{ details.last_page }}</span>
      <div class="flex gap-2">
        <button type="button" :disabled="details.page <= 1" class="rounded-lg border border-border px-3 py-2 text-foreground disabled:opacity-40" @click="emit('page', details.page - 1)">Anterior</button>
        <button type="button" :disabled="details.page >= details.last_page" class="rounded-lg border border-border px-3 py-2 text-foreground disabled:opacity-40" @click="emit('page', details.page + 1)">Siguiente</button>
      </div>
    </div>
  </div>
</template>
