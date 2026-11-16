<script setup lang="ts">
import { computed, onScopeDispose, shallowRef, watch } from 'vue';
import { Printer, RefreshCw } from 'lucide-vue-next';
import { AccountingApiClient } from '../api/httpClient';
import type { CompanyId } from '../types/accountingAccount.types';
import type { TrialBalanceReport, EquityChangesReport } from '../types/financialContinuation.types';
import { formatExactLocalAmount } from '../utils/formatExactLocalAmount';
import { openStatementTablePrint } from '../utils/printStatementTable';
import type { FinancialReportLayoutSettings } from '../types/financialLayout.types';
import { applyFinancialReportLayout } from '../utils/financialLayout';
import FinancialLayoutSettingsPanel from './FinancialLayoutSettingsPanel.vue';

const props=defineProps<{
  kind:'trial'|'equity';activeCompanyId:CompanyId;getAccessToken:()=>string|null;
  companyName:string;companyTaxId:string;asOf:string;canEdit:boolean;
}>();
const emit=defineEmits<{ledger:[id:number]}>();
const trial=shallowRef<TrialBalanceReport|null>(null);
const equity=shallowRef<EquityChangesReport|null>(null);
const pending=shallowRef(false);
const error=shallowRef<string|null>(null);
const printNotice=shallowRef<string|null>(null);
const layout=shallowRef<FinancialReportLayoutSettings|null>(null);
const layoutRevision=shallowRef(0);
const layoutWarning=shallowRef<string|null>(null);
const showEditor=shallowRef(false);
const displayComponents=computed(()=>{
  const components=equity.value?.components.map(x=>({...x,code:x.key}))??[];
  return applyFinancialReportLayout(
    components,layout.value,()=> 'PATRIMONIO',()=>true,
    (row)=>[row.opening,row.opening_entries,row.other_movements,row.closing]
      .every(x=>x==='0.0000'),
  );
});
function saveLayout(settings:FinancialReportLayoutSettings,revision:number){
  layout.value=settings;layoutRevision.value=revision;layoutWarning.value=null;
}
const client=computed(()=>new AccountingApiClient('',{
  getActiveCompanyId:()=>props.activeCompanyId,
  getAccessToken:props.getAccessToken,
}));
let version=0;
const money=(amount:string)=>formatExactLocalAmount(amount,trial.value?.currency??equity.value?.currency??'PEN');
async function load(){
  const v=++version, company=props.activeCompanyId, kind=props.kind, asOf=props.asOf;
  trial.value=null;equity.value=null;layout.value=null;layoutRevision.value=0;error.value=null;printNotice.value=null;pending.value=true;
  try{
    const result=kind==='trial'
      ? await client.value.fetchTrialBalance(asOf.slice(0,4)+'-01-01',asOf)
      : await client.value.fetchEquityChanges(asOf);
    if(v!==version||company!==props.activeCompanyId||kind!==props.kind||asOf!==props.asOf)return;
    if(kind==='trial')trial.value=result as TrialBalanceReport;
    else {
      equity.value=result as EquityChangesReport;
      try {
        const configured=await client.value.fetchFinancialReportLayout('ECP');
        if(v===version&&company===props.activeCompanyId){
          layout.value=configured.data.settings;layoutRevision.value=configured.data.revision;
        }
      }catch {
        if(v===version)layoutWarning.value='No se pudo cargar la personalización ECP. Se usa el formato estándar.';
      }
    }
  }catch(e){
    if(v===version)error.value=e instanceof Error?e.message:'No fue posible consultar este estado.';
  }finally{if(v===version)pending.value=false;}
}
watch([()=>props.activeCompanyId,()=>props.asOf,()=>props.kind],()=>{showEditor.value=false;void load();},{immediate:true,flush:'sync'});
onScopeDispose(()=>{version++;});
function print(){
  const t=trial.value,e=equity.value;
  if(!t&&!e)return;
  const format=(n:string)=>formatExactLocalAmount(n,(t?.currency??e?.currency??'PEN'));
  const data=t?{
    title:'Balance de comprobación · Referencial formato 3.17',
    period:`Del ${t.from} al ${t.to}`,currency:t.currency,
    columns:['Cuenta','Saldo inicial Debe','Saldo inicial Haber','Mov. Debe','Mov. Haber','Saldo final Debe','Saldo final Haber'],
    rows:[...t.rows.map(x=>[
      `${x.code} · ${x.name}`,format(x.opening_debit),format(x.opening_credit),
      format(x.movement_debit),format(x.movement_credit),
      format(x.closing_debit),format(x.closing_credit),
    ]),['TOTALES',...([
      'opening_debit','opening_credit','movement_debit','movement_credit','closing_debit','closing_credit'
    ] as const).map(key=>format(t.totals[key]))]],
    notices:['Solo informe contable; no es archivo PLE Formato 3.17 validado.',
      ...(t.balanced?[]:['Hay diferencias contables pendientes de conciliación.'])],
    landscape:true,
  }:{
    title:'Estado de Cambios en el Patrimonio · Preliminar',
    period:`Del ${e!.from} al ${e!.to}`,currency:e!.currency,
    columns:['Componente','Saldo anterior','Asientos de apertura','Otros movimientos','Saldo al cierre'],
    rows:[...displayComponents.value.map(x=>[
      x.label,format(x.opening),format(x.opening_entries),format(x.other_movements),format(x.closing),
    ]),['Resultado del ejercicio (sin cierre)','—','—',format(e!.period_result),format(e!.period_result)],
      ['Patrimonio total incluido resultado',format(e!.opening_equity),format(e!.opening_entries),
        format(e!.other_equity_movements),format(e!.closing_equity_including_result)]],
    notices:[...e!.warnings,'Resultado anterior no cerrado: '+format(e!.prior_unclosed_result),
      'Faltan notas y conciliación documental de aportes, dividendos, ORI y transacciones con propietarios.'],
    landscape:true,
  };
  const ok=openStatementTablePrint({company:props.companyName,taxId:props.companyTaxId,...data});
  printNotice.value=ok?null:'Habilita las ventanas emergentes para imprimir o guardar como PDF.';
}
</script>

<template>
  <section class="space-y-4">
    <header class="flex flex-wrap items-start justify-between gap-4 border-b border-border pb-4">
      <div>
        <h2 class="text-lg font-semibold">{{ kind==='trial'?'Balance de comprobación':'Estado de Cambios en el Patrimonio' }}</h2>
        <p class="mt-1 text-sm text-muted">Empresa: {{ companyName }} · Corte: {{ asOf }} · Informe preliminar</p>
        <p class="mt-1 text-xs text-muted">{{ kind==='trial'
          ? 'Saldos iniciales, movimientos y saldos finales de todas las cuentas, incluidas cuentas analíticas.'
          : 'Variaciones de patrimonio acreditadas por asientos; aportes, retiros y ORI requieren documentación.'
        }}</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button type="button" @click="load" :disabled="pending"
          class="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm">
          <RefreshCw class="h-4 w-4"/>Actualizar
        </button>
        <button type="button" @click="print" :disabled="pending||(!trial&&!equity)"
          class="inline-flex items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm disabled:opacity-40">
          <Printer class="h-4 w-4"/>PDF preliminar
        </button>
      </div>
    </header>
    <div v-if="kind==='equity' && canEdit" class="flex justify-end">
      <button type="button" @click="showEditor=!showEditor"
        class="rounded-lg border border-border px-3 py-2 text-sm text-brand">
        {{showEditor?'Cerrar editor':'Personalizar estructura ECP de esta empresa'}}
      </button>
    </div>
    <FinancialLayoutSettingsPanel v-if="kind==='equity' && canEdit && showEditor"
      statement="ECP" :active-company-id="activeCompanyId"
      :get-access-token="getAccessToken" :can-edit="canEdit" @saved="saveLayout"/>
    <p v-if="layoutWarning" class="text-xs text-muted">{{ layoutWarning }}</p>
    <p v-if="equity" class="text-xs text-muted">Revisión de estructura ECP: {{layoutRevision}}</p>
    <p v-if="pending" role="status" class="text-sm text-muted">Consultando asientos de esta empresa…</p>
    <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>
    <p v-if="printNotice" role="alert" class="text-sm text-danger">{{ printNotice }}</p>
    <template v-if="trial">
      <p class="text-sm" :class="trial.balanced?'text-emerald-700 dark:text-emerald-300':'text-danger'">
        {{ trial.balanced?'Debe y Haber conciliados':'Diferencias en los saldos o movimientos' }}
        · Inicial {{ money(trial.differences.opening) }}
        · Movimientos {{ money(trial.differences.movements) }}
        · Final {{ money(trial.differences.closing) }}
      </p>
      <div class="overflow-x-auto rounded-xl border border-border bg-surface">
        <table class="w-full min-w-[1050px] border-collapse text-xs tabular-nums">
          <thead class="bg-soft">
            <tr><th rowspan="2" class="p-3 text-left">Cuenta</th>
              <th colspan="2" class="p-2 text-center">Saldo inicial</th>
              <th colspan="2" class="p-2 text-center">Movimiento del período</th>
              <th colspan="2" class="p-2 text-center">Saldo final</th></tr>
            <tr><th class="p-2 text-right">Debe</th><th class="p-2 text-right">Haber</th>
              <th class="p-2 text-right">Debe</th><th class="p-2 text-right">Haber</th>
              <th class="p-2 text-right">Debe</th><th class="p-2 text-right">Haber</th></tr>
          </thead>
          <tbody>
            <tr v-for="row in trial.rows" :key="row.account_id" class="border-t border-border">
              <td class="min-w-56 p-2">
                <button type="button" class="text-left text-brand hover:underline" @click="emit('ledger',row.account_id)">
                  {{ row.code }} · {{ row.name }}
                </button>
              </td>
              <td v-for="key in (['opening_debit','opening_credit','movement_debit','movement_credit','closing_debit','closing_credit'] as const)"
                :key="key" class="p-2 text-right font-mono">{{ money(row[key]) }}</td>
            </tr>
          </tbody>
          <tfoot class="border-t-2 border-border bg-soft font-bold">
            <tr><th class="p-3 text-left">TOTALES</th>
              <td v-for="key in (['opening_debit','opening_credit','movement_debit','movement_credit','closing_debit','closing_credit'] as const)"
                :key="key" class="p-2 text-right font-mono">{{ money(trial.totals[key]) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
      <p class="text-xs text-muted">Formato referencial: antes de generar un TXT PLE 3.17 se requieren validaciones del anexo SUNAT vigente.</p>
    </template>
    <template v-if="equity">
      <div class="overflow-x-auto rounded-xl border border-border bg-surface">
        <table class="w-full min-w-[850px] text-xs tabular-nums">
          <thead class="bg-soft"><tr>
            <th class="p-3 text-left">Componente</th><th class="p-3 text-right">Inicio anterior</th>
            <th class="p-3 text-right">Apertura</th><th class="p-3 text-right">Movimientos</th>
            <th class="p-3 text-right">Final</th></tr></thead>
          <tbody>
            <tr v-for="item in displayComponents" :key="item.key" class="border-t border-border">
              <td class="p-3 font-medium">{{ item.label }}</td>
              <td class="p-3 text-right font-mono">{{ money(item.opening) }}</td>
              <td class="p-3 text-right font-mono">{{ money(item.opening_entries) }}</td>
              <td class="p-3 text-right font-mono">{{ money(item.other_movements) }}</td>
              <td class="p-3 text-right font-mono">{{ money(item.closing) }}</td>
            </tr>
            <tr class="border-t border-border"><th class="p-3 text-left">Utilidad / pérdida del ejercicio sin cierre</th>
              <td class="p-3 text-right">—</td><td class="p-3 text-right">—</td>
              <td class="p-3 text-right font-mono">{{ money(equity.period_result) }}</td>
              <td class="p-3 text-right font-mono">{{ money(equity.period_result) }}</td>
            </tr>
          </tbody>
          <tfoot class="border-t-2 border-border bg-soft font-bold">
            <tr><th class="p-3 text-left">Patrimonio final, incluido resultado</th>
              <td class="p-3 text-right font-mono">{{ money(equity.opening_equity) }}</td>
              <td class="p-3 text-right font-mono">{{ money(equity.opening_entries) }}</td>
              <td class="p-3 text-right font-mono">{{ money(equity.other_equity_movements) }}</td>
              <td class="p-3 text-right font-mono">{{ money(equity.closing_equity_including_result) }}</td></tr>
          </tfoot>
        </table>
      </div>
      <p class="text-sm font-semibold">Resultado acumulado anterior sin cierre: {{ money(equity.prior_unclosed_result) }} · Diferencia del puente: {{ money(equity.bridge_difference) }}</p>
      <div class="rounded-lg border border-border p-3 text-xs text-muted">
        <p v-for="warning in equity.warnings" :key="warning" class="py-1">{{ warning }}</p>
      </div>
    </template>
  </section>
</template>
