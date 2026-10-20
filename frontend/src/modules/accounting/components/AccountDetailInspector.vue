<script setup lang="ts">
import {
  ArrowRight,
  Ban,
  Banknote,
  Building2,
  CheckCircle2,
  Coins,
  FileSpreadsheet,
  GitBranch,
  Hash,
  Layers,
  Pencil,
  Plus,
  ShieldCheck,
  Sparkles,
  Workflow,
  X,
} from 'lucide-vue-next';
import {
  ACCOUNT_CURRENCY_MODE_LABELS,
  ACCOUNT_TYPE_LABELS,
  BALANCE_TYPE_LABELS,
  CASH_FLOW_CATEGORY_LABELS,
  ENTITY_REQUIREMENT_LABELS,
  FINANCIAL_STATEMENT_RUBRIC_LABELS,
  type FlattenedAccountRow,
} from '../types/accountingAccount.types';

defineProps<{
  readonly selectedRow: FlattenedAccountRow | null;
  readonly isMobileOpen: boolean;
}>();

const emit = defineEmits<{
  closeMobile: [];
  createChild: [row: FlattenedAccountRow];
  editAccount: [row: FlattenedAccountRow];
  deactivateAccount: [row: FlattenedAccountRow];
}>();

const levelName = (level: number): string => {
  switch (level) {
    case 1:
      return 'Nivel 1 · Elemento PCGE';
    case 2:
      return 'Nivel 2 · Cuenta Mayor';
    case 3:
      return 'Nivel 3 · Subcuenta Rubro';
    default:
      return 'Nivel 4 · Subcuenta Analítica Operativa';
  }
};
</script>

<template>
  <!-- Backdrop en móvil/tablet -->
  <div
    v-if="isMobileOpen && selectedRow"
    class="fixed inset-0 z-40 bg-slate-950/60 backdrop-blur-xs xl:hidden"
    @click="emit('closeMobile')"
  />

  <aside
    v-if="selectedRow"
    :class="[
      'bg-surf border border-line rounded-lg shadow-sm flex flex-col overflow-hidden transition-all duration-200',
      isMobileOpen
        ? 'fixed inset-y-3 right-3 z-50 w-[min(94vw,430px)] shadow-2xl xl:static xl:w-auto xl:shadow-sm'
        : 'hidden xl:flex xl:sticky xl:top-20 xl:max-h-[calc(100vh-6.25rem)]',
    ]"
    aria-label="Ficha técnica de cuenta contable"
  >
    <!-- Cabecera del Inspector -->
    <div class="bg-brand text-brandtx px-4 py-3.5 border-b border-white/15">
      <div class="flex items-start justify-between gap-2">
        <div class="flex items-center gap-2">
          <span
            class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-mono font-semibold bg-white/20 text-white"
          >
            {{ selectedRow.node.code }}
          </span>
          <span
            class="tag text-[10px]"
            :class="
              selectedRow.node.is_operational
                ? 'ok'
                : 'border-white/30 text-white/80'
            "
          >
            {{ selectedRow.node.is_operational ? 'Imputable (Hoja)' : 'Agrupadora' }}
          </span>
          <span
            v-if="!selectedRow.node.is_active"
            class="tag er text-[10px]"
          >
            Inactiva
          </span>
        </div>

        <button
          type="button"
          class="xl:hidden rounded-lg p-1 text-slate-300 hover:bg-white/10 hover:text-white"
          title="Cerrar inspector"
          @click="emit('closeMobile')"
        >
          <X class="w-4 h-4" />
        </button>
      </div>

      <h3 class="mt-2 text-sm font-bold leading-snug text-white">
        {{ selectedRow.node.name }}
      </h3>

      <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-300 font-mono">
        <span class="inline-flex items-center gap-1">
          <Hash class="w-3 h-3 text-brand-soft" />
          ID(Int64): {{ selectedRow.node.id }}
        </span>
        <span class="inline-flex items-center gap-1">
          <Layers class="w-3 h-3 text-brand-soft" />
          Short(Int16): N{{ selectedRow.node.level }}
        </span>
        <span class="inline-flex items-center gap-1">
          <GitBranch class="w-3 h-3 text-brand-soft" />
          {{ selectedRow.node.path }}
        </span>
      </div>
    </div>

    <!-- Cuerpo desplazable con las 5 dimensiones contables -->
    <div class="p-4 space-y-3.5 overflow-y-auto text-xs flex-1">
      <!-- Clasificación PCGE y Naturaleza -->
      <div class="rounded-md bg-surf border border-line p-3 space-y-2">
        <div class="flex items-center justify-between">
          <span class="text-[11px] font-semibold uppercase tracking-wider text-muted">
            Clasificación Contable
          </span>
          <span class="text-[11px] font-mono text-muted">
            {{ levelName(selectedRow.node.level) }}
          </span>
        </div>

        <div class="grid grid-cols-2 gap-2 pt-1">
          <div class="rounded bg-soft p-2 border border-line">
            <span class="block text-[10px] uppercase text-muted">Elemento / Tipo</span>
            <span class="font-semibold text-foreground">
              {{ ACCOUNT_TYPE_LABELS[selectedRow.node.account_type] }}
            </span>
          </div>
          <div class="rounded bg-soft p-2 border border-line">
            <span class="block text-[10px] uppercase text-muted">Saldo Natural</span>
            <span
              class="font-semibold"
              :class="
                selectedRow.node.balance_type === 'DEBIT'
                  ? 'text-brand'
                  : 'text-accent-ink'
              "
            >
              {{ BALANCE_TYPE_LABELS[selectedRow.node.balance_type] }}
            </span>
          </div>
        </div>
      </div>

      <!-- 1. Moneda y Bimoneda Simultánea -->
      <div class="rounded-md bg-surf border border-line p-3 space-y-1.5">
        <div class="flex items-center justify-between">
          <span class="inline-flex items-center gap-1.5 font-semibold text-foreground">
            <Coins class="w-3.5 h-3.5 text-brand" />
            1. Régimen de Moneda
          </span>
          <span
            class="tag text-[10px] font-mono"
            :class="
              selectedRow.node.currency_mode === 'USD'
                ? 'ok'
                : selectedRow.node.currency_mode === 'MULTI'
                  ? 'wa'
                  : 'text-muted border-line'
            "
          >
            {{ selectedRow.node.currency_mode }}
          </span>
        </div>
        <p class="text-muted leading-relaxed">
          {{ ACCOUNT_CURRENCY_MODE_LABELS[selectedRow.node.currency_mode] }}
        </p>
        <div
          v-if="selectedRow.node.requires_currency"
          class="flex items-center gap-1.5 text-[11px] text-pos font-medium pt-0.5"
        >
          <CheckCircle2 class="w-3.5 h-3.5 shrink-0" />
          Exige Tipo de Cambio exacto NUMERIC(14,6) y reexpresión NIC 21.
        </div>
      </div>

      <!-- 2. Entidad / Anexo Tributario -->
      <div class="rounded-md bg-surf border border-line p-3 space-y-1.5">
        <div class="flex items-center justify-between">
          <span class="inline-flex items-center gap-1.5 font-semibold text-foreground">
            <Building2 class="w-3.5 h-3.5 text-brand" />
            2. Control de Entidad / Anexo
          </span>
          <span
            class="tag text-[10px] font-mono"
            :class="
              selectedRow.node.requires_auxiliary
                ? 'ok'
                : 'text-muted border-line'
            "
          >
            {{ selectedRow.node.requires_auxiliary ? 'Obligatorio' : 'No requerido' }}
          </span>
        </div>
        <p class="text-muted">
          {{ ENTITY_REQUIREMENT_LABELS[selectedRow.node.entity_requirement_type] }}
        </p>
      </div>

      <!-- 3. Centro de Costos y Destino Automático 94/95 vs 79 -->
      <div class="rounded-md bg-surf border border-line p-3 space-y-2">
        <div class="flex items-center justify-between">
          <span class="inline-flex items-center gap-1.5 font-semibold text-foreground">
            <Workflow class="w-3.5 h-3.5 text-accent" />
            3. Centro de Costos y Destino (Elem. 9)
          </span>
          <span
            class="tag text-[10px] font-mono"
            :class="
              selectedRow.node.requires_cost_center || selectedRow.node.has_automatic_destination
                ? 'wa'
                : 'text-muted border-line'
            "
          >
            {{
              selectedRow.node.has_automatic_destination
                ? 'Destino Activo'
                : selectedRow.node.requires_cost_center
                  ? 'Exige CeCo'
                  : 'Sin Destino'
            }}
          </span>
        </div>

        <div
          v-if="selectedRow.node.has_automatic_destination"
          class="rounded-md bg-soft p-2.5 border border-line space-y-1.5"
        >
          <div class="text-[10px] font-semibold uppercase tracking-wider text-accent flex items-center gap-1">
            <Sparkles class="w-3 h-3" />
            Asiento Automático de Transferencia
          </div>
          <div class="flex items-center justify-between font-mono text-xs pt-0.5">
            <div class="px-2 py-1 rounded bg-surf text-foreground border border-line">
              <span class="text-[10px] text-muted block">DEBE (Gasto Función)</span>
              <strong class="text-accent">Cta. {{ selectedRow.node.destination_debit_code }}</strong>
            </div>
            <ArrowRight class="w-4 h-4 text-muted shrink-0" />
            <div class="px-2 py-1 rounded bg-surf text-foreground border border-line">
              <span class="text-[10px] text-muted block">HABER (CICC)</span>
              <strong class="text-brand">Cta. {{ selectedRow.node.destination_credit_code }}</strong>
            </div>
          </div>
        </div>
        <p v-else class="text-muted">
          {{
            selectedRow.node.requires_cost_center
              ? 'Exige imputar Centro de Costos para distribuir en 94 (Administración) o 95 (Ventas).'
              : 'Cuenta sin transferencia automática al Elemento 9.'
          }}
        </p>
      </div>

      <!-- 4. Área de Flujo de Efectivo (Cuenta 10 - NIC 7) -->
      <div class="rounded-md bg-surf border border-line p-3 space-y-1.5">
        <div class="flex items-center justify-between">
          <span class="inline-flex items-center gap-1.5 font-semibold text-foreground">
            <Banknote class="w-3.5 h-3.5 text-pos" />
            4. Flujo de Efectivo (Cta. 10 / NIC 7)
          </span>
          <span
            class="tag text-[10px] font-mono"
            :class="
              selectedRow.node.requires_cash_flow_area
                ? 'ok'
                : 'text-muted border-line'
            "
          >
            {{ selectedRow.node.requires_cash_flow_area ? 'Exige Área' : 'Estándar' }}
          </span>
        </div>
        <p class="text-muted">
          {{ CASH_FLOW_CATEGORY_LABELS[selectedRow.node.cash_flow_category] }}
        </p>
      </div>

      <!-- 5. Rubro para Reportes / Estados Financieros (ej. 1041 = Efectivo y Equivalentes) -->
      <div class="rounded-md bg-surf border border-line p-3 space-y-1.5">
        <div class="flex items-center justify-between">
          <span class="inline-flex items-center gap-1.5 font-semibold text-foreground">
            <FileSpreadsheet class="w-3.5 h-3.5 text-brand" />
            5. Rubro en Estados Financieros
          </span>
          <span
            class="px-2 py-0.5 rounded font-mono text-[10px] font-semibold bg-soft text-brand border border-line"
          >
            {{ selectedRow.node.financial_statement_rubric }}
          </span>
        </div>
        <p class="font-medium text-foreground">
          {{ FINANCIAL_STATEMENT_RUBRIC_LABELS[selectedRow.node.financial_statement_rubric] }}
        </p>
      </div>
    </div>

    <!-- Acciones rápidas del Inspector -->
    <div class="p-3 bg-soft border-t border-line flex flex-wrap items-center gap-2">
      <button
        type="button"
        class="ui-btn ui-btn-primary flex-1"
        @click="emit('editAccount', selectedRow)"
      >
        <Pencil class="w-3.5 h-3.5" />
        Configurar (Enter)
      </button>

      <button
        v-if="!selectedRow.node.is_operational && selectedRow.node.level < 4 && selectedRow.node.is_active"
        type="button"
        class="ui-btn ui-btn-secondary"
        @click="emit('createChild', selectedRow)"
      >
        <Plus class="w-3.5 h-3.5" />
        Subcuenta
      </button>

      <button
        v-if="selectedRow.node.is_active"
        type="button"
        class="ui-btn ui-btn-danger ui-btn-icon"
        title="Desactivar cuenta (Supr)"
        @click="emit('deactivateAccount', selectedRow)"
      >
        <Ban class="w-3.5 h-3.5" />
      </button>
    </div>

    <!-- Pie de integridad SQL -->
    <div class="px-4 py-2 bg-surf border-t border-line flex items-center justify-between text-[10px] text-muted">
      <span class="inline-flex items-center gap-1">
        <ShieldCheck class="w-3.5 h-3.5 text-ok" />
        FK Compuesta (company_id, id)
      </span>
      <span class="font-mono">NUMERIC(14,4)</span>
    </div>
  </aside>
</template>
