<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Loader2 } from 'lucide-vue-next';
import type { AccountingApiClient } from '../api/httpClient';
import type { EntityMaster } from '../../company/types/entityMaster.types';
import { entityRequired, entityRoleFilter, type EntityAccountRule } from '../utils/journalEntity';

const props = defineProps<{
  api: AccountingApiClient;
  account?: EntityAccountRule;
  modelValue: EntityMaster | null;
  disabled?: boolean;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: EntityMaster | null] }>();
const open = ref(false);
const search = ref('');
const rows = ref<readonly EntityMaster[]>([]);
const page = ref(1);
const lastPage = ref(1);
const busy = ref(false);
const error = ref('');
const searchInput = ref<HTMLInputElement | null>(null);
const trigger = ref<HTMLButtonElement | null>(null);
const required = computed(() => entityRequired(props.account));
let sequence = 0;
let alive = true;
onBeforeUnmount(() => { alive = false; ++sequence; });
function close(): void {
  ++sequence;
  open.value = false;
  busy.value = false;
  trigger.value?.focus();
}
watch(() => props.account, () => {
  close();
  rows.value = [];
  search.value = '';
  busy.value = false;
  emit('update:modelValue', null);
});
async function load(target = 1): Promise<void> {
  const request = ++sequence;
  busy.value = true;
  error.value = '';
  try {
    const result = await props.api.fetchEntities({ search: search.value,
      role: entityRoleFilter(props.account?.entity_requirement_type), page: target });
    if (!alive || request !== sequence) return;
    rows.value = result.data;
    page.value = result.current_page;
    lastPage.value = result.last_page;
  } catch (e) {
    if (alive && request === sequence) {
      rows.value = [];
      error.value = e instanceof Error ? e.message : 'No se pudieron consultar los anexos.';
    }
  } finally {
    if (alive && request === sequence) busy.value = false;
  }
}
function toggle(): void {
  if (open.value) { close(); return; }
  open.value = true;
  search.value = '';
  page.value = 1;
  void nextTick(() => searchInput.value?.focus());
  void load();
}
function choose(entity: EntityMaster | null): void {
  emit('update:modelValue', entity);
  close();
}
function searchEntities(): void { void load(1); }
</script>

<template>
  <div class="min-w-[200px]">
    <button ref="trigger" type="button" class="w-full rounded-md border border-line bg-surface px-2 py-1.5 text-left text-xs"
      :disabled="disabled || !account" :aria-expanded="open" @click="toggle">
      <span class="block max-w-[220px] truncate">{{ modelValue ? `${modelValue.tax_id} · ${modelValue.legal_name}` : required ? 'Seleccionar anexo obligatorio' : 'Sin anexo' }}</span>
    </button>
    <!-- Dialog outside the scrollable journal grid, never expands its row. -->
    <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-[10000] flex items-center justify-center bg-surface md:bg-slate-950/55 md:p-4"
      @mousedown.self="close" @keydown.esc.stop.prevent="close">
      <div role="dialog" aria-modal="true" aria-label="Buscar anexo contable"
        class="flex h-[100dvh] min-h-0 w-full flex-col gap-4 overflow-hidden bg-surface p-4 text-sm text-foreground shadow-2xl md:h-auto md:max-h-[min(80vh,650px)] md:max-w-2xl md:rounded-xl md:border md:border-border md:p-5">
        <div class="flex items-center justify-between gap-3">
          <strong class="text-sm">Seleccionar anexo / tercero</strong>
          <button type="button" class="rounded border border-border bg-surface px-3 py-2" @click="close">Cerrar</button>
        </div>
      <div class="flex gap-2">
        <input ref="searchInput" v-model="search" maxlength="100" aria-label="Buscar anexo por nombre o documento"
          placeholder="Nombre o documento" class="min-w-0 flex-1 rounded-md border border-border bg-surface px-3 py-2 text-base md:text-sm"
          @keydown.enter.prevent="searchEntities" />
        <button type="button" :disabled="busy" class="inline-flex items-center gap-1 rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white disabled:opacity-40" @click="searchEntities">
          <Loader2 v-if="busy" class="size-4 animate-spin" /> Buscar
        </button>
      </div>
      <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>
      <div v-if="busy" role="status" aria-label="Consultando anexos" class="min-h-0 flex-1 space-y-3 overflow-hidden rounded-md border border-border p-3 md:min-h-[190px]">
        <p class="flex items-center gap-2 text-muted"><Loader2 class="size-4 animate-spin text-brand" /> Consultando anexos…</p>
        <div v-for="row in 3" :key="row" class="ui-skeleton h-10 w-full" aria-hidden="true" />
      </div>
      <div v-else class="min-h-0 flex-1 space-y-1 overflow-y-auto overscroll-contain rounded-md border border-border bg-surface p-2 md:min-h-[120px] md:max-h-[260px]">
        <button v-for="entity in rows" :key="entity.id" type="button"
          class="block w-full rounded-md bg-surface px-3 py-3 text-left text-sm hover:bg-surface-muted" @click="choose(entity)">
          {{ entity.tax_id }} · {{ entity.legal_name }}
        </button>
        <p v-if="!rows.length && !error" class="p-2 text-muted">Sin anexos para esta búsqueda y tipo de cuenta.</p>
      </div>
      <div class="flex items-center justify-between gap-2 border-t border-border pt-3">
        <button type="button" :disabled="busy || page <= 1" @click="load(page - 1)">Anterior</button>
        <span>{{ page }} / {{ lastPage }}</span>
        <button type="button" :disabled="busy || page >= lastPage" @click="load(page + 1)">Siguiente</button>
      </div>
      <button type="button" class="self-start rounded-md border border-border bg-surface px-3 py-2 text-xs text-foreground" @click="choose(null)">Quitar selección</button>
      </div>
    </div>
    </Teleport>
  </div>
</template>
