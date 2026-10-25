<script setup lang="ts">
import { computed, shallowRef, watch } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { createColumnHelper, getCoreRowModel, getFilteredRowModel, getPaginationRowModel, useVueTable } from '@tanstack/vue-table';
import { Combobox, ComboboxButton, ComboboxInput, ComboboxOption, ComboboxOptions } from '@headlessui/vue';
import { BookOpen, Check, ChevronsUpDown, Search } from 'lucide-vue-next';
import { AccountingApiClient } from '../api/httpClient';
import type { SunatCiiuClass, SunatTaxCode } from '@/modules/company/types/companyOnboarding.types';

const props = defineProps<{ readonly getAccessToken: () => string | null }>();
const client = new AccountingApiClient('', { getAccessToken: props.getAccessToken, getActiveCompanyId: () => null });
const pleTables = [
  { number: '01', name: 'Medios de pago' },
  { number: '02', name: 'Tipos de documento de identidad' },
  { number: '03', name: 'Entidades financieras' },
  { number: '04', name: 'Tipo de moneda (Anexo 3 PLE)' },
  { number: '05', name: 'Tipos de existencia' },
  { number: '06', name: 'Unidades de medida' },
  { number: '08', name: 'Libros y registros tributarios' },
  { number: '10', name: 'Tipos de comprobante de pago' },
  { number: '11', name: 'Dependencias aduaneras' },
  { number: '12', name: 'Operaciones de inventario' },
  { number: '13', name: 'Catálogo de existencias (origen)' },
  { number: '14', name: 'Método de valuación' },
  { number: '15', name: 'Tipo de título' },
  { number: '16', name: 'Tipo de acciones o participaciones' },
  { number: '17', name: 'Plan de cuentas PLE' },
  { number: '18', name: 'Tipo de activo fijo' },
  { number: '19', name: 'Estado del activo fijo' },
  { number: '20', name: 'Método de depreciación' },
  { number: '21', name: 'Agrupamiento del costo de producción' },
  { number: '22', name: 'Catálogo de estados financieros' },
] as const;
const rucDatasets = [
  { number: 'contributor-types', name: 'Anexo 2 · Tipos de contribuyente' },
  { number: 'ciiu', name: 'CIIU Rev. 4 · Actividades económicas' },
  { number: 'tax-codes', name: 'Anexo 10 · Códigos de tributos' },
] as const;
type RucDataset = typeof rucDatasets[number]['number'];
type Source = 'ple' | 'ruc';
interface ReferenceRow { code: string; description: string; details: string; }
interface ReferenceResult { rows: ReferenceRow[]; edition: string | null; sourceUrl: string | null; amendmentUrl: string | null; }
const source = shallowRef<Source>('ple');
const pleNumber = shallowRef<string>('01');
const selectedEdition = shallowRef<'latest' | 'legacy-seed' | '2015-06-anexo-3'>('latest');
const rucNumber = shallowRef<RucDataset>('contributor-types');
const comboFilter = shallowRef('');
const searchInput = shallowRef('');
const pageIndex = shallowRef(0);
const pageSize = 25;
const choices = computed(() => source.value === 'ple' ? pleTables : rucDatasets);
const selected = computed(() => source.value === 'ple' ? pleNumber.value : rucNumber.value);
const selectedName = computed(() => choices.value.find((entry) => entry.number === selected.value)?.name ?? selected.value);
const hasEditions = computed(() => source.value === 'ple' && ['01', '02', '03', '05', '06', '08', '10', '11', '12', '13'].includes(pleNumber.value));
function normalize(value: string): string {
  return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es-PE').trim();
}
const filteredChoices = computed(() => choices.value.filter((entry) => normalize(`${entry.number} ${entry.name}`).includes(normalize(comboFilter.value))));
function chooseSource(next: Source): void {
  source.value = next;
  selectedEdition.value = 'latest';
  comboFilter.value = ''; searchInput.value = ''; pageIndex.value = 0;
}
function chooseTable(number: string): void {
  if (source.value === 'ple') pleNumber.value = number;
  else rucNumber.value = number as RucDataset;
  selectedEdition.value = 'latest';
  comboFilter.value = ''; searchInput.value = ''; pageIndex.value = 0;
}
function displayChoice(value: unknown): string {
  const number = typeof value === 'string' ? value : selected.value;
  const option = choices.value.find((entry) => entry.number === number);
  return option ? `${option.number} · ${option.name}` : number;
}
const query = useQuery({
  queryKey: computed(() => ['sunat-reference', source.value, selected.value, hasEditions.value ? selectedEdition.value : 'latest']),
  queryFn: async ({ signal, queryKey }): Promise<ReferenceResult> => {
    const group = queryKey[1];
    const number = queryKey[2] ?? '01';
    if (group === 'ple') {
      const response = await client.fetchSunatTableReference(number, signal, queryKey[3] as 'latest' | 'legacy-seed' | '2015-06-anexo-3');
      return {
        rows: response.data.map((item) => ({ code: item.code, description: number === '04' ? '—' : item.description, details: '' })),
        edition: response.meta.version ?? null,
        sourceUrl: response.meta.source_url ?? null,
        amendmentUrl: response.meta.amendment_url ?? null,
      };
    }
    const items = await client.fetchSunatRucReference(number as RucDataset, signal);
    return { rows: items.map((item) => {
      const additional = item as Partial<SunatCiiuClass & SunatTaxCode>;
      const details = number === 'ciiu'
        ? `División ${additional.division ?? ''} · Grupo ${additional.group_code ?? ''}`
        : number === 'tax-codes' ? (additional.short_name ?? '') : '';
      return { code: item.code, description: item.name, details };
    }), edition: null, sourceUrl: null, amendmentUrl: null };
  },
  staleTime: 30 * 60 * 1000, gcTime: 30 * 60 * 1000, retry: 1, refetchOnWindowFocus: false,
});
const rows = computed<ReferenceRow[]>(() => query.data.value?.rows ?? []);
const columns = createColumnHelper<ReferenceRow>();
const table = useVueTable({
  get data() { return rows.value; },
  columns: [columns.accessor('code', { header: 'Código' }), columns.accessor('description', { header: 'Descripción' }), columns.accessor('details', { header: 'Información adicional' })],
  get state() { return { globalFilter: searchInput.value, pagination: { pageIndex: pageIndex.value, pageSize } }; },
  globalFilterFn: (row, _columnId, term: string) => normalize(`${row.original.code} ${row.original.description} ${row.original.details}`).includes(normalize(term)),
  getCoreRowModel: getCoreRowModel(), getFilteredRowModel: getFilteredRowModel(), getPaginationRowModel: getPaginationRowModel(),
});
const count = computed(() => table.getFilteredRowModel().rows.length);
const pages = computed(() => table.getPageCount());
const pageLabel = computed(() => count.value ? `${pageIndex.value * pageSize + 1}–${pageIndex.value * pageSize + table.getRowModel().rows.length} de ${count.value.toLocaleString('es-PE')}` : '0 resultados');
watch(searchInput, () => { pageIndex.value = 0; });
</script>

<template>
  <section class="space-y-5" aria-labelledby="sunat-heading">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <p class="text-xs font-semibold uppercase tracking-wider text-brand">Referencias globales</p>
        <h1 id="sunat-heading" class="mt-1 text-2xl font-semibold text-foreground">Otros catálogos SUNAT</h1>
        <p class="mt-1 text-sm text-muted">Tablas para libros y anexos RUC disponibles en el sistema.</p>
      </div>
      <span class="rounded-full border border-border bg-soft px-3 py-1 text-xs text-muted">Solo lectura</span>
    </div>
    <div class="flex flex-wrap gap-2 border-b border-border pb-3" aria-label="Grupo de catálogos">
      <button type="button" :aria-pressed="source === 'ple'" :class="source === 'ple' ? 'bg-brand text-on-brand' : 'border border-border bg-surface text-foreground hover:bg-soft'" class="rounded-md px-3 py-2 text-sm font-medium" @click="chooseSource('ple')">Tablas PLE</button>
      <button type="button" :aria-pressed="source === 'ruc'" :class="source === 'ruc' ? 'bg-brand text-on-brand' : 'border border-border bg-surface text-foreground hover:bg-soft'" class="rounded-md px-3 py-2 text-sm font-medium" @click="chooseSource('ruc')">Anexos RUC y CIIU</button>
    </div>
    <p class="text-sm text-muted">{{ source === 'ple' ? 'Diecinueve tablas PLE y la Tabla 8 de libros tributarios disponibles. SIRE y SEE usan referencias independientes.' : 'Anexos del RUC y clasificación CIIU; sus números no equivalen a tablas PLE.' }}</p>
    <div class="space-y-4 rounded-xl border border-border bg-surface p-4">
      <div class="grid gap-3 md:grid-cols-2">
        <div class="min-w-0 text-xs font-medium text-muted">
          <span id="sunat-table-label">{{ source === 'ple' ? 'Tabla SUNAT de libros / PLE' : 'Anexo RUC o CIIU' }}</span>
          <Combobox :key="source" :model-value="selected" @update:model-value="chooseTable">
            <div class="relative mt-1.5">
              <div class="flex w-full items-center rounded-md border border-border bg-surface text-foreground focus-within:ring-2 focus-within:ring-brand">
                <ComboboxInput aria-labelledby="sunat-table-label" class="min-w-0 flex-1 bg-transparent px-3 py-2.5 text-sm outline-none" :display-value="displayChoice" @change="comboFilter = ($event.target as HTMLInputElement).value" />
                <ComboboxButton class="shrink-0 px-3 py-2.5" aria-label="Mostrar tablas"><ChevronsUpDown class="h-4 w-4" /></ComboboxButton>
              </div>
              <ComboboxOptions class="absolute z-50 mt-1 max-h-64 w-full overflow-y-auto rounded-md border border-border bg-surface p-1 text-sm text-foreground shadow-pop focus:outline-none">
                <div v-if="filteredChoices.length === 0" class="px-3 py-2 text-muted">Sin coincidencias</div>
                <ComboboxOption v-for="option in filteredChoices" :key="option.number" v-slot="{ active, selected: isSelected }" :value="option.number" as="template">
                  <li :class="active ? 'bg-soft' : ''" class="flex cursor-pointer items-center justify-between gap-2 rounded px-3 py-2"><span>{{ option.number }} · {{ option.name }}</span><Check v-if="isSelected" class="h-4 w-4 shrink-0 text-brand" /></li>
                </ComboboxOption>
              </ComboboxOptions>
            </div>
          </Combobox>
        </div>
        <label class="block text-xs font-medium text-muted">Buscar en esta tabla
          <span class="relative mt-1.5 block"><Search class="absolute left-3 top-3 h-4 w-4" /><input v-model="searchInput" type="search" maxlength="100" class="w-full rounded-md border border-border bg-surface py-2.5 pl-9 pr-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-brand" placeholder="Código o descripción…" /></span>
        </label>
      </div>
      <div v-if="hasEditions" class="flex flex-wrap items-center gap-2 text-xs" aria-label="Edición de la tabla">
        <span class="text-muted">Edición:</span>
        <button type="button" :aria-pressed="selectedEdition === 'latest'" :class="selectedEdition === 'latest' ? 'bg-brand text-on-brand' : 'border border-border bg-surface text-foreground'" class="rounded-md px-3 py-1.5" @click="selectedEdition = 'latest'; pageIndex = 0">{{ pleNumber === '08' ? 'Tabla cotejada' : pleNumber === '13' ? 'Anexo 2020' : 'Anexo cotejado' }}</button>
        <button type="button" :aria-pressed="selectedEdition === (pleNumber === '13' ? '2015-06-anexo-3' : 'legacy-seed')" :class="selectedEdition === (pleNumber === '13' ? '2015-06-anexo-3' : 'legacy-seed') ? 'bg-brand text-on-brand' : 'border border-border bg-surface text-foreground'" class="rounded-md px-3 py-1.5" @click="selectedEdition = pleNumber === '13' ? '2015-06-anexo-3' : 'legacy-seed'; pageIndex = 0">{{ pleNumber === '13' ? 'Anexo 2015' : 'Carga inicial' }}</button>
      </div>
      <p class="text-sm text-muted">{{ selectedName }} · {{ rows.length.toLocaleString('es-PE') }} registros disponibles.</p>
      <p v-if="source === 'ple' && query.data.value?.edition === '2015-06-anexo-3'" class="text-xs text-muted">Edición PLE de 2015 incorporada para consulta; la vigencia de cada código en 2026 requiere cotejo normativo. <a v-if="query.data.value?.sourceUrl" class="underline" :href="query.data.value.sourceUrl" target="_blank" rel="noopener noreferrer">Ver anexo SUNAT</a></p>
      <p v-if="source === 'ple' && pleNumber === '04'" class="text-xs text-muted">La Tabla 4 PLE conserva 178 códigos históricos. Las descripciones del PDF no se transcribieron por errores de extracción; consulte el anexo. Para configurar monedas de una empresa se usa el catálogo SEE/ISO con visibilidad propia, no esta lista de 2015.</p>
      <p v-if="source === 'ple' && pleNumber === '13'" class="text-xs text-muted">La Tabla 13 identifica el catálogo de códigos usado para existencias. La edición de 2020 llama GTIN al código 3; la de 2015 decía GS1 (EAN-UCC). No clasifica productos ni sustituye la Tabla 5 de tipos de existencia.</p>
      <p v-if="source === 'ple' && pleNumber === '14'" class="text-xs text-muted">Los seis métodos son una referencia PLE de 2015. Su presencia en el anexo no cambia por sí sola el método de valorización configurado en inventario.</p>
      <p v-if="source === 'ple' && ['15', '16', '17'].includes(pleNumber)" class="text-xs text-muted">Códigos PLE del Anexo 3 de 2015 para títulos, participaciones o tipo de plan. Son referencias de libros; la Tabla 17 no selecciona ni reemplaza el plan contable de la empresa.</p>
      <p v-if="source === 'ple' && ['18', '19', '20'].includes(pleNumber)" class="text-xs text-muted">Referencias PLE para activos fijos del Anexo 3 de 2015. La selección de un código no determina por sí sola depreciación fiscal ni política contable de la empresa.</p>
      <p v-if="source === 'ple' && pleNumber === '21'" class="text-xs text-muted">Agrupamientos PLE del costo de producción valorizado anual del Anexo 3 de 2015. No configuran automáticamente el costeo de la empresa.</p>
      <p v-if="source === 'ple' && pleNumber === '22'" class="text-xs text-muted">El catálogo PLE de estados financieros de 2015 identifica sectores y formatos de presentación; no modifica los estados financieros ni su normativa aplicable.</p>
      <p v-if="source === 'ple' && pleNumber === '03' && selectedEdition === 'latest'" class="text-xs text-muted">La tabla 03 incluye entidades históricas. Sus códigos son referencia PLE; no acreditan que un banco esté operativo ni habilitan su selección para cuentas actuales.</p>
      <p v-if="source === 'ple' && pleNumber === '12' && selectedEdition === 'latest'" class="text-xs text-muted">La edición cotejada contiene 47 operaciones de inventario. La carga inicial tiene 17 y asigna otro significado al código 07; no use ambas ediciones indistintamente en el kardex.</p>
      <p v-if="source === 'ple' && pleNumber === '11' && selectedEdition === 'latest'" class="text-xs text-muted">Los 30 códigos corresponden a la Tabla 11 PLE de 2015; no acreditan qué dependencias siguen operativas ni sustituyen la Tabla 4 de SIRE.</p>
      <p v-if="source === 'ple' && pleNumber === '10' && selectedEdition === 'latest'" class="text-xs text-muted">La lista PLE de 2015 contiene 61 tipos. Cada libro y campo puede restringir los códigos admitidos; esta lista no habilita la emisión de comprobantes ni concede crédito fiscal.</p>
      <p v-if="source === 'ple' && pleNumber === '08' && query.data.value?.edition === '2008-rs239'" class="text-xs text-muted">Tabla 8 de la R.S. 234-2006/SUNAT y código 5-A añadido por la R.S. 239-2008/SUNAT. No es la Tabla 08 del Anexo 3 PLE ni confirma obligatoriedad por régimen o vigencia individual en 2026. <a v-if="query.data.value?.sourceUrl" class="underline" :href="query.data.value.sourceUrl" target="_blank" rel="noopener noreferrer">Ver tabla SUNAT</a><template v-if="query.data.value?.amendmentUrl"> · <a class="underline" :href="query.data.value.amendmentUrl" target="_blank" rel="noopener noreferrer">Ver modificación</a></template></p>
      <p v-if="source === 'ple' && query.data.value?.edition === 'legacy-seed'" class="text-xs text-muted">Carga inicial preservada como histórico, sin verificación de vigencia.</p>
      <p v-if="query.isError.value" role="alert" class="rounded-md border border-neg/30 bg-neg/10 px-3 py-2 text-sm text-neg">{{ query.error.value instanceof Error ? query.error.value.message : 'No se pudo cargar la tabla.' }} <button type="button" class="ml-2 underline" @click="query.refetch()">Reintentar</button></p>
      <p v-else-if="query.isPending.value" role="status" class="py-8 text-center text-sm text-muted">Cargando referencias…</p>
      <template v-else>
        <div class="overflow-x-auto" role="region" :aria-label="`Tabla ${selectedName}`" tabindex="0">
          <table class="w-[712px] table-fixed text-left text-sm lg:w-[1000px]">
            <colgroup><col class="w-[120px] lg:w-[140px]" /><col class="w-[392px] lg:w-[560px]" /><col class="w-[200px] lg:w-[300px]" /></colgroup>
            <thead class="border-b border-border text-xs uppercase tracking-wide text-muted"><tr><th scope="col" class="py-2 pr-4">Código</th><th scope="col" class="py-2 pr-4">Descripción</th><th scope="col" class="py-2">Información adicional</th></tr></thead>
            <tbody><tr v-for="row in table.getRowModel().rows" :key="row.original.code" class="border-b border-border/60 align-top"><td class="break-words py-3 pr-4 font-mono font-semibold text-foreground">{{ row.original.code }}</td><td class="break-words py-3 pr-4 text-foreground">{{ row.original.description }}</td><td class="break-words py-3 text-xs text-muted">{{ row.original.details }}</td></tr></tbody>
          </table>
          <p v-if="count === 0" class="py-8 text-center text-sm text-muted">No hay resultados para esta búsqueda.</p>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-3 text-xs text-muted"><span>{{ pageLabel }} · página {{ pageIndex + 1 }} de {{ pages }}</span><div class="flex gap-2"><button type="button" class="rounded-md border border-border px-3 py-1.5 text-foreground disabled:opacity-40" :disabled="pageIndex <= 0" @click="pageIndex--">Anterior</button><button type="button" class="rounded-md border border-border px-3 py-1.5 text-foreground disabled:opacity-40" :disabled="pageIndex >= pages - 1" @click="pageIndex++">Siguiente</button></div></div>
      </template>
    </div>
    <p class="text-xs text-muted"><BookOpen class="mr-1 inline h-3.5 w-3.5" /> Búsqueda y paginación locales. En pantallas pequeñas, deslice la tabla horizontalmente.</p>
  </section>
</template>
