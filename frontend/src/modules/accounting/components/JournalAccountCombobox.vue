<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, useId, watch } from 'vue';
import type { OperationalAccount } from '../types/journalEntry.types';

const props = withDefaults(defineProps<{
  modelValue: number | null;
  accounts: readonly OperationalAccount[];
  disabled?: boolean;
}>(), { disabled: false });
const emit = defineEmits<{ 'update:modelValue': [id: number | null] }>();
const uid = useId();
const listboxId = `journal-accounts-${uid}`;
const inputEl = ref<HTMLInputElement | null>(null);
const open = ref(false);
const query = ref('');
const activeIndex = ref(0);
const popupStyle = ref<Record<string, string>>({});

const selected = computed(() => props.accounts.find(a => a.id === props.modelValue));
const selectedLabel = computed(() => selected.value ? `${selected.value.code} · ${selected.value.name}` : '');
function fold(value: string): string {
  return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es-PE');
}
const matches = computed(() => {
  const words = fold(query.value.trim()).split(/\s+/).filter(Boolean);
  const found: OperationalAccount[] = [];
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
  open.value = true;
  query.value = '';
  await nextTick();
  reposition();
}
function choose(account: OperationalAccount): void {
  if (account.id !== props.modelValue) emit('update:modelValue', account.id);
  open.value = false;
  query.value = '';
}
function handleKey(event: KeyboardEvent): void {
  if (props.disabled) return;
  if (event.key === 'Escape') {
    open.value = false;
    query.value = '';
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
  window.addEventListener('resize', reposition);
  window.addEventListener('scroll', reposition, true);
});
onBeforeUnmount(() => {
  window.removeEventListener('resize', reposition);
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
      placeholder="Buscar código o nombre de cuenta..."
      autocomplete="off"
      class="w-full rounded-md border border-line bg-surf px-2.5 py-2 text-xs font-mono text-foreground focus:outline-none focus:ring-1 focus:ring-brand disabled:opacity-50"
      @focus="show"
      @input="query = ($event.target as HTMLInputElement).value; open = true; reposition()"
      @keydown="handleKey"
      @blur="open = false; query = ''"
    />
    <!-- Teleport prevents the horizontal accounting table from clipping results. -->
    <Teleport to="body">
      <div v-if="open && !disabled" :id="listboxId" role="listbox"
        :style="popupStyle"
        class="overflow-y-auto rounded-lg border border-line bg-surf shadow-xl"
      >
        <button v-for="(account, index) in matches"
          :id="`${listboxId}-${index}`" :key="account.id" type="button" role="option"
          :aria-selected="account.id === modelValue"
          class="block w-full border-b border-line/50 px-3 py-2.5 text-left text-xs hover:bg-soft"
          :class="index === activeIndex ? 'bg-soft text-foreground' : 'text-muted'"
          @mouseenter="activeIndex = index" @mousedown.prevent="choose(account)">
          <span class="font-semibold font-mono">{{ account.code }}</span> · {{ account.name }}
        </button>
        <p v-if="matches.length === 0" class="p-3 text-xs text-muted">No se encontraron cuentas.</p>
        <p v-else-if="matches.length === 40" class="border-t border-line p-2 text-[11px] text-muted">
          Hasta 40 resultados. Escriba más para filtrar.
        </p>
      </div>
    </Teleport>
  </div>
</template>
