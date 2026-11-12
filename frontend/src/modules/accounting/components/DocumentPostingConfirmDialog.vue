<script setup lang="ts">
import { Dialog, DialogPanel, DialogTitle, Description } from '@headlessui/vue';
import { AlertTriangle, CheckCircle2 } from 'lucide-vue-next';

defineProps<{
  readonly open: boolean;
  readonly busy: boolean;
  readonly documentLabel: string;
  readonly currency: string;
  readonly total: string;
}>();
const emit = defineEmits<{ (e: 'cancel'): void; (e: 'confirm'): void }>();
</script>

<template>
  <Dialog :open="open" class="relative z-[70]" @close="busy ? undefined : emit('cancel')">
    <div aria-hidden="true" class="fixed inset-0 bg-zinc-950/60" />
    <div class="fixed inset-0 overflow-y-auto p-4">
      <div class="flex min-h-full items-center justify-center">
        <DialogPanel class="w-full max-w-md rounded-xl border border-line bg-surface p-5 text-foreground shadow-2xl">
          <div class="flex items-start gap-3">
            <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-violet-600/10 text-violet-600 dark:text-violet-200">
              <AlertTriangle class="size-5" />
            </div>
            <div class="min-w-0">
              <DialogTitle class="text-base font-semibold">Confirmar contabilización</DialogTitle>
              <Description class="mt-2 text-sm text-muted">
                Se registrará el comprobante <strong class="text-foreground">{{ documentLabel }}</strong>
                por <strong class="text-foreground">{{ currency }} {{ total }}</strong> en el Libro Diario
                y en el registro tributario interno.
              </Description>
              <p class="mt-2 text-xs text-muted">El asiento será inmutable; una corrección se realiza mediante extorno.</p>
            </div>
          </div>
          <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="rounded-lg border border-line bg-background px-4 py-2 text-sm font-medium hover:bg-hover disabled:opacity-50"
              :disabled="busy" @click="emit('cancel')">Cancelar</button>
            <button type="button" class="inline-flex items-center gap-2 rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-700 disabled:opacity-50"
              :disabled="busy" @click="emit('confirm')">
              <CheckCircle2 class="size-4" />
              {{ busy ? 'Registrando…' : 'Confirmar registro' }}
            </button>
          </div>
        </DialogPanel>
      </div>
    </div>
  </Dialog>
</template>
