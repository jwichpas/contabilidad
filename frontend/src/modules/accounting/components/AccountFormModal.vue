<script setup lang="ts">
import { computed, nextTick, reactive, shallowRef, useTemplateRef, watch } from 'vue';
import {
  ArrowRight,
  Banknote,
  Building2,
  Coins,
  FileSpreadsheet,
  FolderTree,
  Sparkles,
  Workflow,
  X,
} from 'lucide-vue-next';
import UiSelect from '@/shared/ui/UiSelect.vue';
import type { UiSelectOption } from '@/shared/ui/types';
import {
  accountingAccountSchema,
  type CreateAccountingAccountFormValues,
} from '../schemas/accountingAccount.schema';
import {
  ACCOUNT_CURRENCY_MODES,
  ACCOUNT_CURRENCY_MODE_LABELS,
  ACCOUNT_TYPES,
  ACCOUNT_TYPE_LABELS,
  BALANCE_TYPES,
  BALANCE_TYPE_LABELS,
  CASH_FLOW_CATEGORIES,
  CASH_FLOW_CATEGORY_LABELS,
  ENTITY_REQUIREMENT_LABELS,
  ENTITY_REQUIREMENT_TYPES,
  FINANCIAL_STATEMENT_RUBRICS,
  FINANCIAL_STATEMENT_RUBRIC_LABELS,
  asAccountLevel,
  type AccountCurrencyMode,
  type AccountLevel,
  type AccountType,
  type BalanceType,
  type CashFlowCategory,
  type CreateAccountingAccountPayload,
  type EntityRequirementType,
  type FinancialStatementRubric,
  type FlattenedAccountRow,
} from '../types/accountingAccount.types';
import type { CashFlowAreaItem, CostCenterItem } from '../types/catalogs.types';

const props = withDefaults(
  defineProps<{
    readonly isOpen: boolean;
    readonly mode: 'create' | 'edit';
    readonly parentRow: FlattenedAccountRow | null;
    readonly editingRow: FlattenedAccountRow | null;
    readonly currencyOptions?: readonly UiSelectOption<AccountCurrencyMode>[];
    readonly rubricOptions?: readonly UiSelectOption<FinancialStatementRubric>[];
    readonly costCenterOptions?: readonly UiSelectOption<string>[];
    readonly cashFlowAreaOptions?: readonly UiSelectOption<string>[];
    readonly costCenters?: readonly CostCenterItem[];
    readonly cashFlowAreas?: readonly CashFlowAreaItem[];
  }>(),
  {
    currencyOptions: () => [],
    rubricOptions: () => [],
    costCenterOptions: () => [],
    cashFlowAreaOptions: () => [],
    costCenters: () => [],
    cashFlowAreas: () => [],
  },
);

const emit = defineEmits<{
  close: [];
  submit: [payload: CreateAccountingAccountPayload];
}>();

const splitPrefixedLabel = (label: string): { badge?: string; label: string } => {
  const [prefix, ...rest] = label.split(' · ');
  return rest.length > 0 ? { badge: prefix, label: rest.join(' · ') } : { label };
};

const accountTypeOptions: readonly UiSelectOption<AccountType>[] = ACCOUNT_TYPES.map((t) => ({
  value: t,
  label: ACCOUNT_TYPE_LABELS[t],
}));

const balanceTypeOptions: readonly UiSelectOption<BalanceType>[] = BALANCE_TYPES.map((b) => ({
  value: b,
  label: BALANCE_TYPE_LABELS[b],
}));

const defaultCurrencyModeOptions: readonly UiSelectOption<AccountCurrencyMode>[] =
  ACCOUNT_CURRENCY_MODES.map((cm) => ({
    value: cm,
    badge: cm,
    label: ACCOUNT_CURRENCY_MODE_LABELS[cm],
  }));

const activeCurrencyModeOptions = computed<readonly UiSelectOption<AccountCurrencyMode>[]>(() =>
  props.currencyOptions && props.currencyOptions.length > 0
    ? props.currencyOptions
    : defaultCurrencyModeOptions,
);

const entityOptions: readonly UiSelectOption<EntityRequirementType>[] =
  ENTITY_REQUIREMENT_TYPES.map((ent) => ({
    value: ent,
    label: ENTITY_REQUIREMENT_LABELS[ent],
  }));

const cashFlowOptions: readonly UiSelectOption<CashFlowCategory>[] = CASH_FLOW_CATEGORIES.map(
  (cf) => ({
    value: cf,
    label: CASH_FLOW_CATEGORY_LABELS[cf],
  }),
);

const defaultRubricOptions: readonly UiSelectOption<FinancialStatementRubric>[] =
  FINANCIAL_STATEMENT_RUBRICS.map((rub) => ({
    value: rub,
    ...splitPrefixedLabel(FINANCIAL_STATEMENT_RUBRIC_LABELS[rub]),
  }));

const activeRubricOptions = computed<readonly UiSelectOption<FinancialStatementRubric>[]>(() =>
  props.rubricOptions && props.rubricOptions.length > 0
    ? props.rubricOptions
    : defaultRubricOptions,
);

const selectedCostCenterCode = shallowRef<string>('');

const codeInputRef = useTemplateRef<HTMLInputElement>('codeInput');

const parentAccount = computed(() => props.parentRow?.node ?? null);
const editingAccount = computed(() => props.editingRow?.node ?? null);

const targetLevel = computed<AccountLevel>(() => {
  if (props.mode === 'edit' && editingAccount.value) {
    return editingAccount.value.level;
  }
  return parentAccount.value ? asAccountLevel(parentAccount.value.level + 1) : asAccountLevel(1);
});

const form = reactive<{
  code: string;
  name: string;
  account_type: AccountType;
  balance_type: BalanceType;
  currency_mode: AccountCurrencyMode;
  entity_requirement_type: EntityRequirementType;
  requires_cost_center: boolean;
  requires_company_area: boolean;
  has_automatic_destination: boolean;
  destination_debit_code: string;
  destination_credit_code: string;
  requires_cash_flow_area: boolean;
  cash_flow_category: CashFlowCategory;
  financial_statement_rubric: FinancialStatementRubric;
  is_operational: boolean;
}>({
  code: '',
  name: '',
  account_type: 'ASSET',
  balance_type: 'DEBIT',
  currency_mode: 'PEN',
  entity_requirement_type: 'NONE',
  requires_cost_center: false,
  requires_company_area: false,
  has_automatic_destination: false,
  destination_debit_code: '',
  destination_credit_code: '',
  requires_cash_flow_area: false,
  cash_flow_category: 'NONE',
  financial_statement_rubric: 'NONE',
  is_operational: false,
});

const fieldErrors = shallowRef<Readonly<Record<string, string>>>({});
const touched = shallowRef(false);

const resetFormFromContext = (): void => {
  touched.value = false;
  fieldErrors.value = {};

  if (props.mode === 'edit' && editingAccount.value) {
    const acc = editingAccount.value;
    form.code = acc.code;
    form.name = acc.name;
    form.account_type = acc.account_type;
    form.balance_type = acc.balance_type;
    form.currency_mode = acc.currency_mode;
    form.entity_requirement_type = acc.entity_requirement_type;
    form.requires_cost_center = acc.requires_cost_center;
    form.requires_company_area = acc.requires_company_area;
    form.has_automatic_destination = acc.has_automatic_destination;
    form.destination_debit_code = acc.destination_debit_code ?? '';
    form.destination_credit_code = acc.destination_credit_code ?? '';
    form.requires_cash_flow_area = acc.requires_cash_flow_area;
    form.cash_flow_category = acc.cash_flow_category;
    form.financial_statement_rubric = acc.financial_statement_rubric;
    form.is_operational = acc.is_operational;
    return;
  }

  const parent = parentAccount.value;
  const lvl = targetLevel.value;
  const isElem10 = parent !== null && parent.code.startsWith('10');
  const isElem6 = parent !== null && parent.code.startsWith('6');
  // New six-digit PCGE leaves are operational by default; intermediate nodes
  // stay non-operational so users can continue growing the hierarchy.

  form.code = parent ? `${parent.code}1` : '';
  form.name = '';
  form.account_type = parent ? parent.account_type : 'ASSET';
  form.balance_type = parent ? parent.balance_type : 'DEBIT';
  form.currency_mode = parent ? parent.currency_mode : 'PEN';
  form.entity_requirement_type =
    lvl === 6 && isElem10 ? 'FINANCIAL_INSTITUTION' : parent ? parent.entity_requirement_type : 'NONE';
  form.requires_cost_center = lvl === 6 && isElem6;
  form.requires_company_area = false;
  form.has_automatic_destination = lvl === 6 && isElem6;
  form.destination_debit_code = isElem6 ? '9411' : '';
  form.destination_credit_code = isElem6 ? '7911' : '';
  form.requires_cash_flow_area = lvl === 6 && isElem10;
  form.cash_flow_category =
    lvl === 6 && isElem10 ? 'OPERATING' : parent ? parent.cash_flow_category : 'NONE';
  form.financial_statement_rubric =
    parent && parent.financial_statement_rubric !== 'NONE'
      ? parent.financial_statement_rubric
      : isElem10
        ? 'ESF_ACT_CORR_EFE'
        : isElem6
          ? 'ERN_SERV_TERCEROS'
          : 'NONE';
  form.is_operational = lvl === 6;
};

watch(
  () => [props.isOpen, props.mode, props.parentRow, props.editingRow],
  async ([open]) => {
    if (open) {
      resetFormFromContext();
      await nextTick();
      codeInputRef.value?.focus();
    }
  },
  { immediate: true },
);

const applyDestinationPreset = (debitCode: '9411' | '9511'): void => {
  form.requires_cost_center = true;
  form.has_automatic_destination = true;
  form.destination_debit_code = debitCode;
  form.destination_credit_code = '7911';
};

const applyCostCenterPreset = (code: string): void => {
  selectedCostCenterCode.value = code;
  const match = props.costCenters?.find((cc) => cc.code === code);
  form.requires_cost_center = true;
  form.has_automatic_destination = true;
  if (match) {
    if (match.destination_debit_account?.code) {
      form.destination_debit_code = match.destination_debit_account.code;
    } else if (code.includes('100')) {
      form.destination_debit_code = '9411';
    } else if (code.includes('200')) {
      form.destination_debit_code = '9511';
    }
    form.destination_credit_code = match.destination_credit_account?.code ?? '7911';
  }
};

const validationPayload = computed<CreateAccountingAccountFormValues>(() => ({
  // Editing a deep PCGE account must retain its actual parent id for Zod's
  // level-2-to-6 hierarchy check. The parentRow prop is only set for CREATE.
  parent_id: props.mode === 'edit'
    ? (editingAccount.value?.parent_id ?? null)
    : (parentAccount.value?.id ?? null),
  parent_code: parentAccount.value ? parentAccount.value.code : null,
  parent_level: parentAccount.value ? parentAccount.value.level : null,
  parent_is_operational: parentAccount.value ? parentAccount.value.is_operational : null,
  code: form.code,
  name: form.name,
  level: targetLevel.value,
  account_type: form.account_type,
  balance_type: form.balance_type,
  currency_mode: form.currency_mode,
  requires_currency: form.currency_mode !== 'PEN',
  requires_auxiliary: form.entity_requirement_type !== 'NONE',
  entity_requirement_type: form.entity_requirement_type,
  requires_cost_center: form.requires_cost_center,
  requires_company_area: form.requires_company_area,
  has_automatic_destination: form.has_automatic_destination,
  destination_debit_code: form.has_automatic_destination ? form.destination_debit_code : null,
  destination_credit_code: form.has_automatic_destination ? form.destination_credit_code : null,
  requires_cash_flow_area: form.requires_cash_flow_area,
  cash_flow_category:
    form.requires_cash_flow_area && form.cash_flow_category === 'NONE'
      ? 'OPERATING'
      : form.cash_flow_category,
  financial_statement_rubric: form.financial_statement_rubric,
  is_operational: form.is_operational,
  is_active: true,
}));

function validateForm(): boolean {
  const result = accountingAccountSchema.safeParse(validationPayload.value);
  if (result.success) {
    fieldErrors.value = {};
    return true;
  }

  const nextErrors: Record<string, string> = {};
  for (const issue of result.error.issues) {
    const key = String(issue.path[0] ?? 'general');
    if (!nextErrors[key]) {
      nextErrors[key] = issue.message;
    }
  }
  fieldErrors.value = nextErrors;
  return false;
}

watch(
  () => [
    form.code,
    form.name,
    form.account_type,
    form.balance_type,
    form.currency_mode,
    form.entity_requirement_type,
    form.requires_cost_center,
    form.requires_company_area,
    form.has_automatic_destination,
    form.destination_debit_code,
    form.destination_credit_code,
    form.requires_cash_flow_area,
    form.cash_flow_category,
    form.financial_statement_rubric,
    form.is_operational,
  ],
  () => {
    if (touched.value) {
      validateForm();
    }
  },
);

function handleSubmit(): void {
  touched.value = true;
  if (!validateForm()) {
    return;
  }

  const v = validationPayload.value;
  emit('submit', {
    parent_id: parentAccount.value ? parentAccount.value.id : null,
    code: form.code.trim().toUpperCase(),
    name: form.name.trim(),
    level: targetLevel.value,
    account_type: form.account_type,
    balance_type: form.balance_type,
    currency_mode: form.currency_mode,
    requires_currency: form.currency_mode !== 'PEN',
    requires_auxiliary: form.entity_requirement_type !== 'NONE',
    entity_requirement_type: form.entity_requirement_type,
    requires_cost_center: form.requires_cost_center,
    requires_company_area: form.requires_company_area,
    has_automatic_destination: form.has_automatic_destination,
    destination_debit_code: v.destination_debit_code ?? null,
    destination_credit_code: v.destination_credit_code ?? null,
    requires_cash_flow_area: form.requires_cash_flow_area,
    cash_flow_category: v.cash_flow_category ?? 'NONE',
    financial_statement_rubric: form.financial_statement_rubric,
    is_operational: form.is_operational,
    is_active: true,
  });
}
</script>

<template>
  <Transition name="ui-fade">
    <div
      v-if="isOpen"
      class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/60 backdrop-blur-xs p-0 sm:p-4"
      role="dialog"
      aria-modal="true"
      aria-labelledby="modal-account-title"
      @keydown.esc="emit('close')"
      @click.self="emit('close')"
    >
      <div
        class="w-full sm:max-w-3xl bg-surf border border-line shadow-card overflow-hidden flex flex-col rounded-t-lg sm:rounded-lg max-h-[94vh]"
      >
        <!-- Cabecera -->
        <div class="relative bg-brand text-brandtx px-5 py-4 flex items-start justify-between gap-4">
          <div
            class="absolute inset-x-0 bottom-0 h-0.5 bg-accent"
            aria-hidden="true"
          />
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <span class="tag ok font-mono text-[10px]">
                Nivel {{ targetLevel }} · Int16
              </span>
              <span v-if="parentAccount" class="text-xs font-mono text-white/80 truncate">
                Padre {{ parentAccount.code }} · {{ parentAccount.name }}
              </span>
            </div>
            <h3 id="modal-account-title" class="text-base sm:text-lg font-semibold text-white mt-1.5">
              {{
                mode === 'edit'
                  ? `Configurar cuenta ${editingAccount?.code}`
                  : parentAccount
                    ? `Nueva subcuenta bajo ${parentAccount.code}`
                    : 'Nueva cuenta raíz (Elemento)'
              }}
            </h3>
          </div>

          <button
            type="button"
            class="ui-btn ui-btn-icon text-brand-soft hover:bg-white/10 hover:text-white"
            aria-label="Cerrar ventana"
            @click="emit('close')"
          >
            <X />
          </button>
        </div>

        <form class="flex-1 overflow-y-auto" novalidate @submit.prevent="handleSubmit">
          <div class="p-4 sm:p-5 space-y-4">
            <!-- Identificación -->
            <section class="ui-card p-4 space-y-3.5">
              <div class="flex flex-wrap items-center justify-between gap-3">
                <span class="ui-section-title">
                  <FolderTree class="w-3.5 h-3.5 text-brand" />
                  Identificación y naturaleza
                </span>
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                  <label class="ui-label ui-label-required" for="acc-code">Código PCGE</label>
                  <input
                    id="acc-code"
                    ref="codeInput"
                    v-model="form.code"
                    type="text"
                    inputmode="numeric"
                    maxlength="20"
                    autocomplete="off"
                    :disabled="mode === 'edit'"
                    :aria-invalid="!!fieldErrors.code || undefined"
                    class="w-full font-mono font-semibold tracking-tight"
                    :placeholder="parentAccount ? `${parentAccount.code}1` : '10'"
                  />
                  <p v-if="fieldErrors.code" class="ui-error">{{ fieldErrors.code }}</p>
                </div>

                <div class="sm:col-span-2">
                  <label class="ui-label ui-label-required" for="acc-name">Denominación oficial</label>
                  <input
                    id="acc-name"
                    v-model="form.name"
                    type="text"
                    maxlength="180"
                    autocomplete="off"
                    :aria-invalid="!!fieldErrors.name || undefined"
                    class="w-full"
                    placeholder="Ej. Cuentas corrientes operativas MN"
                  />
                  <p v-if="fieldErrors.name" class="ui-error">{{ fieldErrors.name }}</p>
                </div>
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                  <label class="ui-label" for="acc-type">Elemento / naturaleza</label>
                  <UiSelect
                    id="acc-type"
                    v-model="form.account_type"
                    :options="accountTypeOptions"
                    :disabled="mode === 'edit'"
                  />
                </div>
                <div>
                  <label class="ui-label" for="acc-balance">Saldo natural</label>
                  <UiSelect
                    id="acc-balance"
                    v-model="form.balance_type"
                    :options="balanceTypeOptions"
                    :disabled="mode === 'edit'"
                  />
                </div>
              </div>

              <label class="ui-toggle-row">
                <span class="min-w-0">
                  <span class="block text-xs font-semibold text-foreground">
                    Cuenta imputable en asientos (hoja operativa)
                  </span>
                  <span class="block text-[11px] text-muted">
                    Las cuentas operativas (niveles 3 a 6) reciben movimientos y no pueden tener hijas.
                  </span>
                </span>
                <input
                  v-model="form.is_operational"
                  type="checkbox"
                  class="ui-switch"
                  :disabled="targetLevel < 3 || (mode === 'edit' && editingRow?.hasChildren === true)"
                />
              </label>
            </section>

            <!-- 1. Moneda · 2. Entidad -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <section class="ui-card p-4 space-y-2">
                <label class="ui-section-title" for="acc-currency-mode">
                  <Coins class="w-3.5 h-3.5 text-brand" />
                  1 · Moneda
                </label>
                <UiSelect
                  id="acc-currency-mode"
                  v-model="form.currency_mode"
                  :options="activeCurrencyModeOptions"
                />
                <p class="ui-hint">
                  {{
                    form.currency_mode === 'PEN'
                      ? 'Registro en soles con equivalente informativo en dólares.'
                      : 'Exige tipo de cambio NUMERIC(14,6) y diferencia de cambio (NIC 21).'
                  }}
                </p>
              </section>

              <section class="ui-card p-4 space-y-2">
                <label class="ui-section-title" for="acc-entity-req">
                  <Building2 class="w-3.5 h-3.5 text-secondary" />
                  2 · Entidad / anexo
                </label>
                <UiSelect
                  id="acc-entity-req"
                  v-model="form.entity_requirement_type"
                  :options="entityOptions"
                  :invalid="!!fieldErrors.entity_requirement_type"
                />
                <p v-if="fieldErrors.entity_requirement_type" class="ui-error">
                  {{ fieldErrors.entity_requirement_type }}
                </p>
                <p v-else class="ui-hint">
                  Cliente (12), proveedor (42), banco (104) o SUNAT (40) obligatorio en el voucher.
                </p>
              </section>
            </div>

            <!-- 3. Centro de costos y destino -->
            <section class="ui-card p-4 space-y-3">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="ui-section-title">
                  <Workflow class="w-3.5 h-3.5 text-accent-ink" />
                  3 · Centro de costos y destino (94/95 ↔ 79)
                </span>
                <div class="ui-segmented" role="group" aria-label="Plantillas de destino">
                  <button
                    type="button"
                    :aria-pressed="form.has_automatic_destination && form.destination_debit_code === '9411'"
                    @click="applyDestinationPreset('9411')"
                  >
                    94 Admin.
                  </button>
                  <button
                    type="button"
                    :aria-pressed="form.has_automatic_destination && form.destination_debit_code === '9511'"
                    @click="applyDestinationPreset('9511')"
                  >
                    95 Ventas
                  </button>
                </div>
              </div>

              <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                <label class="ui-toggle-row">
                  <span class="text-xs font-medium text-foreground">Exige centro de costos</span>
                  <input v-model="form.requires_cost_center" type="checkbox" class="ui-switch ui-switch-accent" />
                </label>
                <label class="ui-toggle-row">
                  <span class="text-xs font-medium text-foreground">Destino automático Elem. 6 → 9</span>
                  <input
                    v-model="form.has_automatic_destination"
                    type="checkbox"
                    class="ui-switch ui-switch-accent"
                  />
                </label>
              </div>

              <div v-if="costCenterOptions && costCenterOptions.length > 0" class="pt-1">
                <label class="ui-label text-[11px]" for="acc-cost-center-select">
                  Centro de Costo configurado en la Empresa
                </label>
                <UiSelect
                  id="acc-cost-center-select"
                  v-model="selectedCostCenterCode"
                  :options="costCenterOptions"
                  placeholder="Seleccionar Centro de Costos..."
                  @update:model-value="applyCostCenterPreset"
                />
              </div>

              <Transition name="ui-pop">
                <div
                  v-if="form.has_automatic_destination"
                  class="grid grid-cols-1 sm:grid-cols-[1fr_auto_1fr] gap-3 items-start rounded-md border border-line bg-soft p-3"
                >
                  <div>
                    <label class="ui-label" for="acc-dest-debit">Debe · destino (94 / 95)</label>
                    <input
                      id="acc-dest-debit"
                      v-model="form.destination_debit_code"
                      type="text"
                      inputmode="numeric"
                      placeholder="9411"
                      :aria-invalid="!!fieldErrors.destination_debit_code || undefined"
                      class="w-full font-mono font-bold text-accent-ink"
                    />
                    <p v-if="fieldErrors.destination_debit_code" class="ui-error">
                      {{ fieldErrors.destination_debit_code }}
                    </p>
                  </div>
                  <div class="hidden sm:flex items-center justify-center pt-7 text-muted">
                    <ArrowRight class="w-4 h-4" />
                  </div>
                  <div>
                    <label class="ui-label" for="acc-dest-credit">Haber · contrapartida (79)</label>
                    <input
                      id="acc-dest-credit"
                      v-model="form.destination_credit_code"
                      type="text"
                      inputmode="numeric"
                      placeholder="7911"
                      :aria-invalid="!!fieldErrors.destination_credit_code || undefined"
                      class="w-full font-mono font-bold text-brand"
                    />
                    <p v-if="fieldErrors.destination_credit_code" class="ui-error">
                      {{ fieldErrors.destination_credit_code }}
                    </p>
                  </div>
                </div>
              </Transition>
            </section>

            <!-- 4. Flujo de efectivo · 5. Rubro EEFF -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <section class="ui-card p-4 space-y-2.5">
                <span class="ui-section-title">
                  <Banknote class="w-3.5 h-3.5 text-info" />
                  4 · Flujo de efectivo (NIC 7)
                </span>
                <label class="ui-toggle-row">
                  <span class="text-xs font-medium text-foreground">Pedir área al contabilizar</span>
                  <input v-model="form.requires_cash_flow_area" type="checkbox" class="ui-switch" />
                </label>
                <UiSelect
                  v-model="form.cash_flow_category"
                  :options="cashFlowOptions"
                  :invalid="!!fieldErrors.cash_flow_category"
                />
                <label class="ui-toggle-row">
                  <span class="text-xs font-medium text-foreground">Exige área organizativa al contabilizar</span>
                  <input v-model="form.requires_company_area" type="checkbox" class="ui-switch" />
                </label>
                <p v-if="fieldErrors.requires_company_area" class="ui-error">{{ fieldErrors.requires_company_area }}</p>
                <p v-if="fieldErrors.cash_flow_category" class="ui-error">
                  {{ fieldErrors.cash_flow_category }}
                </p>
              </section>

              <section class="ui-card p-4 space-y-2.5">
                <label class="ui-section-title" for="acc-fs-rubric">
                  <FileSpreadsheet class="w-3.5 h-3.5 text-brand" />
                  5 · Rubro de estados financieros
                </label>
                <UiSelect
                  id="acc-fs-rubric"
                  v-model="form.financial_statement_rubric"
                  :options="activeRubricOptions"
                  :invalid="!!fieldErrors.financial_statement_rubric"
                />
                <p v-if="fieldErrors.financial_statement_rubric" class="ui-error">
                  {{ fieldErrors.financial_statement_rubric }}
                </p>
                <p v-else class="ui-hint">
                  Ej. <strong class="text-foreground">1041 → Efectivo y equivalentes de efectivo</strong>.
                </p>
              </section>
            </div>

            <div v-if="fieldErrors.parent_id || fieldErrors.level" class="ui-alert ui-alert-danger">
              <Sparkles />
              <span>{{ fieldErrors.parent_id ?? fieldErrors.level }}</span>
            </div>
          </div>

          <!-- Pie fijo -->
          <div
            class="sticky bottom-0 flex flex-col-reverse sm:flex-row sm:items-center sm:justify-between gap-2.5 px-4 sm:px-5 py-3 border-t border-line bg-surf/95 backdrop-blur"
          >
            <span class="hidden sm:inline-flex items-center gap-1.5 text-[11px] text-muted">
              <kbd class="ui-kbd">Esc</kbd> cancelar · <kbd class="ui-kbd">Enter</kbd> guardar
            </span>
            <div class="flex gap-2">
              <button type="button" class="ui-btn ui-btn-secondary flex-1 sm:flex-none" @click="emit('close')">
                Cancelar
              </button>
              <button type="submit" class="ui-btn ui-btn-primary flex-1 sm:flex-none">
                {{ mode === 'edit' ? 'Guardar cambios' : 'Registrar cuenta' }}
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </Transition>
</template>
