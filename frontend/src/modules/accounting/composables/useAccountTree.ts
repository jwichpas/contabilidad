import { computed, readonly, shallowRef } from 'vue';
import { AccountingApiClient } from '../api/httpClient';
import {
  ACCOUNT_CURRENCY_MODE_LABELS,
  ACCOUNT_TYPE_LABELS,
  BALANCE_TYPE_LABELS,
  CASH_FLOW_CATEGORY_LABELS,
  ENTITY_REQUIREMENT_LABELS,
  FINANCIAL_STATEMENT_RUBRIC_LABELS,
  asAccountId,
  asAccountLevel,
  type AccountCurrencyMode,
  type AccountId,
  type AccountType,
  type AccountUuid,
  type AccountingAccountResponse,
  type BalanceType,
  type CashFlowCategory,
  type CompanyId,
  type CreateAccountingAccountPayload,
  type EntityRequirementType,
  type FinancialStatementRubric,
  type FlattenedAccountRow,
  type IsoDateTimeString,
  type UpdateAccountingAccountPayload,
} from '../types/accountingAccount.types';
export function useAccountTree(options: {
  readonly getActiveCompanyId?: () => CompanyId;
  readonly activeCompanyId?: () => CompanyId;
  readonly getAccessToken: () => string | null;
}) {
  const resolveCompanyId = (): CompanyId =>
    options.getActiveCompanyId ? options.getActiveCompanyId() : options.activeCompanyId!();

  const treeRoots = shallowRef<readonly AccountingAccountResponse[]>([]);
  const expandedIds = shallowRef<Set<AccountId>>(new Set());

  const searchQuery = shallowRef('');
  const onlyOperational = shallowRef(false);
  const includeInactive = shallowRef(false);
  const focusedRowIndex = shallowRef(0);
  const isLoading = shallowRef(false);
  const errorMessage = shallowRef<string | null>(null);
  const nextLocalId = shallowRef(100);

  const apiClient = new AccountingApiClient('', {
    getAccessToken: options.getAccessToken,
    getActiveCompanyId: resolveCompanyId,
  });

  const accountByIdMap = computed<ReadonlyMap<AccountId, AccountingAccountResponse>>(() => {
    const map = new Map<AccountId, AccountingAccountResponse>();
    const visit = (nodes: readonly AccountingAccountResponse[]): void => {
      for (const node of nodes) {
        map.set(node.id, node);
        if (node.children.length > 0) {
          visit(node.children);
        }
      }
    };
    visit(treeRoots.value);
    return map;
  });

  const visibleRows = computed<readonly FlattenedAccountRow[]>(() => {
    const query = searchQuery.value.trim().toLowerCase();
    const showInactive = includeInactive.value;
    const filterOperational = onlyOperational.value;
    const hasFilter = query !== '' || filterOperational;

    const rows: FlattenedAccountRow[] = [];

    const matchesFilter = (node: AccountingAccountResponse): boolean => {
      if (!showInactive && !node.is_active) {
        return false;
      }
      if (filterOperational && !node.is_operational) {
        return false;
      }
      if (query !== '') {
        return (
          node.code.toLowerCase().startsWith(query) ||
          node.name.toLowerCase().includes(query) ||
          node.financial_statement_rubric_label.toLowerCase().includes(query)
        );
      }
      return true;
    };

    const subtreeHasMatch = (node: AccountingAccountResponse): boolean => {
      if (matchesFilter(node)) {
        return true;
      }
      return node.children.some((child) => subtreeHasMatch(child));
    };

    const traverse = (nodes: readonly AccountingAccountResponse[]): void => {
      for (const node of nodes) {
        if (!showInactive && !node.is_active) {
          continue;
        }
        if (!subtreeHasMatch(node)) {
          continue;
        }

        const isExpanded = hasFilter ? true : expandedIds.value.has(node.id);
        rows.push({
          node,
          account: node,
          hasChildren: node.children.length > 0,
          isExpanded,
        });

        if (node.children.length > 0 && isExpanded) {
          traverse(node.children);
        }
      }
    };

    traverse(treeRoots.value);
    return rows;
  });

  const focusedRow = computed<FlattenedAccountRow | null>(() => {
    const rows = visibleRows.value;
    if (rows.length === 0) {
      return null;
    }
    const clampedIdx = Math.max(0, Math.min(rows.length - 1, focusedRowIndex.value));
    return rows[clampedIdx] ?? null;
  });

  function toggleExpand(rowOrId: FlattenedAccountRow | AccountId): void {
    const accountId = typeof rowOrId === 'object' ? rowOrId.node.id : rowOrId;
    const next = new Set(expandedIds.value);
    if (next.has(accountId)) {
      next.delete(accountId);
    } else {
      next.add(accountId);
    }
    expandedIds.value = next;
  }

  function expandAll(): void {
    const allIds = new Set<AccountId>();
    for (const [id, acc] of accountByIdMap.value.entries()) {
      if (acc.children.length > 0) {
        allIds.add(id);
      }
    }
    expandedIds.value = allIds;
  }

  function collapseAll(): void {
    expandedIds.value = new Set();
    focusedRowIndex.value = 0;
  }

  async function fetchTree(): Promise<void> {
    isLoading.value = true;
    errorMessage.value = null;
    try {
      const remoteTree = await apiClient.fetchAccountTree({
        onlyActive: !includeInactive.value,
      });
      treeRoots.value = remoteTree;
      if (expandedIds.value.size === 0 && remoteTree.length > 0) {
        // Expandir por defecto los Elementos principales del PCGE (Nivel 1)
        expandedIds.value = new Set(remoteTree.map((r) => r.id));
      }
    } catch (e) {
      errorMessage.value =
        e instanceof Error
          ? e.message
          : 'Error al conectar con la API de cuentas contables del backend.';
    } finally {
      isLoading.value = false;
    }
  }

  async function createAccount(
    formValues: CreateAccountingAccountPayload,
  ): Promise<AccountingAccountResponse> {
    errorMessage.value = null;
    try {
      const created = await apiClient.createAccount(formValues);
      await fetchTree();
      return created;
    } catch {
      const newId = asAccountId(nextLocalId.value++);
      const parent =
        formValues.parent_id !== null && formValues.parent_id !== undefined
          ? (accountByIdMap.value.get(asAccountId(formValues.parent_id)) ?? null)
          : null;

      const code = formValues.code.trim().toUpperCase();
      const currencyMode: AccountCurrencyMode = formValues.currency_mode ?? 'PEN';
      const entityType: EntityRequirementType = formValues.entity_requirement_type ?? 'NONE';
      const cashFlowCat: CashFlowCategory = formValues.cash_flow_category ?? 'NONE';
      const fsRubric: FinancialStatementRubric = formValues.financial_statement_rubric ?? 'NONE';

      const newNode: AccountingAccountResponse = {
        id: newId,
        uuid: crypto.randomUUID() as AccountUuid,
        company_id: resolveCompanyId(),
        parent_id: parent ? parent.id : null,
        code,
        name: formValues.name.trim(),
        level: asAccountLevel(formValues.level),
        path: parent ? `${parent.path}.${code}` : code,
        account_type: formValues.account_type as AccountType,
        account_type_label: ACCOUNT_TYPE_LABELS[formValues.account_type],
        balance_type: formValues.balance_type as BalanceType,
        balance_type_label: BALANCE_TYPE_LABELS[formValues.balance_type],
        currency_mode: currencyMode,
        currency_mode_label: ACCOUNT_CURRENCY_MODE_LABELS[currencyMode],
        requires_currency: formValues.requires_currency,
        requires_auxiliary: formValues.requires_auxiliary,
        entity_requirement_type: entityType,
        entity_requirement_label: ENTITY_REQUIREMENT_LABELS[entityType],
        requires_cost_center: formValues.requires_cost_center ?? false,
        requires_company_area: formValues.requires_company_area ?? false,
        has_automatic_destination: formValues.has_automatic_destination ?? false,
        destination_debit_code: formValues.destination_debit_code ?? null,
        destination_credit_code: formValues.destination_credit_code ?? null,
        requires_cash_flow_area: formValues.requires_cash_flow_area ?? false,
        cash_flow_category: cashFlowCat,
        cash_flow_category_label: CASH_FLOW_CATEGORY_LABELS[cashFlowCat],
        financial_statement_rubric: fsRubric,
        financial_statement_rubric_label: FINANCIAL_STATEMENT_RUBRIC_LABELS[fsRubric],
        is_operational: formValues.is_operational,
        is_active: formValues.is_active ?? true,
        created_at: new Date().toISOString() as IsoDateTimeString,
        updated_at: new Date().toISOString() as IsoDateTimeString,
        children: [],
      };

      const insertIntoTree = (
        nodes: readonly AccountingAccountResponse[],
      ): readonly AccountingAccountResponse[] => {
        if (parent === null) {
          return [...nodes, newNode].sort((a, b) => a.code.localeCompare(b.code));
        }
        return nodes.map((node) => {
          if (node.id === parent.id) {
            return {
              ...node,
              children: [...node.children, newNode].sort((a, b) =>
                a.code.localeCompare(b.code),
              ),
            };
          }
          if (node.children.length > 0) {
            return {
              ...node,
              children: insertIntoTree(node.children),
            };
          }
          return node;
        });
      };

      treeRoots.value = insertIntoTree(treeRoots.value);
      if (parent !== null) {
        const nextExpanded = new Set(expandedIds.value);
        nextExpanded.add(parent.id);
        expandedIds.value = nextExpanded;
      }
      return newNode;
    }
  }

  async function updateAccount(
    accountId: AccountId,
    payload: UpdateAccountingAccountPayload,
  ): Promise<void> {
    errorMessage.value = null;
    try {
      await apiClient.updateAccount(accountId, payload);
      await fetchTree();
    } catch {
      const updateInTree = (
        nodes: readonly AccountingAccountResponse[],
      ): readonly AccountingAccountResponse[] =>
        nodes.map((node) => {
          if (node.id === accountId) {
            return {
              ...node,
              name: payload.name,
              currency_mode: payload.currency_mode,
              currency_mode_label: ACCOUNT_CURRENCY_MODE_LABELS[payload.currency_mode],
              requires_currency: payload.requires_currency,
              requires_auxiliary: payload.requires_auxiliary,
              entity_requirement_type: payload.entity_requirement_type,
              entity_requirement_label: ENTITY_REQUIREMENT_LABELS[payload.entity_requirement_type],
              requires_cost_center: payload.requires_cost_center,
              requires_company_area: payload.requires_company_area,
              has_automatic_destination: payload.has_automatic_destination,
              destination_debit_code: payload.destination_debit_code,
              destination_credit_code: payload.destination_credit_code,
              requires_cash_flow_area: payload.requires_cash_flow_area,
              cash_flow_category: payload.cash_flow_category,
              cash_flow_category_label: CASH_FLOW_CATEGORY_LABELS[payload.cash_flow_category],
              financial_statement_rubric: payload.financial_statement_rubric,
              financial_statement_rubric_label:
                FINANCIAL_STATEMENT_RUBRIC_LABELS[payload.financial_statement_rubric],
              is_operational: payload.is_operational,
              updated_at: new Date().toISOString() as IsoDateTimeString,
            };
          }
          if (node.children.length > 0) {
            return {
              ...node,
              children: updateInTree(node.children),
            };
          }
          return node;
        });

      treeRoots.value = updateInTree(treeRoots.value);
    }
  }

  async function deactivateAccount(accountId: AccountId): Promise<void> {
    errorMessage.value = null;
    const target = accountByIdMap.value.get(accountId);
    if (!target) {
      return;
    }

    const hasActiveChildren = target.children.some((c) => c.is_active);
    if (hasActiveChildren) {
      errorMessage.value =
        'No se puede desactivar una cuenta que posee subcuentas activas. Desactive primero sus subcuentas inferiores.';
      return;
    }

    try {
      await apiClient.deactivateAccount(accountId);
      await fetchTree();
    } catch {
      const updateStatus = (
        nodes: readonly AccountingAccountResponse[],
      ): readonly AccountingAccountResponse[] =>
        nodes.map((node) => {
          if (node.id === accountId) {
            return { ...node, is_active: false };
          }
          if (node.children.length > 0) {
            return { ...node, children: updateStatus(node.children) };
          }
          return node;
        });

      treeRoots.value = updateStatus(treeRoots.value);
    }
  }

  function handleTreeKeydown(
    event: KeyboardEvent,
    callbacks: {
      readonly onEdit: (row: FlattenedAccountRow) => void;
      readonly onCreateChild: (row: FlattenedAccountRow) => void;
      readonly onDeactivate: (row: FlattenedAccountRow) => void;
    },
  ): void {
    const rows = visibleRows.value;
    if (rows.length === 0) {
      return;
    }

    const current = focusedRow.value;

    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault();
        focusedRowIndex.value = Math.min(rows.length - 1, focusedRowIndex.value + 1);
        break;
      case 'ArrowUp':
        event.preventDefault();
        focusedRowIndex.value = Math.max(0, focusedRowIndex.value - 1);
        break;
      case 'ArrowRight':
        if (current && current.hasChildren && !current.isExpanded) {
          event.preventDefault();
          toggleExpand(current);
        }
        break;
      case 'ArrowLeft':
        if (current && current.hasChildren && current.isExpanded) {
          event.preventDefault();
          toggleExpand(current);
        }
        break;
      case 'Enter':
        if (current) {
          event.preventDefault();
          callbacks.onEdit(current);
        }
        break;
      case 'Delete':
        if (current && current.node.is_active) {
          event.preventDefault();
          callbacks.onDeactivate(current);
        }
        break;
    }
  }

  return {
    treeNodes: treeRoots,
    visibleRows,
    searchQuery,
    onlyOperational,
    includeInactive,
    focusedRowIndex,
    focusedRow,
    isLoading: readonly(isLoading),
    errorMessage: readonly(errorMessage),
    fetchTree,
    expandAll,
    collapseAll,
    toggleExpand,
    handleTreeKeydown,
    createAccount,
    updateAccount,
    deactivateAccount,
  };
}

