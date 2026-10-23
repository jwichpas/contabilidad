<script setup lang="ts">
import { computed, onMounted, ref, reactive, watch } from 'vue';
import { AccountingApiClient, ApiHttpError } from '@/modules/accounting/api/httpClient';
import type { CompanyId } from '@/modules/accounting/types/accountingAccount.types';
import type { CompanyFiscalProfile, CreateCompanyFiscalProfilePayload, FiscalLegalForm, FiscalTaxRegime } from '../types/companyFiscalProfile.types';

const props = defineProps<{ activeCompanyId: CompanyId; getAccessToken: () => string | null; canWrite: boolean }>();
const api = computed(() => new AccountingApiClient('', {
  getAccessToken: props.getAccessToken, getActiveCompanyId: () => props.activeCompanyId,
}));
const year = ref(new Date().getFullYear());
const profiles = ref<readonly CompanyFiscalProfile[]>([]);
const loading = ref(false), saving = ref(false), editing = ref(false), confirmed = ref(false);
const error = ref(''), success = ref(''), formError = ref('');
let sequence = 0;
const form = reactive({
  effective_date: '', tax_regime: 'GENERAL' as FiscalTaxRegime, legal_form: 'SAC' as FiscalLegalForm,
  income_reference_year: '', annual_income: '', default_vat_rate: '18.00',
  is_retention_agent: false, is_perception_agent: false, is_good_taxpayer: false,
  is_sire_obliged: true, is_ple_obliged: false, change_reason: '',
});
const regimes: FiscalTaxRegime[] = ['GENERAL', 'MYPE_TRIBUTARIO', 'RER', 'ESPECIAL', 'NRUS'];
const legalForms: FiscalLegalForm[] = ['PERSONA_NATURAL', 'EIRL', 'SAC', 'SRL', 'SA', 'SAA', 'SACS', 'OTRA'];
function explain(e: unknown): string {
  if (e instanceof ApiHttpError && e.status === 422) {
    const messages = Object.values(e.validationErrors).flat();
    if (messages.length) return messages.join(' · ');
  }
  return e instanceof Error ? e.message : 'No fue posible completar la operación.';
}
async function load(): Promise<void> {
  const current = ++sequence;
  loading.value = true; error.value = '';
  try {
    const result = await api.value.fetchCompanyFiscalProfiles(year.value);
    if (current === sequence) profiles.value = result;
  } catch (e) {
    if (current === sequence) { profiles.value = []; error.value = explain(e); }
  } finally { if (current === sequence) loading.value = false; }
}
function start(): void {
  if (!props.canWrite) return;
  const last = profiles.value[0];
  form.effective_date = '';
  form.tax_regime = last?.tax_regime ?? 'GENERAL';
  form.legal_form = last?.legal_form ?? 'SAC';
  form.income_reference_year = last?.income_reference_year == null ? '' : String(last.income_reference_year);
  form.annual_income = last?.annual_income ?? '';
  form.default_vat_rate = last?.default_vat_rate ?? '18.00';
  form.is_retention_agent = last?.is_retention_agent ?? false;
  form.is_perception_agent = last?.is_perception_agent ?? false;
  form.is_good_taxpayer = last?.is_good_taxpayer ?? false;
  form.is_sire_obliged = last?.is_sire_obliged ?? true;
  form.is_ple_obliged = last?.is_ple_obliged ?? false;
  form.change_reason = '';
  confirmed.value = false; formError.value = ''; success.value = ''; editing.value = true;
}
async function save(): Promise<void> {
  if (!props.canWrite || saving.value) return;
  formError.value = '';
  if (!Number.isInteger(year.value) || year.value < 2000 || year.value > 2100 ||
      !/^\d{4}-\d{2}-\d{2}$/.test(form.effective_date) ||
      Number(form.effective_date.slice(0,4)) !== year.value ||
      (profiles.value[0] != null && form.effective_date <= profiles.value[0].effective_date.slice(0,10))) {
    formError.value = 'Revise el ejercicio y use una fecha posterior a la última vigencia.'; return;
  }
  if (form.tax_regime === 'NRUS' && form.legal_form !== 'PERSONA_NATURAL') {
    formError.value = 'NRUS requiere persona natural.'; return;
  }
  if (form.income_reference_year && (!/^\d{4}$/.test(form.income_reference_year) ||
      Number(form.income_reference_year) > year.value)) {
    formError.value = 'Año de referencia inválido.'; return;
  }
  if (!/^\d+(\.\d{1,2})?$/.test(form.default_vat_rate) ||
      Number(form.default_vat_rate) > 100 ||
      (form.annual_income !== '' && !/^\d+(\.\d{1,2})?$/.test(form.annual_income)) ||
      form.change_reason.trim().length < 10 || !confirmed.value) {
    formError.value = 'Revise importes, sustento y confirmación (máximo 2 decimales).'; return;
  }
  const payload: CreateCompanyFiscalProfilePayload = {
    fiscal_year: year.value, effective_date: form.effective_date,
    tax_regime: form.tax_regime, legal_form: form.legal_form,
    income_reference_year: form.income_reference_year ? Number(form.income_reference_year) : null,
    annual_income: form.annual_income || null, default_vat_rate: form.default_vat_rate,
    is_retention_agent: form.is_retention_agent, is_perception_agent: form.is_perception_agent,
    is_good_taxpayer: form.is_good_taxpayer, is_sire_obliged: form.is_sire_obliged,
    is_ple_obliged: form.is_ple_obliged, change_reason: form.change_reason.trim(),
  };
  saving.value = true;
  try {
    const saved = await api.value.createCompanyFiscalProfile(payload);
    success.value = 'Revisión fiscal ' + saved.revision + ' auditada.';
    editing.value = false; await load();
  } catch (e) { formError.value = explain(e); }
  finally { saving.value = false; }
}
watch(() => props.activeCompanyId, () => { ++sequence; editing.value = false; profiles.value = []; void load(); });
onMounted(() => { void load(); });
</script>

<template>
  <section class="space-y-4" aria-label="Historial fiscal por ejercicio">
    <header class="flex flex-wrap items-center justify-between gap-3">
      <div><h1 class="text-xl font-semibold">Historial fiscal</h1><p class="text-xs text-muted">Empresa {{ activeCompanyId }} · Versiones inmutables</p></div>
      <button v-if="canWrite" type="button" class="rounded-md bg-brand px-3 py-2 text-xs font-semibold text-white" :disabled="loading || saving" @click="start">Nueva revisión</button>
    </header>
    <div class="flex items-end gap-3 rounded-lg border border-line bg-surface p-4">
      <label class="text-xs">Ejercicio
        <input v-model.number="year" type="number" min="2000" max="2100" :disabled="saving" class="block w-28 rounded border border-line bg-background p-2" @change="editing = false; load()" />
      </label>
      <button type="button" class="rounded border border-line px-3 py-2 text-xs" :disabled="loading" @click="load">Actualizar</button>
      <span class="ml-auto text-xs text-muted">{{ profiles.length }} revisiones</span>
    </div>
    <p v-if="error" role="alert" class="text-xs text-red-600">{{ error }}</p>
    <p v-if="success" role="status" class="text-xs text-emerald-600">{{ success }}</p>
    <div class="overflow-x-auto rounded-lg border border-line bg-surface">
      <table class="w-full min-w-[680px] text-left text-xs">
        <thead><tr><th class="p-3">Revisión</th><th class="p-3">Vigencia</th><th class="p-3">Régimen</th><th class="p-3">Forma</th><th class="p-3">IGV</th><th class="p-3">Motivo y autor</th></tr></thead>
        <tbody class="divide-y divide-line">
          <tr v-if="loading"><td colspan="6" class="p-6 text-center">Cargando…</td></tr>
          <tr v-else-if="profiles.length === 0"><td colspan="6" class="p-6 text-center">Sin revisiones registradas. No se inventan antecedentes fiscales.</td></tr>
          <tr v-for="p in (loading ? [] : profiles)" :key="p.id"><td class="p-3">#{{ p.revision }}</td><td class="p-3">{{ p.effective_date.slice(0,10) }}</td><td class="p-3">{{ p.tax_regime }}</td><td class="p-3">{{ p.legal_form }}</td><td class="p-3">{{ p.default_vat_rate }}%</td><td class="p-3">{{ p.change_reason }}<span class="block text-muted">Usuario {{ p.created_by }}</span></td></tr>
        </tbody>
      </table>
    </div>
    <form v-if="editing && canWrite" class="grid grid-cols-1 gap-3 rounded-lg border border-line bg-surface p-4 sm:grid-cols-2" @submit.prevent="save">
      <h2 class="font-semibold sm:col-span-2">Registrar nueva vigencia · {{ year }}</h2>
      <p class="text-xs text-muted sm:col-span-2">Introduzca datos verificados. No se modifica la configuración actual ni se pueden editar las revisiones anteriores.</p>
      <label class="text-xs">Fecha efectiva *<input v-model="form.effective_date" type="date" required :min="String(year) + '-01-01'" :max="String(year) + '-12-31'" class="block w-full rounded border border-line bg-background p-2" /></label>
      <label class="text-xs">Régimen *<select v-model="form.tax_regime" class="block w-full rounded border border-line bg-background p-2"><option v-for="r in regimes" :key="r" :value="r">{{ r }}</option></select></label>
      <label class="text-xs">Forma jurídica *<select v-model="form.legal_form" class="block w-full rounded border border-line bg-background p-2"><option v-for="r in legalForms" :key="r" :value="r">{{ r }}</option></select></label>
      <label class="text-xs">IGV (%) *<input v-model.trim="form.default_vat_rate" required inputmode="decimal" class="block w-full rounded border border-line bg-background p-2" /></label>
      <label class="text-xs">Año ingresos de referencia<input v-model="form.income_reference_year" type="number" :max="year" min="2000" class="block w-full rounded border border-line bg-background p-2" /></label>
      <label class="text-xs">Ingresos anuales<input v-model.trim="form.annual_income" inputmode="decimal" class="block w-full rounded border border-line bg-background p-2" /></label>
      <fieldset class="grid gap-2 rounded border border-line p-3 text-xs sm:col-span-2 sm:grid-cols-2"><legend>Condiciones tributarias</legend>
        <label><input v-model="form.is_retention_agent" type="checkbox" /> Agente de retención</label>
        <label><input v-model="form.is_perception_agent" type="checkbox" /> Agente de percepción</label>
        <label><input v-model="form.is_good_taxpayer" type="checkbox" /> Buen contribuyente</label>
        <label><input v-model="form.is_sire_obliged" type="checkbox" /> Obligado SIRE</label>
        <label><input v-model="form.is_ple_obliged" type="checkbox" /> Obligado PLE</label>
      </fieldset>
      <label class="text-xs sm:col-span-2">Motivo y sustento *<textarea v-model.trim="form.change_reason" required minlength="10" maxlength="500" rows="2" class="block w-full rounded border border-line bg-background p-2"></textarea></label>
      <label class="text-xs sm:col-span-2"><input v-model="confirmed" type="checkbox" /> Confirmo que revisé el ejercicio, vigencia y datos tributarios. El registro será inmutable.</label>
      <p v-if="formError" role="alert" class="text-xs text-red-600 sm:col-span-2">{{ formError }}</p>
      <div class="flex justify-end gap-2 sm:col-span-2">
        <button type="button" class="rounded border border-line px-4 py-2 text-xs" :disabled="saving" @click="editing = false">Cancelar</button>
        <button type="submit" :disabled="saving || !confirmed" class="rounded bg-brand px-4 py-2 text-xs font-semibold text-white disabled:opacity-50">Guardar revisión auditada</button>
      </div>
    </form>
  </section>
</template>
