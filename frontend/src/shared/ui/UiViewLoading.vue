<template>
  <div class="workspace-section space-y-4 p-5" role="status" aria-live="polite" aria-label="Cargando módulo">
    <div class="flex items-center gap-3">
      <span class="ui-skeleton block size-9 rounded-md" aria-hidden="true" />
      <div class="flex-1 space-y-2">
        <div class="ui-skeleton h-4 w-40 max-w-full" aria-hidden="true" />
        <div class="ui-skeleton h-3 w-64 max-w-full" aria-hidden="true" />
      </div>
    </div>
    <div class="ui-skeleton h-10 w-full" aria-hidden="true" />
    <div class="ui-skeleton h-36 w-full" aria-hidden="true" />
    <span class="sr-only">Cargando módulo contable…</span>
  </div>
</template>
