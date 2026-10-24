<script setup lang="ts">
import { computed, onMounted, shallowRef, watch } from 'vue';
import { AlertTriangle, BookOpen, Globe2, MapPin, Search } from 'lucide-vue-next';
import { AccountingApiClient } from '../api/httpClient';
import type { SeeCatalogItem, SeeCatalogSummary, SeePage, SeeUbigeo } from '../types/seeReferences.types';

const props = defineProps<{ readonly getAccessToken: () => string | null }>();
const client = new AccountingApiClient('', {
  getAccessToken: props.getAccessToken,
  getActiveCompanyId: () => null,
});

type Section = 'catalogs' | 'ubigeos';
const section = shallowRef<Section>('catalogs');
const catalogNumber = shallowRef('02');
const catalogs = shallowRef<readonly SeeCatalogSummary[]>([]);
const searchInput = shallowRef('');
const searchTerm = shallowRef('');
const page = shallowRef(1);
const retryKey = shallowRef(0);
const isLoadingCatalogs = shallowRef(false);
const isLoadingPage = shallowRef(false);
const error = shallowRef<string | null>(null);
const catalogPage = shallowRef<SeePage<SeeCatalogItem> | null>(null);
const ubigeoPage = shallowRef<SeePage<SeeUbigeo> | null>(null);

const currentCatalog = computed(() => catalogs.value.find((item) => item.number === catalogNumber.value));
const currentPage = computed(() => section.value === 'catalogs' ? catalogPage.value : ubigeoPage.value);
const pageRows = computed(() => currentPage.value?.data.length ?? 0);
const pageLabel = computed(() => {
  const meta = currentPage.value?.meta;
  if (!meta || meta.total === 0) return '0 resultados';
  const first = (meta.current_page - 1) * 25 + 1;
  return `${first}–${first + pageRows.value - 1} de ${meta.total.toLocaleString('es-PE')}`;
});

function chooseSection(next: Section): void {
  if (next === section.value) return;
  section.value = next;
  searchInput.value = '';
  searchTerm.value = '';
  page.value = 1;
}

function chooseCatalog(number: string): void {
  catalogNumber.value = number;
  searchInput.value = '';
  searchTerm.value = '';
  page.value = 1;
}

async function loadCatalogs(): Promise<void> {
  isLoadingCatalogs.value = true;
  try {
    catalogs.value = await client.fetchSeeCatalogs();
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : 'No se pudo cargar el listado SEE.';
  } finally {
    isLoadingCatalogs.value = false;
  }
}

function retry(): void {
  if (section.value === 'catalogs' && catalogs.value.length === 0) void loadCatalogs();
  retryKey.value++;
}

onMounted(() => { void loadCatalogs(); });

watch(searchInput, (value, _previous, onCleanup) => {
  const timeout = window.setTimeout(() => {
    searchTerm.value = value.trim();
    page.value = 1;
  }, 300);
  onCleanup(() => window.clearTimeout(timeout));
});

watch([section, catalogNumber, page, searchTerm, retryKey], async ([active, number, requestedPage, query], _previous, onCleanup) => {
  const controller = new AbortController();
  onCleanup(() => controller.abort());
  isLoadingPage.value = true;
  error.value = null;
  if (active === 'catalogs') catalogPage.value = null;
  else ubigeoPage.value = null;
  try {
    if (active === 'catalogs') {
      const result = await client.fetchSeeCatalog(number, { q: query, page: requestedPage, signal: controller.signal });
      if (!controller.signal.aborted) catalogPage.value = result;
    } else {
      const result = await client.fetchSeeUbigeos({ q: query, page: requestedPage, signal: controller.signal });
      if (!controller.signal.aborted) ubigeoPage.value = result;
    }
  } catch (cause) {
    if (!controller.signal.aborted) {
      error.value = cause instanceof Error ? cause.message : 'No se pudieron cargar las referencias.';
    }
  } finally {
    if (!controller.signal.aborted) isLoadingPage.value = false;
  }
}, { immediate: true });
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
      <p>Fuentes recibidas pendientes de verificar su vigencia. Algunos códigos son históricos y la unidad A11 tiene dos descripciones. Estos datos no sustituyen la validación normativa del comprobante.</p>
    </div>

    <div class="flex flex-wrap gap-2 border-b border-line pb-3" aria-label="Tipo de referencia">
      <button type="button" :aria-pressed="section === 'catalogs'" :class="section === 'catalogs' ? 'bg-brand text-brandtx' : 'border border-line bg-surf text-foreground hover:bg-soft'" class="inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium" @click="chooseSection('catalogs')">
        <BookOpen class="h-4 w-4" /> Catálogos SEE
      </button>
      <button type="button" :aria-pressed="section === 'ubigeos'" :class="section === 'ubigeos' ? 'bg-brand text-brandtx' : 'border border-line bg-surf text-foreground hover:bg-soft'" class="inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium" @click="chooseSection('ubigeos')">
        <MapPin class="h-4 w-4" /> Ubigeo INEI
      </button>
    </div>

    <div class="rounded-xl border border-line bg-surf p-4 space-y-4">
      <div class="grid gap-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <label v-if="section === 'catalogs'" class="block text-xs font-medium text-muted">
          Catálogo SEE
          <select :value="catalogNumber" class="mt-1.5 w-full rounded-md border border-line bg-surf px-3 py-2.5 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-brand" :disabled="isLoadingCatalogs" @change="chooseCatalog(($event.target as HTMLSelectElement).value)">
            <option v-if="catalogs.length === 0" :value="catalogNumber">{{ isLoadingCatalogs ? 'Cargando catálogos…' : 'Catálogo 02' }}</option>
            <option v-for="catalog in catalogs" :key="catalog.number" :value="catalog.number">{{ catalog.number }} · {{ catalog.name }} ({{ catalog.item_count }})</option>
          </select>
        </label>
        <div v-else class="flex items-end gap-2 text-sm text-muted">
          <Globe2 class="mb-1 h-5 w-5 text-brand" /> 1,874 distritos en el archivo suministrado
        </div>
        <label class="block text-xs font-medium text-muted">
          Buscar por {{ section === 'catalogs' ? 'código o descripción' : 'código o distrito' }}
          <span class="relative mt-1.5 block">
            <Search class="absolute left-3 top-3 h-4 w-4 text-muted" />
            <input v-model="searchInput" type="search" maxlength="100" class="w-full rounded-md border border-line bg-surf py-2.5 pl-9 pr-3 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-brand" :placeholder="section === 'catalogs' ? 'Ej. PEN, NIU, PE…' : 'Ej. 230101, Tacna…'" />
          </span>
        </label>
      </div>

      <p v-if="section === 'catalogs' && currentCatalog" class="text-sm text-muted">
        Catálogo {{ currentCatalog.number }} · {{ currentCatalog.name }} · {{ currentCatalog.item_count.toLocaleString('es-PE') }} registros en el archivo.
      </p>
      <p v-if="error" role="alert" class="rounded-md border border-neg/30 bg-neg/10 px-3 py-2 text-sm text-neg">
        {{ error }} <button type="button" class="ml-2 underline" @click="retry">Reintentar</button>
      </p>
      <p v-if="isLoadingPage" role="status" class="py-8 text-center text-sm text-muted">Cargando referencias…</p>

      <div v-else-if="section === 'catalogs' && catalogPage" class="overflow-x-auto">
        <table class="w-full min-w-[600px] text-left text-sm">
          <thead class="border-b border-line text-xs uppercase tracking-wide text-muted"><tr><th scope="col" class="py-2 pr-4">Código</th><th scope="col" class="py-2 pr-4">Descripción</th><th scope="col" class="py-2">Información adicional</th></tr></thead>
          <tbody>
            <tr v-for="item in catalogPage.data" :key="`${catalogNumber}-${item.source_row}`" class="border-b border-line/60 align-top">
              <td class="py-3 pr-4 font-mono font-semibold text-foreground">{{ item.code }} <span v-if="item.is_ambiguous" class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-900 dark:bg-amber-900 dark:text-amber-100">Ambiguo</span></td>
              <td class="py-3 pr-4 text-foreground">{{ item.description }}</td>
              <td class="py-3 text-xs text-muted"><span v-for="(value, key) in item.details" :key="key" class="mr-2 inline-block">{{ key }}: {{ value }}</span></td>
            </tr>
          </tbody>
        </table>
        <p v-if="catalogPage.data.length === 0" class="py-8 text-center text-sm text-muted">{{ currentCatalog?.item_count === 0 ? 'Este catálogo remite a una fuente externa y no contiene filas en el archivo recibido.' : 'No hay resultados para esta búsqueda.' }}</p>
      </div>

      <div v-else-if="ubigeoPage" class="overflow-x-auto">
        <table class="w-full min-w-[680px] text-left text-sm">
          <thead class="border-b border-line text-xs uppercase tracking-wide text-muted"><tr><th scope="col" class="py-2 pr-4">Ubigeo</th><th scope="col" class="py-2 pr-4">Distrito</th><th scope="col" class="py-2 pr-4">Provincia</th><th scope="col" class="py-2">Departamento</th></tr></thead>
          <tbody><tr v-for="place in ubigeoPage.data" :key="place.code" class="border-b border-line/60"><td class="py-3 pr-4 font-mono font-semibold text-foreground">{{ place.code }}</td><td class="py-3 pr-4 text-foreground">{{ place.district }}</td><td class="py-3 pr-4 text-muted">{{ place.province }}</td><td class="py-3 text-muted">{{ place.department }}</td></tr></tbody>
        </table>
        <p v-if="ubigeoPage.data.length === 0" class="py-8 text-center text-sm text-muted">No hay ubigeos para esta búsqueda.</p>
      </div>

      <div v-if="currentPage && !isLoadingPage" class="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-3 text-xs text-muted">
        <span>{{ pageLabel }} · página {{ currentPage.meta.current_page }} de {{ currentPage.meta.last_page }}</span>
        <div class="flex gap-2">
          <button type="button" class="rounded-md border border-line px-3 py-1.5 text-foreground disabled:opacity-40" :disabled="page <= 1" @click="page--">Anterior</button>
          <button type="button" class="rounded-md border border-line px-3 py-1.5 text-foreground disabled:opacity-40" :disabled="page >= currentPage.meta.last_page" @click="page++">Siguiente</button>
        </div>
      </div>
    </div>
    <p class="text-xs text-muted">Los catálogos para libros PLE y SIRE se mantienen independientes de esta consulta SEE.</p>
  </section>
</template>
