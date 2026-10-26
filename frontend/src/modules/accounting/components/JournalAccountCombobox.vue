<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import type { JournalEditorAccount } from '../types/journalEntry.types';

const props = withDefaults(defineProps<{
  modelValue: number | null;
  accounts: readonly JournalEditorAccount[];
  disabled?: boolean;
}>(), { disabled: false });
const emit = defineEmits<{ 'update:modelValue': [id: number | null] }>();
const uid = useId();
const listboxId = `journal-accounts-${uid}`;
const inputEl = ref<HTMLInputElement | null>(null);
const mobileSearchEl = ref<HTMLInputElement | null>(null);
const open = ref(false);
const mobileDialog = ref(false);
const mobileViewport = ref(false);
const query = ref('');
const activeIndex = ref(0);
const popupStyle = ref<Record<string, string>>({});

function syncViewport(): void {
  mobileViewport.value = window.innerWidth < 768;
  if (open.value && mobileDialog.value !== mobileViewport.value) close();
  else reposition();
}

const selected = computed(() => props.accounts.find(a => a.id === props.modelValue));
const selectedLabel = computed(() => selected.value ? `${selected.value.code} · ${selected.value.name}` : '');
function fold(value: string): string {
  return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es-PE');
}
const matches = computed(() => {
  const words = fold(query.value.trim()).split(/\s+/).filter(Boolean);
  const found: JournalEditorAccount[] = [];
  for (const a of props.accounts) {
    if (words.length && !words.every(word => fold(`${a.code} ${a.name}`).includes(word))) continue;
    found.push(a);
    if (found.length === 40) break;
  }
  return found;
});
watch(query, () => { activeIndex.value = 0; });
watch(() => props.modelValue, () => {
  if (!open.value) query.value = '';
});

function reposition(): void {
  if (!open.value || !inputEl.value) return;
  const rect = inputEl.value.getBoundingClientRect();
  const width = Math.min(Math.max(rect.width, 340), window.innerWidth - 16);
  const left = Math.min(Math.max(8, rect.left), Math.max(8, window.innerWidth - width - 8));
  const showAbove = rect.bottom + 275 > window.innerHeight && rect.top > 280;
  popupStyle.value = {
    position: 'fixed',
    zIndex: '9999',
    left: `${left}px`,
    top: showAbove ? 'auto' : `${rect.bottom + 4}px`,
    bottom: showAbove ? `${window.innerHeight - rect.top + 4}px` : 'auto',
    width: `${width}px`,
    maxHeight: '260px',
  };
}
async function show(): Promise<void> {
  if (props.disabled) return;
  mobileViewport.value = window.innerWidth < 768;
  mobileDialog.value = mobileViewport.value;
  open.value = true;
  query.value = '';
  await nextTick();
  if (mobileDialog.value) mobileSearchEl.value?.focus();
  else reposition();
}
function close(): void {
  open.value = false;
  query.value = '';
  mobileDialog.value = false;
}
function choose(account: JournalEditorAccount): void {
  if (account.id !== props.modelValue) emit('update:modelValue', account.id);
  close();
}
function handleKey(event: KeyboardEvent): void {
  if (props.disabled) return;
  if (event.key === 'Escape') {
    close();
    return;
  }
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault();
    if (!open.value) { void show(); return; }
    activeIndex.value = Math.max(0, Math.min(matches.value.length - 1,
      activeIndex.value + (event.key === 'ArrowDown' ? 1 : -1)));
  }
  if (event.key === 'Enter' && open.value) {
    event.preventDefault();
    const account = matches.value[activeIndex.value];
    if (account) choose(account);
  }
}
onMounted(() => {
  syncViewport();
  window.addEventListener('resize', syncViewport);
  window.addEventListener('scroll', reposition, true);
});
onBeforeUnmount(() => {
  window.removeEventListener('resize', syncViewport);
  window.removeEventListener('scroll', reposition, true);
});
</script>

<template>
  <div class="min-w-[235px]">
    <input
      ref="inputEl"
      type="text"
      role="combobox"
      :aria-controls="listboxId"
      aria-autocomplete="list"
      :aria-expanded="open"
      :aria-activedescendant="open && matches.length ? `${listboxId}-${activeIndex}` : undefined"
      :value="open ? query : selectedLabel"
      :disabled="disabled"
      :readonly="mobileViewport"
      placeholder="Buscar código o nombre de cuenta..."
      autocomplete="off"
      class="w-full rounded-md border border-border bg-surface px-2.5 py-2 text-xs font-mono text-foreground focus:outline-none focus:ring-1 focus:ring-brand disabled:opacity-50"
      @focus="show"
      @input="query = ($event.target as HTMLInputElement).value; open = true; reposition()"
      @keydown="handleKey"
      @blur="mobileDialog || close()"
    />
    <!-- Desktop popup and mobile dialog share the same local filtered results. -->
    <Teleport to="body">
      <div v-if="open && mobileDialog && !disabled" role="dialog" aria-modal="true" aria-label="Buscar cuenta contable"
        class="fixed inset-0 z-[10000] flex h-[100dvh] flex-col bg-surface text-foreground">
        <div class="flex items-center justify-between gap-3 border-b border-border p-4">
          <strong class="text-base">Seleccionar cuenta</strong>
          <button type="button" class="rounded-md border border-border px-3 py-2 text-sm" @click="close">Cerrar</button>
        </div>
        <div class="border-b border-border p-4">
          <input ref="mobileSearchEl" v-model="query" type="search" autocomplete="off"
            role="combobox" aria-autocomplete="list" :aria-expanded="open"
            :aria-activedescendant="matches.length ? `${listboxId}-${activeIndex}` : undefined"
            aria-label="Buscar cuenta por código o nombre" :aria-controls="listboxId"
            placeholder="Código o nombre de cuenta"
            class="w-full rounded-md border border-border bg-surface px-3 py-3 text-base text-foreground focus:outline-none focus:ring-2 focus:ring-brand"
            @keydown="handleKey" />
        </div>
        <div :id="listboxId" role="listbox" class="min-h-0 flex-1 overflow-y-auto overscroll-contain bg-surface">
          <button v-for="(account, index) in matches" :id="`${listboxId}-${index}`" :key="account.id"
            type="button" role="option" :aria-selected="account.id === modelValue"
            class="block w-full border-b border-border px-4 py-3 text-left text-sm text-foreground"
            :class="index === activeIndex ? 'bg-surface-muted' : 'bg-surface'"
            @click="choose(account)">
            <span class="font-semibold font-mono">{{ account.code }}</span> · {{ account.name }}
          </button>
          <p v-if="matches.length === 0" class="p-4 text-sm text-muted">No se encontraron cuentas.</p>
          <p v-else-if="matches.length === 40" class="p-4 text-xs text-muted">Hasta 40 resultados. Escriba más para filtrar.</p>
        </div>
      </div>
      <div v-else-if="open && !disabled" :id="listboxId" role="listbox"
        :style="popupStyle"
        class="overflow-y-auto rounded-lg border border-border bg-surface text-foreground shadow-xl"
      >
        <button v-for="(account, index) in matches"
          :id="`${listboxId}-${index}`" :key="account.id" type="button" role="option"
          :aria-selected="account.id === modelValue"
          class="block w-full border-b border-border px-3 py-2.5 text-left text-xs hover:bg-surface-muted"
          :class="index === activeIndex ? 'bg-surface-muted text-foreground' : 'bg-surface text-foreground'"
          @mouseenter="activeIndex = index" @mousedown.prevent="choose(account)">
          <span class="font-semibold font-mono">{{ account.code }}</span> · {{ account.name }}
        </button>
        <p v-if="matches.length === 0" class="p-3 text-xs text-muted">No se encontraron cuentas.</p>
        <p v-else-if="matches.length === 40" class="border-t border-border p-2 text-[11px] text-muted">
          Hasta 40 resultados. Escriba más para filtrar.
        </p>
      </div>
    </Teleport>
  </div>
</template>
