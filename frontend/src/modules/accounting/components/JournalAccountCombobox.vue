<script setup lang="ts">
import { computed, ref, useId, watch } from 'vue';
import type { OperationalAccount } from '../types/journalEntry.types';

/** Search thousands of operating accounts without rendering thousands of <option> nodes. */
const props = withDefaults(defineProps<{
  modelValue: number | null;
  accounts: readonly OperationalAccount[];
  disabled?: boolean;
}>(), { disabled: false });
const emit = defineEmits<{ 'update:modelValue': [id: number | null] }>();
const uid = useId();
const listboxId = `journal-accounts-${uid}`;
const open = ref(false);
const query = ref('');
const activeIndex = ref(0);

const selected = computed(() => props.accounts.find(a => a.id === props.modelValue));
const selectedLabel = computed(() => selected.value ? `${selected.value.code} · ${selected.value.name}` : '');

function fold(value: string): string {
  return value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es-PE');
}

const matches = computed(() => {
  const words = fold(query.value.trim()).split(/\s+/).filter(Boolean);
  if (words.length === 0) return props.accounts.slice(0, 40);
  const found: OperationalAccount[] = [];
  for (const account of props.accounts) {
    const haystack = fold(`${account.code} ${account.name}`);
    if (words.every(word => haystack.includes(word))) found.push(account);
    if (found.length === 40) break;
  }
  return found;
});

watch(query, () => { activeIndex.value = 0; });
watch(() => props.modelValue, () => {
  if (!open.value) query.value = '';
});

function choose(account: OperationalAccount): void {
  emit('update:modelValue', account.id);
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
    if (!open.value) { open.value = true; activeIndex.value = 0; return; }
    activeIndex.value = Math.max(0, Math.min(matches.value.length - 1,
      activeIndex.value + (event.key === 'ArrowDown' ? 1 : -1)));
  }
  if (event.key === 'Enter' && open.value) {
    event.preventDefault();
    const account = matches.value[activeIndex.value];
    if (account) choose(account);
  }
}
</script>

<template>
  <div class="relative min-w-[225px]">
    <input
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
      class="w-full min-w-0 rounded-md border border-line bg-surf px-2.5 py-1.5 text-xs font-mono text-foreground focus:outline-none focus:ring-1 focus:ring-brand disabled:opacity-50"
      @focus="open = true; query = ''"
      @input="query = ($event.target as HTMLInputElement).value; open = true"
      @keydown="handleKey"
      @blur="open = false; query = ''"
    />
    <div
      v-if="open && !disabled"
      :id="listboxId"
      role="listbox"
      class="absolute left-0 top-full z-50 mt-1 max-h-64 w-[min(480px,90vw)] overflow-y-auto rounded-md border border-line bg-surf shadow-lg"
    >
      <button
        v-for="(account, index) in matches"
        :id="`${listboxId}-${index}`"
        :key="account.id"
        type="button"
        role="option"
        :aria-selected="account.id === modelValue"
        class="block w-full px-3 py-2 text-left text-xs hover:bg-soft"
        :class="index === activeIndex ? 'bg-soft text-foreground' : 'text-muted'"
        @mouseenter="activeIndex = index"
        @mousedown.prevent="choose(account)"
      >
        <span class="font-semibold font-mono">{{ account.code }}</span> · {{ account.name }}
      </button>
      <p v-if="matches.length === 0" class="px-3 py-2 text-xs text-muted">No se encontraron cuentas.</p>
      <p v-else-if="matches.length === 40" class="border-t border-line px-3 py-1 text-[10px] text-muted">
        Mostrando 40 resultados. Escriba más para precisar la búsqueda.
      </p>
    </div>
  </div>
</template>
