<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
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
const required = computed(() => entityRequired(props.account));
let sequence = 0;
let alive = true;
onBeforeUnmount(() => { alive = false; ++sequence; });
watch(() => props.account, () => {
  ++sequence;
  open.value = false;
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
  open.value = !open.value;
  if (open.value) void load();
}
function choose(entity: EntityMaster | null): void {
  emit('update:modelValue', entity);
  open.value = false;
}
</script>

<template>
  <div class="min-w-[200px]">
    <button type="button" class="w-full rounded-md border border-line px-2 py-1.5 text-left text-xs"
      :disabled="disabled || !account" :aria-expanded="open" @click="toggle">
      <span class="block max-w-[220px] truncate">{{ modelValue ? `${modelValue.tax_id} · ${modelValue.legal_name}` : required ? 'Seleccionar anexo obligatorio' : 'Sin anexo' }}</span>
    </button>
    <!-- Dialog outside the scrollable journal grid, never expands its row. -->
    <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-[10000] flex items-center justify-center bg-slate-950/45 p-4"
      @mousedown.self="open = false">
      <div role="dialog" aria-modal="true" aria-label="Buscar anexo contable"
        class="flex max-h-[min(80vh,650px)] w-full max-w-2xl flex-col gap-4 overflow-y-auto rounded-xl border border-line bg-surf p-5 text-sm text-foreground shadow-2xl">
        <div class="flex items-center justify-between gap-3">
          <strong class="text-sm">Seleccionar anexo / tercero</strong>
          <button type="button" class="rounded border border-line px-3 py-1" @click="open = false">Cerrar</button>
        </div>
      <div class="flex gap-1">
        <input v-model="search" maxlength="100" aria-label="Buscar anexo por nombre o documento"
          placeholder="Nombre o documento" class="min-w-0 flex-1 rounded-md border border-line bg-surf px-3 py-2 text-sm"
          @keydown.enter.prevent="load()" />
        <button type="button" :disabled="busy" class="rounded-md bg-brand px-4 py-2 text-sm font-semibold text-white disabled:opacity-40" @click="load()">Buscar</button>
      </div>
      <p v-if="error" role="alert" class="text-red-600">{{ error }}</p>
      <p v-if="busy" role="status">Consultando anexos…</p>
      <div v-else class="min-h-[88px] max-h-[260px] space-y-1 overflow-y-auto rounded-md border border-line p-2">
        <button v-for="entity in rows" :key="entity.id" type="button"
          class="block w-full rounded-md px-3 py-2 text-left text-sm hover:bg-soft" @click="choose(entity)">
          {{ entity.tax_id }} · {{ entity.legal_name }}
        </button>
        <p v-if="!rows.length && !error">Sin anexos para esta búsqueda y tipo de cuenta.</p>
      </div>
      <div class="flex items-center justify-between gap-2 border-t border-line pt-3">
        <button type="button" :disabled="busy || page <= 1" @click="load(page - 1)">Anterior</button>
        <span>{{ page }} / {{ lastPage }}</span>
        <button type="button" :disabled="busy || page >= lastPage" @click="load(page + 1)">Siguiente</button>
      </div>
      <button type="button" class="self-start rounded-md border border-line px-3 py-2 text-xs text-foreground" @click="choose(null)">Quitar selección</button>
      </div>
    </div>
    </Teleport>
  </div>
</template>
