<script setup lang="ts">
import { computed } from 'vue';
import type { FinancialSnapshot, FinancialSnapshotAccount } from '../types/financialSnapshot.types';
import {
  buildFinancialPositionPresentation, currencyPositionLabel,
  formatPositionAmount, formatPositionDate, isPositionZero,
} from '../utils/financialPositionPresentation';

const props = defineProps<{
  readonly snapshot: FinancialSnapshot;
  readonly companyName: string;
  readonly companyTaxId: string;
  readonly companyAddress: string;
}>();
const emit = defineEmits<{ openLedger: [account: FinancialSnapshotAccount] }>();
const model = computed(() => buildFinancialPositionPresentation(props.snapshot));
const accounts = computed(() => new Map(props.snapshot.accounts.map((account) => [account.id, account])));
const year = computed(() => props.snapshot.as_of.slice(0, 4));
const currencyLabel = computed(() => currencyPositionLabel(props.snapshot.currency));
const dateLabel = computed(() => formatPositionDate(props.snapshot.as_of));
const allLiabilities = computed(() => model.value.liabilityGroups);
const onLedger = (accountId: number) => {
  const account = accounts.value.get(accountId);
  if (account) emit('openLedger', account);
};
const format = formatPositionAmount;
const zero = isPositionZero;
</script>

<template>
  <article class="overflow-hidden rounded-xl border border-border bg-surface text-foreground" aria-label="Estado de situación financiera formal">
    <header class="border-b border-border px-5 py-5 sm:px-7">
      <p class="break-words text-sm font-bold uppercase tracking-tight">{{ companyName }}</p>
      <p class="mt-1 text-xs font-semibold">RUC {{ companyTaxId }}</p>
      <p v-if="companyAddress" class="mt-1 break-words text-xs font-medium">{{ companyAddress }}</p>
      <h2 class="mt-5 text-base font-bold">Estado de Situación Financiera</h2>
      <p class="text-sm">{{ dateLabel }}</p>
      <p class="mt-1 text-xs text-muted">{{ currencyLabel }} · Importes presentados a dos decimales</p>
      <p class="mt-2 text-xs text-muted">Preliminar · Saldos contables sin asiento de cierre ni notas NIIF vinculadas</p>
    </header>
    <div class="w-full overflow-x-auto px-3 py-5 sm:px-5">
      <div class="grid min-w-[880px] grid-cols-2 items-start gap-x-7">
        <section aria-label="Activos">
          <table class="w-full table-fixed border-collapse text-[11px] leading-5 tabular-nums">
            <colgroup><col /><col class="w-12" /><col class="w-32" /></colgroup>
            <thead>
              <tr class="border-b border-border">
                <th scope="col" class="px-2 pb-2 text-left text-xs font-bold">Activo</th>
                <th scope="col" class="px-1 pb-2 text-center text-[11px] font-semibold">Notas</th>
                <th scope="col" class="px-2 pb-2 text-right font-semibold">{{ year }}<span class="block text-[10px]">{{ snapshot.currency }}</span></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="group in model.assetGroups" :key="group.key">
                <tr v-if="group.rows.length" class="border-t border-border/50 bg-soft">
                  <th colspan="3" scope="rowgroup" class="px-2 py-2 text-left text-xs font-semibold">{{ group.label }}</th>
                </tr>
                <tr v-for="line in group.rows" :key="line.code" class="align-top">
                  <td class="px-2 py-1">
                    <details v-if="line.accountIds.length" class="group">
                      <summary class="cursor-pointer list-none hover:text-brand" :class="{ 'text-danger': line.requiresReview }">
                        {{ line.label }}<span class="ml-1 text-[9px] text-muted group-open:hidden">▸</span>
                      </summary>
                      <div class="mt-1 space-y-1 border-l border-border pl-2 text-[10px]">
                        <button v-for="id in line.accountIds" :key="id" type="button"
                          class="block max-w-full text-left text-brand underline-offset-2 hover:underline"
                          @click="onLedger(id)">{{ accounts.get(id)?.code }} · {{ accounts.get(id)?.name }}</button>
                      </div>
                    </details>
                    <span v-else :class="{ 'text-danger': line.requiresReview }">{{ line.label }}</span>
                  </td>
                  <td class="px-1 py-1 text-center text-muted">—</td>
                  <td class="px-2 py-1 text-right font-mono" :class="{ 'text-muted': zero(line.value) }">{{ format(line.value) }}</td>
                </tr>
                <tr v-if="group.rows.length" class="border-t border-border">
                  <th colspan="2" scope="row" class="px-2 py-2 text-left font-semibold">Total {{ group.label.toLowerCase() }}</th>
                  <td class="px-2 py-2 text-right font-mono font-bold">{{ format(group.subtotal) }}</td>
                </tr>
              </template>
              <tr class="border-t-2 border-foreground/50">
                <th colspan="2" scope="row" class="px-2 py-3 text-left text-xs font-bold">TOTAL ACTIVO</th>
                <td class="px-2 py-3 text-right font-mono text-xs font-bold">{{ format(model.assetTotal) }}</td>
              </tr>
            </tbody>
          </table>
        </section>

        <section aria-label="Pasivos y patrimonio">
          <table class="w-full table-fixed border-collapse text-[11px] leading-5 tabular-nums">
            <colgroup><col /><col class="w-12" /><col class="w-32" /></colgroup>
            <thead>
              <tr class="border-b border-border">
                <th scope="col" class="px-2 pb-2 text-left text-xs font-bold">Pasivo y Patrimonio</th>
                <th scope="col" class="px-1 pb-2 text-center text-[11px] font-semibold">Notas</th>
                <th scope="col" class="px-2 pb-2 text-right font-semibold">{{ year }}<span class="block text-[10px]">{{ snapshot.currency }}</span></th>
              </tr>
            </thead>
            <tbody>
              <template v-for="group in allLiabilities" :key="group.key">
                <tr v-if="group.rows.length" class="border-t border-border/50 bg-soft">
                  <th colspan="3" scope="rowgroup" class="px-2 py-2 text-left text-xs font-semibold">{{ group.label }}</th>
                </tr>
                <tr v-for="line in group.rows" :key="line.code" class="align-top">
                  <td class="px-2 py-1">
                    <details v-if="line.accountIds.length" class="group">
                      <summary class="cursor-pointer list-none hover:text-brand" :class="{ 'text-danger': line.requiresReview }">
                        {{ line.label }}<span class="ml-1 text-[9px] text-muted group-open:hidden">▸</span>
                      </summary>
                      <div class="mt-1 space-y-1 border-l border-border pl-2 text-[10px]">
                        <button v-for="id in line.accountIds" :key="id" type="button"
                          class="block max-w-full text-left text-brand underline-offset-2 hover:underline"
                          @click="onLedger(id)">{{ accounts.get(id)?.code }} · {{ accounts.get(id)?.name }}</button>
                      </div>
                    </details>
                    <span v-else :class="{ 'text-danger': line.requiresReview }">{{ line.label }}</span>
                  </td>
                  <td class="px-1 py-1 text-center text-muted">—</td>
                  <td class="px-2 py-1 text-right font-mono" :class="{ 'text-muted': zero(line.value) }">{{ format(line.value) }}</td>
                </tr>
                <tr v-if="group.rows.length" class="border-t border-border">
                  <th colspan="2" scope="row" class="px-2 py-2 text-left font-semibold">Total {{ group.label.toLowerCase() }}</th>
                  <td class="px-2 py-2 text-right font-mono font-bold">{{ format(group.subtotal) }}</td>
                </tr>
              </template>
              <tr class="border-t border-border">
                <th colspan="2" scope="row" class="px-2 py-2 text-left font-semibold">TOTAL PASIVO</th>
                <td class="px-2 py-2 text-right font-mono font-bold">{{ format(model.liabilityTotal) }}</td>
              </tr>
              <tr class="border-t border-border/50 bg-soft">
                <th colspan="3" scope="rowgroup" class="px-2 py-2 text-left text-xs font-semibold">{{ model.equityGroup.label }}</th>
              </tr>
              <tr v-for="line in model.equityGroup.rows" :key="line.code" class="align-top">
                <td class="px-2 py-1">
                  <details v-if="line.accountIds.length" class="group">
                    <summary class="cursor-pointer list-none hover:text-brand" :class="{ 'text-danger': line.requiresReview }">
                      {{ line.label }}<span class="ml-1 text-[9px] text-muted group-open:hidden">▸</span>
                    </summary>
                    <div class="mt-1 space-y-1 border-l border-border pl-2 text-[10px]">
                      <button v-for="id in line.accountIds" :key="id" type="button"
                        class="block max-w-full text-left text-brand underline-offset-2 hover:underline"
                        @click="onLedger(id)">{{ accounts.get(id)?.code }} · {{ accounts.get(id)?.name }}</button>
                    </div>
                  </details>
                  <span v-else :class="{ 'text-danger': line.requiresReview }">{{ line.label }}</span>
                </td>
                <td class="px-1 py-1 text-center text-muted">—</td>
                <td class="px-2 py-1 text-right font-mono" :class="{ 'text-muted': zero(line.value) }">{{ format(line.value) }}</td>
              </tr>
              <tr class="border-t border-border">
                <th colspan="2" scope="row" class="px-2 py-2 text-left font-semibold">TOTAL PATRIMONIO</th>
                <td class="px-2 py-2 text-right font-mono font-bold">{{ format(model.equityTotal) }}</td>
              </tr>
              <tr class="border-t-2 border-foreground/50">
                <th colspan="2" scope="row" class="px-2 py-3 text-left text-xs font-bold">TOTAL PASIVO Y PATRIMONIO</th>
                <td class="px-2 py-3 text-right font-mono text-xs font-bold">{{ format(model.liabilityAndEquityTotal) }}</td>
              </tr>
            </tbody>
          </table>
        </section>
      </div>
    </div>
    <footer class="space-y-2 border-t border-border px-5 py-4 text-xs text-muted sm:px-7">
      <p class="flex flex-wrap items-center gap-2">
        <strong class="text-foreground">Diferencia de presentación (sin redondear):</strong>
        <span class="font-mono font-semibold" :class="zero(model.difference) ? 'text-foreground' : 'text-danger'">
          {{ model.difference }} {{ snapshot.currency }}
        </span>
        <span v-if="zero(model.difference)">· Activo = Pasivo + Patrimonio</span>
      </p>
      <p>Notas: «—» indica que aún no existe una nota explicativa aprobada y vinculada al rubro.</p>
      <p v-for="warning in model.warnings" :key="warning">{{ warning }}</p>
    </footer>
  </article>
</template>
