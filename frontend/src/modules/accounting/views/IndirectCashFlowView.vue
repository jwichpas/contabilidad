<script setup lang="ts">
import { computed, onScopeDispose, ref, shallowRef, watch } from 'vue';
import { Printer, RefreshCw } from 'lucide-vue-next';
import type { CompanyId } from '../types/accountingAccount.types';
import type { IndirectCashFlowReport } from '../types/financialContinuation.types';
import { AccountingApiClient } from '../api/httpClient';
import { formatExactLocalAmount } from '../utils/formatExactLocalAmount';
import { openStatementTablePrint } from '../utils/printStatementTable';

const props=defineProps<{
  activeCompanyId:CompanyId;getAccessToken:()=>string|null;
  companyName:string;companyTaxId:string;
}>();
const today=new Date();
const asOf=ref(`${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`);
const from=ref(`${today.getFullYear()}-01-01`);
const report=shallowRef<IndirectCashFlowReport|null>(null);
const pending=shallowRef(false);
const error=shallowRef<string|null>(null);
const printNotice=shallowRef<string|null>(null);
const client=computed(()=>new AccountingApiClient('',{
  getAccessToken:props.getAccessToken,getActiveCompanyId:()=>props.activeCompanyId,
}));
const names:Readonly<Record<keyof IndirectCashFlowReport['adjustments'],string>>={
  net_result:'Utilidad / pérdida neta del ejercicio',
  noncash_depreciation:'Más: depreciación identificada (684)',
  receivables:'Variación de cuentas por cobrar',
  inventory:'Variación de existencias',
  trade_payables:'Variación de obligaciones operativas y proveedores',
  tax_liabilities:'Variación neta de tributos por pagar / crédito',
  other_working_capital:'Otros ajustes comprobados de capital de trabajo',
};
const adjustmentKeys=Object.keys(names) as (keyof IndirectCashFlowReport['adjustments'])[];
let version=0;
const money=(n:string)=>formatExactLocalAmount(n,report.value?.currency??'PEN');
async function load(){
  const v=++version, company=props.activeCompanyId, start=from.value, end=asOf.value;
  report.value=null;error.value=null;printNotice.value=null;pending.value=true;
  try {
    const data=await client.value.fetchIndirectCashFlow(start,end);
    if(v===version&&company===props.activeCompanyId&&start===from.value&&end===asOf.value)report.value=data;
  }catch(e){if(v===version)error.value=e instanceof Error?e.message:'No se pudo elaborar el flujo indirecto.';}
  finally{if(v===version)pending.value=false;}
}
watch(()=>props.activeCompanyId,()=>{void load();},{immediate:true,flush:'sync'});
onScopeDispose(()=>{version++;});
function print(){
  const r=report.value;if(!r)return;
  const rows=[
    ...adjustmentKeys.map(key=>[names[key],money(r.adjustments[key])]),
    ['FLUJO NETO DE OPERACIÓN, MÉTODO INDIRECTO',money(r.operating_indirect)],
    ['Flujo de inversión',money(r.investing_local)],
    ['Flujo de financiación',money(r.financing_local)],
    ['Efecto de cambio identificado',money(r.exchange_effect_local)],
    ['Otros ajustes identificados en el efectivo',money(r.other_adjustments_local)],
    ['Efectivo inicial',money(r.opening_local)],
    ['EFECTIVO FINAL INDIRECTO',money(r.closing_indirect_local)],
    ['Efectivo final en libros',money(r.closing_book_local)],
    ['Diferencia con libro mayor',money(r.closing_difference)],
    ['Diferencia con operación por método directo',money(r.operating_difference)],
  ];
  const ok=openStatementTablePrint({
    company:props.companyName,taxId:props.companyTaxId,title:'Flujo de efectivo · Método indirecto',
    period:`${r.from} al ${r.to}`,currency:r.currency,
    columns:['Concepto','Monto'],rows,notices:[...r.warnings],landscape:false,
  });
  printNotice.value=ok?null:'Permite ventanas emergentes para imprimir o guardar como PDF.';
}
</script>

<template>
  <section class="space-y-4">
    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border pb-4">
      <div>
        <h2 class="text-lg font-semibold">Flujo de Efectivo · Método indirecto</h2>
        <p class="mt-1 text-sm text-muted">NIC 7 · Utilidad ajustada por efectos no monetarios y variaciones del capital de trabajo.</p>
      </div>
      <form class="flex flex-wrap items-end gap-2" @submit.prevent="load">
        <label class="text-xs">Desde<input v-model="from" type="date" required
          :max="asOf" class="mt-1 block rounded border border-border bg-surface px-2 py-2"/></label>
        <label class="text-xs">Hasta<input v-model="asOf" type="date" required
          :min="from" class="mt-1 block rounded border border-border bg-surface px-2 py-2"/></label>
        <button type="submit" :disabled="pending"
          class="inline-flex items-center gap-1 rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">
          <RefreshCw class="h-4 w-4"/>Consultar
        </button>
        <button type="button" :disabled="pending||!report" @click="print"
          class="inline-flex items-center gap-1 rounded-lg border border-border px-3 py-2 text-sm disabled:opacity-50">
          <Printer class="h-4 w-4"/>PDF indirecto
        </button>
      </form>
    </header>
    <p v-if="pending" class="text-sm text-muted" role="status">Conciliando el Libro Diario…</p>
    <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>
    <p v-if="printNotice" class="text-sm text-danger" role="alert">{{ printNotice }}</p>
    <template v-if="report">
      <p class="rounded-lg border border-border p-3 text-sm" :class="report.reconciled?'text-emerald-700 dark:text-emerald-300':'text-danger'">
        {{ report.reconciled?'Conciliado con método directo y efectivo en libros':'Conciliación pendiente' }}
        · Diferencia operación: {{ money(report.operating_difference) }}
        · Diferencia efectivo final: {{ money(report.closing_difference) }}
      </p>
      <div class="overflow-x-auto rounded-xl border border-border bg-surface">
        <table class="w-full min-w-[450px] text-sm tabular-nums">
          <thead class="bg-soft"><tr><th class="p-3 text-left">Concepto</th><th class="p-3 text-right">{{ report.currency }}</th></tr></thead>
          <tbody>
            <tr v-for="key in adjustmentKeys" :key="key" class="border-t border-border">
              <td class="p-3">{{ names[key] }}</td><td class="p-3 text-right font-mono">{{ money(report.adjustments[key]) }}</td>
            </tr>
            <tr class="border-t-2 border-border bg-soft font-semibold"><th class="p-3 text-left">FLUJO NETO OPERATIVO · INDIRECTO</th><td class="p-3 text-right font-mono">{{ money(report.operating_indirect) }}</td></tr>
            <tr v-for="line in [
              {label:'Actividades de inversión',value:report.investing_local},
              {label:'Actividades de financiación',value:report.financing_local},
              {label:'Efecto de tipo de cambio',value:report.exchange_effect_local},
              {label:'Otros ajustes de efectivo',value:report.other_adjustments_local},
              {label:'Efectivo inicial',value:report.opening_local},
              {label:'Efectivo final calculado',value:report.closing_indirect_local},
              {label:'Efectivo final en libros',value:report.closing_book_local},
            ]" :key="line.label" class="border-t border-border"><td class="p-3">{{ line.label }}</td>
              <td class="p-3 text-right font-mono">{{ money(line.value) }}</td></tr>
          </tbody>
        </table>
      </div>
      <p v-for="w in report.warnings" :key="w" class="text-xs text-muted">{{ w }}</p>
      <p class="text-xs text-muted">Reporte interno preliminar, distinto de archivo SUNAT 3.25. Las diferencias no se ajustan automáticamente.</p>
    </template>
  </section>
</template>
