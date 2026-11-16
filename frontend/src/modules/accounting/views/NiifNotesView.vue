<script setup lang="ts">
import { computed, onScopeDispose, ref, shallowRef, watch } from 'vue';
import { Printer, RefreshCw, Save } from 'lucide-vue-next';
import type { CompanyId } from '../types/accountingAccount.types';
import type { FinancialSnapshot } from '../types/financialSnapshot.types';
import type { NiifNotesResponse } from '../types/financialContinuation.types';
import { AccountingApiClient } from '../api/httpClient';
import { formatExactLocalAmount } from '../utils/formatExactLocalAmount';
import { openStatementTablePrint } from '../utils/printStatementTable';

const props=defineProps<{
  activeCompanyId:CompanyId;asOf:string;companyName:string;companyTaxId:string;
  getAccessToken:()=>string|null;canEdit:boolean;snapshot:FinancialSnapshot|null;
}>();
const year=computed(()=>Number(props.asOf.slice(0,4)));
const notes=shallowRef<NiifNotesResponse|null>(null);
const selected=ref('N01');
const draft=ref('');
const pending=shallowRef(false);
const saving=shallowRef(false);
const error=shallowRef<string|null>(null);
const message=shallowRef<string|null>(null);
const client=computed(()=>new AccountingApiClient('',{
  getActiveCompanyId:()=>props.activeCompanyId,getAccessToken:props.getAccessToken,
}));
let requestId=0;
const active=computed(()=>notes.value?.notes.find(n=>n.code===selected.value)??null);
const money=(value:string)=>formatExactLocalAmount(value,props.snapshot?.currency??'PEN');
function signedValue(prefixes:readonly string[],creditNormal:boolean):string|null {
  const source=props.snapshot;
  if(!source)return null;
  let total=0n;
  for(const acc of source.accounts){
    if(!prefixes.some(prefix=>acc.code.startsWith(prefix)))continue;
    const toScaled=(s:string):bigint=>{
      const match=/^(-?)(\d+)(?:\.(\d{1,4}))?$/.exec(s);
      if(!match)throw new Error('Importe inválido de la API');
      const x=BigInt(match[2]!)*10000n+BigInt((match[3]??'').padEnd(4,'0'));
      return match[1]==='-'?-x:x;
    };
    total+=creditNormal
      ? toScaled(acc.credit_local)-toScaled(acc.debit_local)
      : toScaled(acc.debit_local)-toScaled(acc.credit_local);
  }
  const abs=total<0n?-total:total;
  return (total<0n?'-':'')+(abs/10000n)+'.'+(abs%10000n).toString().padStart(4,'0');
}
const evidence=computed(()=>{
  const source=props.snapshot;
  if(!source)return [] as {code:string;label:string;value:string}[];
  const rows=[
    ['N04','Saldo de cuentas 10 (efectivo)',signedValue(['10'],false)],
    ['N05','Saldo contable de cuentas 12 (comerciales)',signedValue(['12'],false)],
    ['N06','Saldo de existencias (20–29)',signedValue(['20','21','22','23','24','25','26','27','28','29'],false)],
    ['N07','Costo neto contable (33 menos 39)',signedValue(['33','39'],false)],
    ['N08','Saldo de obligaciones financieras (45)',signedValue(['45'],true)],
    ['N09','Saldo neto de tributos (40)',signedValue(['40'],true)],
    ['N10','Saldo de cuentas patrimoniales, sin resultado del ejercicio',signedValue(['50','52','56','57','58','59'],true)],
    ['N11','Ventas del ejercicio',source.income_statement.revenue],
    ['N12','Efectivo conciliable con balance',signedValue(['10'],false)],
  ] as const;
  return rows.flatMap(([code,label,value])=>
    value===null?[]:[{code,label,value}]);
});
const selectedEvidence=computed(()=>evidence.value.filter(x=>x.code===selected.value));
async function load(){
  const id=++requestId,tenant=props.activeCompanyId, y=year.value;
  notes.value=null;error.value=null;message.value=null;pending.value=true;draft.value='';
  try{
    const result=await client.value.fetchNiifNotes(y);
    if(id===requestId&&tenant===props.activeCompanyId&&y===year.value){
      notes.value=result;selected.value='N01';
      draft.value=result.notes[0]?.narrative??'';
    }
  }catch(e){if(id===requestId)error.value=e instanceof Error?e.message:'Error al cargar notas NIIF.';}
  finally{if(id===requestId)pending.value=false;}
}
function select(code:string){
  selected.value=code;
  draft.value=notes.value?.notes.find(n=>n.code===code)?.narrative??'';
  message.value=null;error.value=null;
}
async function save(){
  const note=active.value;if(!note||!props.canEdit||saving.value)return;
  const v=requestId,company=props.activeCompanyId,y=year.value;
  saving.value=true;error.value=null;message.value=null;
  try{
    const result=await client.value.saveNiifNote(y,note.code,draft.value,note.revision);
    if(v===requestId&&company===props.activeCompanyId&&year.value===y){
      // Keep the selected code and update only the current version.
      notes.value={...notes.value!,notes:notes.value!.notes.map(row=>
        row.code===note.code?{...row,revision:result.revision,narrative:result.narrative}:row)};
      draft.value=result.narrative;
      message.value='Borrador guardado, revisión '+result.revision+'. Aún no está aprobado.';
    }
  }catch(e){if(v===requestId)error.value=e instanceof Error?e.message:'No se pudo guardar la nota.';}
  finally{if(company===props.activeCompanyId)saving.value=false;}
}
function print(){
  const all=notes.value;if(!all)return;
  const rows=all.notes.map(note=>{
    const figures=evidence.value.filter(item=>item.code===note.code)
      .map(item=>item.label+': '+money(item.value)).join('; ');
    return [note.code+' · '+note.title,
      note.narrative||'PENDIENTE DE DOCUMENTACIÓN CONTABLE',
      figures||'Pendiente de sustento específico',
    ];
  });
  const ok=openStatementTablePrint({
    company:props.companyName,taxId:props.companyTaxId,
    title:'Notas a los Estados Financieros · Borradores NIIF',
    period:'Ejercicio '+year.value+' · corte '+props.asOf,
    currency:props.snapshot?.currency??'Moneda funcional pendiente',
    columns:['Nota','Narrativa de la empresa (borrador)','Cifras de consulta del Libro Diario'],
    rows,
    notices:[
      'Las notas no han pasado revisión, aprobación ni auditoría NIIF.',
      'Sin comparativos, estimaciones verificadas, políticas aprobadas ni información extralibros.',
      'Las cifras son indicadores contables, no determinan por sí solas revelaciones suficientes.',
    ],landscape:true,
  });
  if(!ok)error.value='Permite ventanas emergentes para imprimir o guardar como PDF.';
}
watch([()=>props.activeCompanyId,year],()=>{saving.value=false;void load();},{immediate:true,flush:'sync'});
onScopeDispose(()=>{requestId++;});
</script>

<template>
  <section class="space-y-4">
    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-border pb-4">
      <div>
        <h2 class="text-lg font-semibold">Notas a los Estados Financieros · NIIF</h2>
        <p class="mt-1 text-sm text-muted">Borradores independientes por empresa y ejercicio {{ year }}.</p>
        <p class="mt-1 text-xs text-muted">Las políticas, estimaciones, riesgos y hechos posteriores necesitan evidencia y aprobación profesional.</p>
      </div>
      <div class="flex gap-2">
        <button type="button" :disabled="pending" @click="load"
          class="inline-flex items-center gap-1 rounded-lg border border-border px-3 py-2 text-sm">
          <RefreshCw class="h-4 w-4"/>Actualizar
        </button>
        <button type="button" :disabled="!notes" @click="print"
          class="inline-flex items-center gap-1 rounded-lg border border-border px-3 py-2 text-sm">
          <Printer class="h-4 w-4"/>PDF preliminar
        </button>
      </div>
    </header>
    <p v-if="pending" role="status" class="text-sm text-muted">Consultando los borradores…</p>
    <p v-if="error" role="alert" class="text-sm text-danger">{{ error }}</p>
    <p v-if="message" role="status" class="text-sm text-muted">{{ message }}</p>
    <template v-if="notes">
      <p class="rounded-lg border border-border p-3 text-sm text-muted">
        {{ notes.warning }} · Ninguna de estas notas será emitida como aprobada automáticamente.
      </p>
      <div class="grid gap-4 lg:grid-cols-[minmax(230px,0.95fr)_minmax(0,2fr)]">
        <nav class="max-h-[620px] overflow-y-auto rounded-xl border border-border bg-surface" aria-label="Índice de notas NIIF">
          <button v-for="note in notes.notes" :key="note.code" type="button"
            :aria-current="selected===note.code?'page':undefined"
            class="block w-full border-b border-border p-3 text-left text-xs hover:bg-soft"
            :class="selected===note.code?'bg-soft font-semibold text-brand':'text-foreground'"
            @click="select(note.code)">
            {{ note.code }} · {{ note.title }}
            <span class="mt-1 block text-[11px] text-muted">Revisión {{ note.revision }} · {{ note.narrative?'Con texto':'Sin sustento redactado' }}</span>
          </button>
        </nav>
        <div v-if="active" class="space-y-4 rounded-xl border border-border bg-surface p-4">
          <div>
            <h3 class="font-semibold">{{ active.code }} · {{ active.title }}</h3>
            <p class="mt-1 text-xs text-muted">Estado: BORRADOR · Revisión {{ active.revision }}</p>
          </div>
          <div v-if="selectedEvidence.length" class="space-y-2 rounded-lg bg-soft p-3">
            <h4 class="text-xs font-semibold">Referencias automáticas del Libro Diario, no revelaciones aprobadas</h4>
            <div v-for="row in selectedEvidence" :key="row.label" class="flex flex-wrap justify-between gap-2 text-xs">
              <span>{{ row.label }}</span><strong class="font-mono">{{ money(row.value) }}</strong>
            </div>
          </div>
          <label :for="'niif-note-'+active.code" class="block text-xs font-semibold">Redacción de la nota (sustentada por la empresa)</label>
          <textarea :id="'niif-note-'+active.code" v-model="draft" :disabled="!canEdit" rows="11" maxlength="5000"
            placeholder="Detallar la política o revelación sustentada; no inventar información."
            class="w-full rounded-lg border border-border bg-surface p-3 text-sm leading-6"/>
          <p class="text-xs text-muted">{{ draft.length }} / 5000 caracteres · Solo texto plano; aún falta revisión NIIF y aprobación.</p>
          <button v-if="canEdit" type="button" :disabled="saving||draft===active.narrative" @click="save"
            class="inline-flex items-center gap-2 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
            <Save class="h-4 w-4"/> {{ saving?'Guardando...':'Guardar borrador de esta empresa' }}
          </button>
          <p v-else class="text-xs text-muted">Su rol permite consulta; solo ADMIN o CHIEF_ACCOUNTANT puede guardar.</p>
        </div>
      </div>
    </template>
  </section>
</template>
