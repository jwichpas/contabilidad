<script setup lang="ts">
import { computed, shallowRef, watch } from 'vue';
import { useQuery } from '@tanstack/vue-query';
import { createColumnHelper, getCoreRowModel, getFilteredRowModel, getPaginationRowModel, useVueTable } from '@tanstack/vue-table';
import { Combobox, ComboboxButton, ComboboxInput, ComboboxOption, ComboboxOptions } from '@headlessui/vue';
import { AlertTriangle, BookOpen, Check, ChevronsUpDown, Globe2, MapPin, Search } from 'lucide-vue-next';
import { AccountingApiClient } from '../api/httpClient';
import type { SeeCatalogItem, SeeCatalogSummary, SeeUbigeo } from '../types/seeReferences.types';

const props = defineProps<{ readonly getAccessToken: () => string | null }>();
const client = new AccountingApiClient('', { getAccessToken: props.getAccessToken, getActiveCompanyId: () => null });
type Section = 'catalogs' | 'ubigeos';
const section = shallowRef<Section>('catalogs');
const catalogNumber = shallowRef('02');
const catalogFilter = shallowRef('');
const searchInput = shallowRef('');
const pageIndex = shallowRef(0);
const pageSize = 25;
const cacheTime = 30 * 60 * 1000;

const catalogsQuery = useQuery({
  queryKey: ['see-catalogs'],
  queryFn: ({ signal }) => client.fetchSeeCatalogs(signal),
  staleTime: cacheTime, gcTime: cacheTime, retry: 1, refetchOnWindowFocus: false,
});
const catalogQuery = useQuery({
  queryKey: computed(() => ['see-catalog', catalogNumber.value]),
  enabled: computed(() => section.value === 'catalogs'),
  queryFn: ({ signal }) => client.fetchSeeCatalog(catalogNumber.value, { q: '', page: 1, signal }),
  staleTime: cacheTime, gcTime: cacheTime, retry: 1, refetchOnWindowFocus: false,
});
const ubigeoQuery = useQuery({
  queryKey: ['see-ubigeos'],
  enabled: computed(() => section.value === 'ubigeos'),
  queryFn: ({ signal }) => client.fetchSeeUbigeos({ q: '', page: 1, signal }),
  staleTime: cacheTime, gcTime: cacheTime, retry: 1, refetchOnWindowFocus: false,
});

function normalize(value: string): string {
  return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es-PE').trim();
}
const catalogs = computed<readonly SeeCatalogSummary[]>(() => catalogsQuery.data.value ?? []);
const currentCatalog = computed(() => catalogs.value.find((item) => item.number === catalogNumber.value));
const filteredCatalogs = computed(() => {
  const term = normalize(catalogFilter.value);
  return term ? catalogs.value.filter((item) => normalize(`${item.number} ${item.name}`).includes(term)) : catalogs.value;
});
const isLoading = computed(() => section.value === 'catalogs' ? catalogQuery.isPending.value : ubigeoQuery.isPending.value);
const error = computed(() => {
  const failure = section.value === 'catalogs' ? catalogQuery.error.value ?? catalogsQuery.error.value : ubigeoQuery.error.value;
  return failure instanceof Error ? failure.message : failure ? 'No se pudieron cargar las referencias.' : null;
});
function chooseSection(next: Section): void {
  if (next === section.value) return;
  section.value = next; searchInput.value = ''; pageIndex.value = 0;
}
function chooseCatalog(number: string): void {
  if (number === catalogNumber.value) return;
  catalogNumber.value = number; catalogFilter.value = ''; searchInput.value = ''; pageIndex.value = 0;
}
function displayCatalog(value: unknown): string {
  const number = typeof value === 'string' ? value : catalogNumber.value;
  const item = catalogs.value.find((catalog) => catalog.number === number);
  return item ? `${item.number} · ${item.name} (${item.item_count})` : `Catálogo ${number}`;
}
function retry(): void {
  if (section.value === 'ubigeos') void ubigeoQuery.refetch();
  else {
    if (catalogsQuery.isError.value) void catalogsQuery.refetch();
    void catalogQuery.refetch();
  }
}

const itemColumns = createColumnHelper<SeeCatalogItem>();
const ubigeoColumns = createColumnHelper<SeeUbigeo>();
const catalogRows = computed(() => [...(catalogQuery.data.value?.data ?? [])]);
const ubigeoRows = computed(() => [...(ubigeoQuery.data.value?.data ?? [])]);
const catalogTable = useVueTable({
  get data() { return catalogRows.value; },
  columns: [
    itemColumns.accessor('code', { header: 'Código' }),
    itemColumns.accessor('description', { header: 'Descripción' }),
    itemColumns.accessor('details', { header: 'Información adicional' }),
  ],
  get state() { return { globalFilter: searchInput.value, pagination: { pageIndex: pageIndex.value, pageSize } }; },
  globalFilterFn: (row, _columnId, term: string) => normalize(`${row.original.code} ${row.original.description}`).includes(normalize(term)),
  getCoreRowModel: getCoreRowModel(), getFilteredRowModel: getFilteredRowModel(), getPaginationRowModel: getPaginationRowModel(),
});
const ubigeoTable = useVueTable({
  get data() { return ubigeoRows.value; },
  columns: [
    ubigeoColumns.accessor('code', { header: 'Ubigeo' }),
    ubigeoColumns.accessor('district', { header: 'Distrito' }),
    ubigeoColumns.accessor('province', { header: 'Provincia' }),
    ubigeoColumns.accessor('department', { header: 'Departamento' }),
  ],
  get state() { return { globalFilter: searchInput.value, pagination: { pageIndex: pageIndex.value, pageSize } }; },
  globalFilterFn: (row, _columnId, term: string) => normalize(`${row.original.code} ${row.original.district} ${row.original.province} ${row.original.department}`).includes(normalize(term)),
  getCoreRowModel: getCoreRowModel(), getFilteredRowModel: getFilteredRowModel(), getPaginationRowModel: getPaginationRowModel(),
});
const activeTable = computed(() => section.value === 'catalogs' ? catalogTable : ubigeoTable);
const rowCount = computed(() => activeTable.value.getFilteredRowModel().rows.length);
const pageRows = computed(() => activeTable.value.getRowModel().rows.length);
const pageCount = computed(() => activeTable.value.getPageCount());
const pageLabel = computed(() => rowCount.value ? `${pageIndex.value * pageSize + 1}–${pageIndex.value * pageSize + pageRows.value} de ${rowCount.value.toLocaleString('es-PE')}` : '0 resultados');
watch(searchInput, () => { pageIndex.value = 0; });
</script>

<template>
  <section class="space-y-5" aria-labelledby="see-heading">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <p class="text-xs font-semibold uppercase tracking-wider text-brand">Referencias globales</p>
        <h1 id="see-heading" class="mt-1 text-2xl font-semibold text-foreground">Catálogos de facturación electrónica</h1>
        <p class="mt-1 text-sm text-muted">Consulta de códigos SEE e INEI compartidos por todas las empresas.</p>
      </div>
      <span class="rounded-full border border-line bg-soft px-3 py-1 text-xs text-muted">Solo lectura</span>
    </div>
    <div class="flex items-start gap-2 rounded-lg border border-amber-300/50 bg-amber-50/70 p-3 text-sm text-amber-950 dark:border-amber-500/30 dark:bg-amber-950/30 dark:text-amber-100" role="note">
      <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
      <p>Archivo histórico de consulta, pendiente de conciliación completa con las versiones vigentes. Los catálogos 15, 19, 20 y 55 tienen diferencias documentadas frente a anexos SUNAT; la unidad A11 tiene dos descripciones. No use esta vista para validar comprobantes.</p>
    </div>
    <div class="flex flex-wrap gap-2 border-b border-line pb-3" aria-label="Tipo de referencia">
      <button type="button" :aria-pressed="section === 'catalogs'" :class="section === 'catalogs' ? 'bg-brand text-brandtx' : 'border border-line bg-surf text-foreground hover:bg-soft'" class="inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium" @click="chooseSection('catalogs')"><BookOpen class="h-4 w-4" /> Catálogos SEE</button>
      <button type="button" :aria-pressed="section === 'ubigeos'" :class="section === 'ubigeos' ? 'bg-brand text-brandtx' : 'border border-line bg-surf text-foreground hover:bg-soft'" class="inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium" @click="chooseSection('ubigeos')"><MapPin class="h-4 w-4" /> Ubigeo INEI</button>
    </div>
    <div class="rounded-xl border border-line bg-surf p-4 space-y-4">
      <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <div v-if="section === 'catalogs'" class="min-w-0 text-xs font-medium text-muted">
          <span id="see-catalog-label">Catálogo SEE</span>
          <Combobox :model-value="catalogNumber" @update:model-value="chooseCatalog">
            <div class="relative mt-1.5">
              <div class="flex w-full items-center rounded-md border border-line bg-surf text-foreground focus-within:ring-2 focus-within:ring-brand">
                <ComboboxInput aria-labelledby="see-catalog-label" class="min-w-0 flex-1 bg-transparent px-3 py-2.5 text-sm outline-none" :display-value="displayCatalog" @change="catalogFilter = ($event.target as HTMLInputElement).value" />
                <ComboboxButton class="shrink-0 px-3 py-2.5" aria-label="Mostrar catálogos"><ChevronsUpDown class="h-4 w-4" /></ComboboxButton>
              </div>
              <ComboboxOptions class="absolute z-30 mt-1 max-h-64 w-full overflow-auto rounded-md border border-line bg-surf p-1 text-sm text-foreground shadow-lg focus:outline-none">
                <div v-if="catalogsQuery.isPending.value" class="px-3 py-2 text-muted">Cargando catálogos…</div>
                <div v-else-if="filteredCatalogs.length === 0" class="px-3 py-2 text-muted">Sin coincidencias</div>
                <ComboboxOption v-for="catalog in filteredCatalogs" :key="catalog.number" v-slot="{ active, selected }" :value="catalog.number" as="template">
                  <li :class="active ? 'bg-soft' : ''" class="flex cursor-pointer items-center justify-between gap-2 rounded px-3 py-2">
                    <span class="min-w-0">{{ catalog.number }} · {{ catalog.name }} ({{ catalog.item_count }})</span>
                    <Check v-if="selected" class="h-4 w-4 shrink-0 text-brand" />
                  </li>
                </ComboboxOption>
              </ComboboxOptions>
            </div>
          </Combobox>
        </div>
        <div v-else class="flex items-end gap-2 text-sm text-muted"><Globe2 class="mb-1 h-5 w-5 text-brand" /> 1,874 distritos en el archivo suministrado</div>
        <label class="block text-xs font-medium text-muted">
          Buscar por {{ section === 'catalogs' ? 'código o descripción' : 'código, distrito, provincia o departamento' }}
          <span class="relative mt-1.5 block">
            <Search class="absolute left-3 top-3 h-4 w-4 text-muted" />
            <input v-model="searchInput" type="search" maxlength="100" class="w-full rounded-md border border-line bg-surf py-2.5 pl-9 pr-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-brand" :placeholder="section === 'catalogs' ? 'Ej. PEN, NIU, PE…' : 'Ej. 230101, Tacna…'" />
          </span>
        </label>
      </div>
      <p v-if="section === 'catalogs' && currentCatalog" class="text-sm text-muted">Catálogo {{ currentCatalog.number }} · {{ currentCatalog.name }} · {{ currentCatalog.item_count.toLocaleString('es-PE') }} registros en el archivo.</p>
      <p v-if="error" role="alert" class="rounded-md border border-neg/30 bg-neg/10 px-3 py-2 text-sm text-neg">{{ error }} <button type="button" class="ml-2 underline" @click="retry">Reintentar</button></p>
      <p v-if="isLoading" role="status" class="py-8 text-center text-sm text-muted">Cargando referencias…</p>
      <div v-else-if="section === 'catalogs' && catalogQuery.data.value" class="overflow-x-auto">
        <table class="w-full min-w-[600px] text-left text-sm">
          <thead class="border-b border-line text-xs uppercase tracking-wide text-muted"><tr><th scope="col" class="py-2 pr-4">Código</th><th scope="col" class="py-2 pr-4">Descripción</th><th scope="col" class="py-2">Información adicional</th></tr></thead>
          <tbody><tr v-for="row in catalogTable.getRowModel().rows" :key="`${catalogNumber}-${row.original.source_row}`" class="border-b border-line/60 align-top">
            <td class="py-3 pr-4 font-mono font-semibold text-foreground">{{ row.original.code }} <span v-if="row.original.is_ambiguous" class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-900 dark:bg-amber-900 dark:text-amber-100">Ambiguo</span></td>
            <td class="py-3 pr-4 text-foreground">{{ row.original.description }}</td>
            <td class="py-3 text-xs text-muted"><span v-for="(value, key) in row.original.details" :key="key" class="mr-2 inline-block">{{ key }}: {{ value }}</span></td>
          </tr></tbody>
        </table>
        <p v-if="rowCount === 0" class="py-8 text-center text-sm text-muted">{{ currentCatalog?.item_count === 0 ? 'Este catálogo remite a una fuente externa y no contiene filas en el archivo recibido.' : 'No hay resultados para esta búsqueda.' }}</p>
      </div>
      <div v-else-if="ubigeoQuery.data.value" class="overflow-x-auto">
        <table class="w-full min-w-[680px] text-left text-sm">
          <thead class="border-b border-line text-xs uppercase tracking-wide text-muted"><tr><th scope="col" class="py-2 pr-4">Ubigeo</th><th scope="col" class="py-2 pr-4">Distrito</th><th scope="col" class="py-2 pr-4">Provincia</th><th scope="col" class="py-2">Departamento</th></tr></thead>
          <tbody><tr v-for="row in ubigeoTable.getRowModel().rows" :key="row.original.code" class="border-b border-line/60"><td class="py-3 pr-4 font-mono font-semibold text-foreground">{{ row.original.code }}</td><td class="py-3 pr-4 text-foreground">{{ row.original.district }}</td><td class="py-3 pr-4 text-muted">{{ row.original.province }}</td><td class="py-3 text-muted">{{ row.original.department }}</td></tr></tbody>
        </table>
        <p v-if="rowCount === 0" class="py-8 text-center text-sm text-muted">No hay ubigeos para esta búsqueda.</p>
      </div>
      <div v-if="!isLoading && !error && (catalogQuery.data.value || ubigeoQuery.data.value)" class="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-3 text-xs text-muted">
        <span>{{ pageLabel }} · página {{ pageIndex + 1 }} de {{ pageCount }}</span>
        <div class="flex gap-2">
          <button type="button" class="rounded-md border border-line px-3 py-1.5 text-foreground disabled:opacity-40" :disabled="pageIndex <= 0" @click="pageIndex--">Anterior</button>
          <button type="button" class="rounded-md border border-line px-3 py-1.5 text-foreground disabled:opacity-40" :disabled="pageIndex >= pageCount - 1" @click="pageIndex++">Siguiente</button>
        </div>
      </div>
    </div>
    <p class="text-xs text-muted">La búsqueda y la paginación se realizan en este dispositivo. Los catálogos PLE y SIRE siguen independientes de esta consulta SEE.</p>
  </section>
</template>
