<script setup lang="ts">
/**
 * Botón base para acciones del ERP.
 * Conserva las clases .ui-btn existentes y no altera los eventos de dominio.
 */
import { Loader2 } from 'lucide-vue-next';

withDefaults(
  defineProps<{
    readonly variant?: 'primary' | 'accent' | 'secondary' | 'ghost' | 'danger';
    readonly size?: 'md' | 'sm' | 'icon';
    readonly type?: 'button' | 'submit' | 'reset';
    readonly disabled?: boolean;
    readonly loading?: boolean;
    /** Obligatoria al usar size="icon" sin texto visible. */
    readonly ariaLabel?: string;
  }>(),
  {
    variant: 'secondary',
    size: 'md',
    type: 'button',
    disabled: false,
    loading: false,
    ariaLabel: undefined,
  },
);
</script>

<template>
  <button
    :type="type"
    :disabled="disabled || loading"
    :aria-label="ariaLabel"
    :aria-busy="loading || undefined"
    class="ui-btn"
    :class="[
      `ui-btn-${variant}`,
      { 'ui-btn-sm': size === 'sm', 'ui-btn-icon': size === 'icon' },
    ]"
  >
    <Loader2 v-if="loading" class="animate-spin" aria-hidden="true" />
    <slot />
    <span v-if="loading" class="sr-only">Procesando…</span>
  </button>
</template>
