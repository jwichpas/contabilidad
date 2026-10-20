<script setup lang="ts" generic="T extends string">
/**
 * UiSelect — Listbox personalizado y accesible (patrón WAI-ARIA "select-only combobox").
 *
 * - Panel teletransportado a <body> con posición `fixed`: nunca queda recortado por
 *   contenedores con `overflow` (modales, tablas, inspectores).
 * - Se abre hacia arriba automáticamente si no hay espacio debajo.
 * - Teclado completo: ↑ ↓ Inicio Fin Enter Esc Tab y filtrado escribiendo.
 * - Búsqueda automática cuando hay más de 7 opciones (o forzada con `searchable`).
 */
import { computed, nextTick, onBeforeUnmount, shallowRef, useId, useTemplateRef, watch } from 'vue';
import { Check, ChevronsUpDown, Search } from 'lucide-vue-next';
import type { UiSelectOption } from './types';

const props = withDefaults(
  defineProps<{
    readonly options: readonly UiSelectOption<T>[];
    readonly placeholder?: string;
    readonly searchable?: boolean;
    readonly disabled?: boolean;
    readonly invalid?: boolean;
    readonly size?: 'sm' | 'md';
    readonly variant?: 'default' | 'dark';
    readonly id?: string;
  }>(),
  {
    placeholder: 'Seleccionar…',
    searchable: undefined,
    disabled: false,
    invalid: false,
    size: 'md',
    variant: 'default',
    id: undefined,
  },
);

const model = defineModel<T>({ required: true });

const generatedId = useId();
const listId = `${props.id ?? generatedId}-listbox`;

const triggerRef = useTemplateRef<HTMLButtonElement>('trigger');
const panelRef = useTemplateRef<HTMLDivElement>('panel');
const searchRef = useTemplateRef<HTMLInputElement>('search');
const listRef = useTemplateRef<HTMLUListElement>('list');

const isOpen = shallowRef(false);
const query = shallowRef('');
const activeIndex = shallowRef(0);
const panelStyle = shallowRef<Record<string, string>>({});

const isSearchable = computed(() => props.searchable ?? props.options.length > 7);

const selectedOption = computed(
  () => props.options.find((o) => o.value === model.value) ?? null,
);

const filteredOptions = computed<readonly UiSelectOption<T>[]>(() => {
  const q = query.value.trim().toLowerCase();
  if (q === '') {
    return props.options;
  }
  return props.options.filter((o) =>
    `${o.label} ${o.value} ${o.hint ?? ''} ${o.badge ?? ''}`.toLowerCase().includes(q),
  );
});

const activeDescendant = computed(() =>
  isOpen.value && filteredOptions.value.length > 0
    ? `${listId}-opt-${activeIndex.value}`
    : undefined,
);

watch(query, () => {
  activeIndex.value = 0;
});

function positionPanel(): void {
  const trigger = triggerRef.value;
  if (!trigger) {
    return;
  }
  const rect = trigger.getBoundingClientRect();
  const viewportH = window.innerHeight;
  const viewportW = window.innerWidth;
  const spaceBelow = viewportH - rect.bottom;
  const spaceAbove = rect.top;
  const openUpwards = spaceBelow < 280 && spaceAbove > spaceBelow;
  const width = Math.max(rect.width, 240);
  const left = Math.min(Math.max(8, rect.left), viewportW - width - 8);
  const maxHeight = Math.min(340, (openUpwards ? spaceAbove : spaceBelow) - 14);

  panelStyle.value = {
    left: `${left}px`,
    width: `${width}px`,
    maxHeight: `${Math.max(160, maxHeight)}px`,
    ...(openUpwards
      ? { bottom: `${viewportH - rect.top + 6}px` }
      : { top: `${rect.bottom + 6}px` }),
  };
}

function scrollActiveIntoView(): void {
  const el = listRef.value?.querySelector<HTMLElement>(`#${CSS.escape(`${listId}-opt-${activeIndex.value}`)}`);
  el?.scrollIntoView({ block: 'nearest' });
}

function onDocumentPointerDown(event: PointerEvent): void {
  const target = event.target as Node | null;
  if (
    target !== null &&
    !triggerRef.value?.contains(target) &&
    !panelRef.value?.contains(target)
  ) {
    close(false);
  }
}

function onViewportChange(): void {
  if (isOpen.value) {
    positionPanel();
  }
}

function attachGlobalListeners(): void {
  document.addEventListener('pointerdown', onDocumentPointerDown, true);
  window.addEventListener('scroll', onViewportChange, true);
  window.addEventListener('resize', onViewportChange);
}

function detachGlobalListeners(): void {
  document.removeEventListener('pointerdown', onDocumentPointerDown, true);
  window.removeEventListener('scroll', onViewportChange, true);
  window.removeEventListener('resize', onViewportChange);
}

async function open(): Promise<void> {
  if (props.disabled || isOpen.value) {
    return;
  }
  query.value = '';
  const selectedIdx = props.options.findIndex((o) => o.value === model.value);
  activeIndex.value = selectedIdx >= 0 ? selectedIdx : 0;
  positionPanel();
  isOpen.value = true;
  attachGlobalListeners();
  await nextTick();
  if (isSearchable.value) {
    searchRef.value?.focus();
  } else {
    listRef.value?.focus();
  }
  scrollActiveIntoView();
}

function close(refocusTrigger = true): void {
  if (!isOpen.value) {
    return;
  }
  isOpen.value = false;
  detachGlobalListeners();
  if (refocusTrigger) {
    triggerRef.value?.focus();
  }
}

function selectOption(option: UiSelectOption<T>): void {
  model.value = option.value;
  close();
}

function moveActive(delta: number): void {
  const total = filteredOptions.value.length;
  if (total === 0) {
    return;
  }
  activeIndex.value = (activeIndex.value + delta + total) % total;
  void nextTick(scrollActiveIntoView);
}

function onTriggerKeydown(event: KeyboardEvent): void {
  if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
    event.preventDefault();
    void open();
  }
}

function onPanelKeydown(event: KeyboardEvent): void {
  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault();
      moveActive(1);
      break;
    case 'ArrowUp':
      event.preventDefault();
      moveActive(-1);
      break;
    case 'Home':
      event.preventDefault();
      activeIndex.value = 0;
      void nextTick(scrollActiveIntoView);
      break;
    case 'End':
      event.preventDefault();
      activeIndex.value = Math.max(0, filteredOptions.value.length - 1);
      void nextTick(scrollActiveIntoView);
      break;
    case 'Enter': {
      event.preventDefault();
      const option = filteredOptions.value[activeIndex.value];
      if (option) {
        selectOption(option);
      }
      break;
    }
    case 'Escape':
      event.preventDefault();
      event.stopPropagation();
      close();
      break;
    case 'Tab':
      close(false);
      break;
  }
}

onBeforeUnmount(detachGlobalListeners);
</script>

<template>
  <div class="ui-select">
    <button
      :id="id"
      ref="trigger"
      type="button"
      class="ui-select-trigger"
      :class="{
        'is-open': isOpen,
        'is-invalid': invalid,
        'ui-select-trigger-sm': size === 'sm',
        'ui-select-trigger-dark': variant === 'dark',
      }"
      :disabled="disabled"
      role="combobox"
      aria-haspopup="listbox"
      :aria-expanded="isOpen"
      :aria-controls="listId"
      :aria-invalid="invalid || undefined"
      @click="isOpen ? close() : open()"
      @keydown="onTriggerKeydown"
    >
      <span v-if="selectedOption" class="ui-select-value">
        <span v-if="selectedOption.badge" class="ui-select-badge">{{ selectedOption.badge }}</span>
        <span class="truncate">{{ selectedOption.label }}</span>
      </span>
      <span v-else class="ui-select-placeholder">{{ placeholder }}</span>
      <ChevronsUpDown class="ui-select-chevron" aria-hidden="true" />
    </button>

    <Teleport to="body">
      <Transition name="ui-pop">
        <div
          v-if="isOpen"
          ref="panel"
          class="ui-dropdown"
          :style="panelStyle"
          @keydown="onPanelKeydown"
        >
          <div v-if="isSearchable" class="ui-dropdown-search">
            <Search aria-hidden="true" />
            <input
              ref="search"
              v-model="query"
              type="text"
              class="ui-dropdown-search-input"
              placeholder="Escribe para filtrar…"
              autocomplete="off"
              aria-label="Filtrar opciones"
              :aria-controls="listId"
              :aria-activedescendant="activeDescendant"
            />
          </div>

          <ul
            :id="listId"
            ref="list"
            role="listbox"
            tabindex="-1"
            class="ui-dropdown-list"
            :aria-activedescendant="activeDescendant"
          >
            <li
              v-for="(option, idx) in filteredOptions"
              :id="`${listId}-opt-${idx}`"
              :key="option.value"
              role="option"
              :aria-selected="option.value === model"
              class="ui-dropdown-item"
              :class="{
                'is-active': idx === activeIndex,
                'is-selected': option.value === model,
              }"
              @pointerenter="activeIndex = idx"
              @click="selectOption(option)"
            >
              <span v-if="option.badge" class="ui-dropdown-badge">{{ option.badge }}</span>
              <span class="flex-1 min-w-0">
                <span class="block truncate">{{ option.label }}</span>
                <span v-if="option.hint" class="ui-dropdown-hint">{{ option.hint }}</span>
              </span>
              <Check v-if="option.value === model" class="ui-dropdown-check" aria-hidden="true" />
            </li>
            <li v-if="filteredOptions.length === 0" class="ui-dropdown-empty">
              Sin coincidencias para “{{ query }}”
            </li>
          </ul>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>
