<script setup lang="ts">
import { computed } from 'vue';
import type { FinancialSnapshot, FinancialSnapshotAccount } from '../types/financialSnapshot.types';
import {
  buildComprehensiveIncomePresentation, formatIncomeAmount,
  formatIncomePercent, formatIncomePeriod,
} from '../utils/comprehensiveIncomePresentation';
import { currencyPositionLabel } from '../utils/financialPositionPresentation';

const props = defineProps<{
  readonly snapshot: FinancialSnapshot;
  readonly companyName: string;
  readonly companyTaxId: string;
  readonly companyAddress: string;
}>();
const emit = defineEmits<{openLedger: [account: FinancialSnapshotAccount]}>();
const report = computed(() => buildComprehensiveIncomePresentation(props.snapshot));
const byAccountId = computed(() => new Map(props.snapshot.accounts.map((a) => [a.id,a])));
const sales = computed(() => report.value.lines.find((line) => line.key === 'net_sales')?.amount ?? '0.0000');
function open(accountId: number): void {
  const account = byAccountId.value.get(accountId);
  if (account) emit('openLedger',account);
}
const format = formatIncomeAmount;
const percentage = formatIncomePercent;
</script>

<template>
  <article class="overflow-hidden rounded-xl border border-border bg-surface text-foreground"
    aria-label="Estado de resultados integrales">
    <header class="border-b border-border px-5 py-5 sm:px-7">
      <h2 class="break-words text-sm font-bold uppercase">{{ companyName }}</h2>
      <p class="mt-1 text-xs font-semibold">RUC {{ companyTaxId }}</p>
      <p v-if="companyAddress" class="mt-1 text-xs font-medium">{{ companyAddress }}</p>
      <h3 class="mt-5 text-base font-bold">Estado de Resultados Integrales</h3>
      <p class="text-sm">{{ formatIncomePeriod(report.from, report.to) }}</p>
      <p class="mt-1 text-xs text-muted">{{ currencyPositionLabel(report.currency) }} · cifras presentadas a dos decimales</p>
      <p class="mt-2 text-xs text-muted">Preliminar · Presentación por función · Otros resultados integrales pendientes de validación</p>
    </header>

    <div class="max-w-full overflow-x-auto px-4 py-5 sm:px-7">
      <table class="w-full min-w-[625px] border-collapse text-xs tabular-nums">
        <colgroup>
          <col />
          <col class="w-16"/>
          <col class="w-36"/>
          <col class="w-24"/>
        </colgroup>
        <thead class="border-b border-border">
          <tr>
            <th scope="col" class="px-2 py-3 text-left font-semibold">Concepto</th>
            <th scope="col" class="px-2 py-3 text-center font-semibold">Nota</th>
            <th scope="col" class="px-2 py-3 text-right font-semibold">{{ report.to.slice(0,4) }}<span class="block text-[10px] text-muted">{{ report.currency }}</span></th>
            <th scope="col" class="px-2 py-3 text-right font-semibold">% ventas netas</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="line in report.lines" :key="line.key"
            :class="{
              'border-t border-border bg-soft font-bold': line.kind === 'subtotal',
              'border-t-2 border-border bg-soft font-bold': line.kind === 'total',
              'text-muted': line.kind === 'warning',
            }">
            <td class="px-2 py-2.5 align-top">
              <details v-if="line.accountIds.length" class="group">
                <summary class="cursor-pointer list-none hover:text-brand">
                  {{ line.label }} <span class="ml-1 text-[10px] text-muted group-open:hidden">▸</span>
                </summary>
                <div class="mt-2 space-y-1 border-l border-border pl-3">
                  <button v-for="accountId in line.accountIds" :key="accountId" type="button"
                    class="block max-w-full text-left text-[11px] font-normal text-brand hover:underline"
                    @click="open(accountId)">
                    {{ byAccountId.get(accountId)?.code }} · {{ byAccountId.get(accountId)?.name }}
                  </button>
                </div>
              </details>
              <span v-else>{{ line.label }}</span>
            </td>
            <td class="px-2 py-2.5 text-center text-muted">—</td>
            <td class="px-2 py-2.5 text-right font-mono" :class="line.amount?.startsWith('-') ? 'text-red-600 dark:text-red-400' : ''">
              {{ format(line.amount) }}
            </td>
            <td class="px-2 py-2.5 text-right font-mono text-muted" :class="line.amount?.startsWith('-') ? 'text-red-600 dark:text-red-400' : ''">
              {{ percentage(line.amount,sales) }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <footer class="space-y-2 border-t border-border px-5 py-4 text-xs text-muted sm:px-7">
      <p>Nota «—»: aún no hay una nota explicativa aprobada y asociada al concepto.</p>
      <p v-for="warning in report.warnings" :key="warning">{{ warning }}</p>
    </footer>
  </article>
</template>
