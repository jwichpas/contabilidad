<script setup lang="ts">
import {
  Ban,
  Banknote,
  Building2,
  ChevronDown,
  ChevronRight,
  Coins,
  Eye,
  FileSpreadsheet,
  FolderTree,
  Pencil,
  Plus,
  Workflow,
} from 'lucide-vue-next';
import {
  ACCOUNT_TYPE_LABELS,
  BALANCE_TYPE_LABELS,
  ENTITY_REQUIREMENT_LABELS,
  FINANCIAL_STATEMENT_RUBRIC_LABELS,
  type FlattenedAccountRow,
} from '../types/accountingAccount.types';

defineProps<{
  readonly row: FlattenedAccountRow;
  readonly isFocused: boolean;
}>();

const emit = defineEmits<{
  toggleExpand: [row: FlattenedAccountRow];
  selectRow: [row: FlattenedAccountRow];
  inspectRow: [row: FlattenedAccountRow];
  createChild: [row: FlattenedAccountRow];
  editAccount: [row: FlattenedAccountRow];
  deactivateAccount: [row: FlattenedAccountRow];
}>();

const levelPrefixBadge = (level: number): string => {
  switch (level) {
    case 1:
      return 'E1';
    case 2:
      return 'C2';
    case 3:
      return 'S3';
    case 4:
      return 'R4';
    case 5:
      return 'N5';
    case 6:
      return 'N6';
    default:
      return `N${level}`;
  }
};

const shortRubricBadge = (rubric: string): string => {
  switch (rubric) {
    case 'ESF_ACT_CORR_EFE':
      return '1041 · Efectivo y Eq.';
    case 'ESF_ACT_CORR_CXC':
      return 'ESF · Cuentas x Cobrar';
    case 'ESF_ACT_CORR_EXI':
      return 'ESF · Existencias';
    case 'ESF_ACT_NOCORR_IME':
      return 'ESF · Activo Fijo';
    case 'ESF_PAS_CORR_TRIB':
      return 'ESF · Tributos';
    case 'ESF_PAS_CORR_CXP':
      return 'ESF · Cuentas x Pagar';
    case 'ESF_PAT_CAPITAL':
      return 'ESF · Patrimonio';
    case 'ERN_VENTAS_NETAS':
      return 'ERN · Ventas Netas';
    case 'ERN_GASTOS_PERSONAL':
      return 'ERN · Gasto Personal';
    case 'ERN_SERV_TERCEROS':
      return 'ERN · Serv. Terceros';
    case 'ERF_GASTOS_ADMIN_94':
      return 'ERF · 94 Gasto Admin.';
    case 'ERF_GASTOS_VENTA_95':
      return 'ERF · 95 Gasto Venta';
    default:
      return '';
  }
};
</script>

<template>
  <tr
    role="treeitem"
    :aria-level="row.node.level"
    :aria-expanded="row.hasChildren ? row.isExpanded : undefined"
    :aria-selected="isFocused"
    :tabindex="isFocused ? 0 : -1"
    class="group border-b border-line transition-colors select-none cursor-pointer"
    :class="[
      isFocused
        ? 'bg-soft shadow-[inset_3px_0_0_var(--accent)] font-medium'
        : row.node.level === 1
          ? 'bg-soft/75 hover:bg-soft'
          : 'bg-surf hover:bg-soft/50',
      !row.node.is_active ? 'opacity-50' : '',
    ]"
    @click="emit('selectRow', row)"
    @dblclick="emit('editAccount', row)"
  >
    <!-- 1. Código Jerárquico PCGE -->
    <td class="py-2.5 pr-3 pl-3 whitespace-nowrap">
      <div
        class="flex items-center gap-1.5"
        :style="{ paddingLeft: `${(row.node.level - 1) * 1.15}rem` }"
      >
        <!-- Conector visual para niveles > 1 -->
        <span
          v-if="row.node.level > 1"
          class="text-border dark:text-slate-700 font-mono text-xs select-none -ml-1"
          aria-hidden="true"
        >
          └
        </span>

        <button
          v-if="row.hasChildren"
          type="button"
          class="w-5 h-5 inline-flex items-center justify-center rounded-md text-muted hover:text-foreground hover:bg-border/50 transition-colors cursor-pointer"
          :aria-label="row.isExpanded ? 'Colapsar subcuentas' : 'Expandir subcuentas'"
          @click.stop="emit('toggleExpand', row)"
        >
          <ChevronDown v-if="row.isExpanded" class="w-3.5 h-3.5" />
          <ChevronRight v-else class="w-3.5 h-3.5" />
        </button>
        <span v-else class="w-5 h-5 inline-block shrink-0" />

        <span
          class="inline-flex items-center justify-center px-1.5 py-0.5 rounded text-[10px] font-mono font-bold tracking-tight"
          :class="[
            row.node.level === 1
              ? 'bg-brand text-white border border-brand'
              : row.node.level === 2
                ? 'bg-soft text-brand border border-line'
                : row.node.level === 3
                  ? 'bg-soft text-foreground border border-line'
                  : 'tag ok font-mono',
          ]"
          :title="`Nivel ${row.node.level} (Int16)`"
        >
          {{ levelPrefixBadge(row.node.level) }}
        </span>

        <span
          class="font-mono text-[13px] tracking-tight"
          :class="[
            row.node.level <= 2 ? 'font-bold text-foreground' : 'font-semibold text-foreground/90',
          ]"
        >
          {{ row.node.code }}
        </span>
      </div>
    </td>

    <!-- 2. Denominación de la Cuenta + Resumen Móvil -->
    <td class="py-2.5 px-3 min-w-[220px]">
      <div class="flex flex-col gap-0.5">
        <div class="flex items-center gap-2">
          <span
            class="text-[13px] leading-snug"
            :class="[
              row.node.level === 1
                ? 'font-bold text-foreground uppercase tracking-tight text-xs'
                : row.node.level === 2
                  ? 'font-semibold text-foreground'
                  : row.node.is_operational
                    ? 'font-medium text-foreground'
                    : 'font-medium text-foreground/90',
              !row.node.is_active ? 'line-through' : '',
            ]"
          >
            {{ row.node.name }}
          </span>
        </div>

        <!-- Sub-línea compacta visible en pantallas pequeñas/medianas -->
        <div class="flex flex-wrap items-center gap-1.5 md:hidden pt-0.5">
          <span class="text-[10px] font-medium text-muted">
            {{ ACCOUNT_TYPE_LABELS[row.node.account_type] }} ({{
              BALANCE_TYPE_LABELS[row.node.balance_type]
            }})
          </span>
          <span
            v-if="row.node.financial_statement_rubric !== 'NONE'"
            class="text-[10px] font-mono px-1.5 py-0.2 rounded bg-brand/10 text-brand"
          >
            {{ shortRubricBadge(row.node.financial_statement_rubric) }}
          </span>
        </div>
      </div>
    </td>

    <!-- 3. Naturaleza y Saldo (Oculto en móvil, visible desde md) -->
    <td class="py-2.5 px-3 whitespace-nowrap hidden md:table-cell">
      <div class="flex items-center gap-1.5">
        <span class="text-xs font-medium text-foreground/85">
          {{ ACCOUNT_TYPE_LABELS[row.node.account_type] }}
        </span>
        <span
          class="tag font-mono text-[10px]"
          :class="
            row.node.balance_type === 'DEBIT'
              ? 'ok'
              : 'wa'
          "
        >
          {{ row.node.balance_type === 'DEBIT' ? 'Deudor' : 'Acreedor' }}
        </span>
      </div>
    </td>

    <!-- 4. Parametrización Operativa (Moneda, Entidad, CeCo + Destino 94/95, Flujo Efectivo Cta. 10) -->
    <td class="py-2.5 px-3">
      <div class="flex items-center gap-1.5 flex-wrap">
        <!-- Tipo Nodo -->
        <span
          v-if="row.node.is_operational"
          class="tag ok font-mono text-[10px]"
        >
          Imputable
        </span>
        <span
          v-else
          class="tag text-muted border-line text-[10px] inline-flex items-center gap-1"
        >
          <FolderTree class="w-3 h-3" />
          Grupo
        </span>

        <!-- 1. Moneda -->
        <span
          class="tag font-mono text-[10px] inline-flex items-center gap-1"
          :class="
            row.node.currency_mode === 'USD'
              ? 'ok'
              : row.node.currency_mode === 'MULTI'
                ? 'wa'
                : 'text-muted border-line'
          "
          :title="`Moneda: ${row.node.currency_mode}${row.node.requires_currency ? ' · Reexpresión TC obligatoria' : ''}`"
        >
          <Coins class="w-3 h-3" />
          {{ row.node.currency_mode }}
        </span>

        <!-- 2. Entidad / Anexo -->
        <span
          v-if="row.node.requires_auxiliary || row.node.entity_requirement_type !== 'NONE'"
          class="tag text-[10px] font-mono text-muted border-line inline-flex items-center gap-1"
          :title="ENTITY_REQUIREMENT_LABELS[row.node.entity_requirement_type]"
        >
          <Building2 class="w-3 h-3" />
          {{
            row.node.entity_requirement_type === 'NONE'
              ? 'Anexo'
              : row.node.entity_requirement_type === 'FINANCIAL_INSTITUTION'
                ? 'Banco'
                : row.node.entity_requirement_type === 'CUSTOMER'
                  ? 'Cliente'
                  : row.node.entity_requirement_type === 'SUPPLIER'
                    ? 'Proveedor'
                    : row.node.entity_requirement_type === 'TAX_AUTHORITY'
                      ? 'SUNAT'
                      : 'Tercero'
          }}
        </span>

        <!-- 3. Centro de Costos y Destino Automático 94/95 vs 79 -->
        <span
          v-if="row.node.requires_cost_center || row.node.has_automatic_destination"
          class="tag wa font-mono text-[10px] inline-flex items-center gap-1"
          :title="
            row.node.has_automatic_destination
              ? `Asiento Destino Automático: Debe ${row.node.destination_debit_code} / Haber ${row.node.destination_credit_code}`
              : 'Exige Centro de Costos'
          "
        >
          <Workflow class="w-3 h-3" />
          <template v-if="row.node.has_automatic_destination">
            CeCo → {{ row.node.destination_debit_code }}/{{ row.node.destination_credit_code }}
          </template>
          <template v-else>CeCo</template>
        </span>

        <!-- 4. Área de Flujo de Efectivo (Cuenta 10 - NIC 7) -->
        <span
          v-if="row.node.requires_cash_flow_area || row.node.cash_flow_category !== 'NONE'"
          class="tag ok font-mono text-[10px] inline-flex items-center gap-1"
        >
          <Banknote class="w-3 h-3" />
          {{
            row.node.requires_cash_flow_area
              ? `Área Flujo (${row.node.cash_flow_category})`
              : 'NIC 7 Efectivo'
          }}
        </span>
      </div>
    </td>

    <!-- 5. Rubro EEFF (Visible en pantallas lg+) -->
    <td class="py-2.5 px-3 whitespace-nowrap hidden lg:table-cell">
      <span
        v-if="row.node.financial_statement_rubric !== 'NONE'"
        class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-[11px] font-medium bg-soft text-foreground/90 border border-line"
        :title="FINANCIAL_STATEMENT_RUBRIC_LABELS[row.node.financial_statement_rubric]"
      >
        <FileSpreadsheet class="w-3 h-3 text-brand shrink-0" />
        {{ shortRubricBadge(row.node.financial_statement_rubric) }}
      </span>
      <span v-else class="text-[11px] text-muted/60 font-mono">—</span>
    </td>

    <!-- 6. Acciones Rápidas -->
    <td class="py-2.5 pl-2 pr-3 text-right whitespace-nowrap">
      <div class="inline-flex items-center justify-end gap-1">
        <!-- Botón de inspección para abrir drawer en pantallas < xl -->
        <button
          type="button"
          class="xl:hidden inline-flex items-center justify-center w-7 h-7 rounded-md border border-line bg-surf text-muted hover:text-foreground hover:border-brand cursor-pointer"
          title="Ver ficha técnica completa"
          @click.stop="emit('inspectRow', row)"
        >
          <Eye class="w-3.5 h-3.5" />
        </button>

        <button
          v-if="!row.node.is_operational && row.node.level < 6 && row.node.is_active"
          type="button"
          class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-[11px] font-semibold text-brand bg-brand/10 hover:bg-brand hover:text-white transition-colors cursor-pointer"
          title="Crear subcuenta hija (Alt+N)"
          @click.stop="emit('createChild', row)"
        >
          <Plus class="w-3.5 h-3.5" />
          <span class="hidden sm:inline">Hija</span>
        </button>

        <button
          type="button"
          class="inline-flex items-center justify-center w-7 h-7 rounded-md text-muted hover:text-foreground hover:bg-soft border border-transparent hover:border-line transition-colors cursor-pointer"
          title="Configurar cuenta contable (Enter)"
          @click.stop="emit('editAccount', row)"
        >
          <Pencil class="w-3.5 h-3.5" />
        </button>

        <button
          v-if="row.node.is_active"
          type="button"
          class="inline-flex items-center justify-center w-7 h-7 rounded-md text-muted hover:text-danger hover:bg-danger/10 transition-colors cursor-pointer"
          title="Desactivar cuenta (Supr)"
          @click.stop="emit('deactivateAccount', row)"
        >
          <Ban class="w-3.5 h-3.5" />
        </button>
      </div>
    </td>
  </tr>
</template>
