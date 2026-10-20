import { computed, shallowRef } from 'vue';
import { AccountingApiClient } from '../api/httpClient';
import type { UiSelectOption } from '@/shared/ui/types';
import {
  ACCOUNT_CURRENCY_MODES,
  ACCOUNT_CURRENCY_MODE_LABELS,
  CASH_FLOW_CATEGORIES,
  CASH_FLOW_CATEGORY_LABELS,
  FINANCIAL_STATEMENT_RUBRICS,
  FINANCIAL_STATEMENT_RUBRIC_LABELS,
  type AccountCurrencyMode,
  type CashFlowCategory,
  type CompanyId,
  type FinancialStatementRubric,
} from '../types/accountingAccount.types';
import type {
  CashFlowAreaItem,
  CostCenterItem,
  CurrencyItem,
  FinancialStatementRubricItem,
  SunatCatalogItem,
} from '../types/catalogs.types';
import type { CompanySettingsData } from '@/modules/company/types/companySettings.types';

export interface UseAccountingCatalogsOptions {
  readonly getActiveCompanyId: () => CompanyId;
  readonly getAccessToken: () => string | null;
}

export function useAccountingCatalogs(options: UseAccountingCatalogsOptions) {
  const apiClient = new AccountingApiClient('', {
    getAccessToken: options.getAccessToken,
    getActiveCompanyId: options.getActiveCompanyId,
  });

  const currencies = shallowRef<readonly CurrencyItem[]>([]);
  const rubrics = shallowRef<readonly FinancialStatementRubricItem[]>([]);
  const costCenters = shallowRef<readonly CostCenterItem[]>([]);
  const cashFlowAreas = shallowRef<readonly CashFlowAreaItem[]>([]);
  const financialInstitutions = shallowRef<readonly SunatCatalogItem[]>([]);
  const companySettings = shallowRef<CompanySettingsData | null>(null);

  const isLoading = shallowRef(false);
  const error = shallowRef<string | null>(null);

  const splitPrefixedLabel = (label: string): { badge?: string; label: string } => {
    const [prefix, ...rest] = label.split(' · ');
    return rest.length > 0 ? { badge: prefix, label: rest.join(' · ') } : { label };
  };

  /**
   * Opciones para UiSelect de Monedas:
   * Prioriza los datos en vivo de la BD (con símbolo y código SUNAT),
   * o utiliza las opciones estándar del Enum si la API aún no responde.
   */
  const currencyOptions = computed<readonly UiSelectOption<AccountCurrencyMode>[]>(() => {
    if (currencies.value.length > 0) {
      const liveList: UiSelectOption<AccountCurrencyMode>[] = currencies.value.map((c) => ({
        value: c.code as AccountCurrencyMode,
        badge: c.code,
        label: `${c.name} (${c.symbol}) · Código SUNAT ${c.sunat_table_04_code ?? c.code}`,
        hint: `${c.decimal_places} decimales`,
      }));
      // Siempre permitir la opción MULTI
      liveList.push({
        value: 'MULTI',
        badge: 'MULTI',
        label: ACCOUNT_CURRENCY_MODE_LABELS.MULTI,
        hint: 'Permite transaccionar en Soles y Moneda Extranjera simultáneamente',
      });
      return liveList;
    }

    return ACCOUNT_CURRENCY_MODES.map((cm) => ({
      value: cm,
      badge: cm,
      label: ACCOUNT_CURRENCY_MODE_LABELS[cm],
    }));
  });

  /**
   * Opciones para UiSelect de Rubros de Estados Financieros:
   * Muestra la taxonomía oficial de la SMV y NIIF 18 / PCGE 2026.
   */
  const rubricOptions = computed<readonly UiSelectOption<FinancialStatementRubric>[]>(() => {
    if (rubrics.value.length > 0) {
      const list: UiSelectOption<FinancialStatementRubric>[] = [
        {
          value: 'NONE' as FinancialStatementRubric,
          label: 'Sin rubro de Estado Financiero (Cuenta de Agrupación)',
        },
      ];

      for (const r of rubrics.value) {
        list.push({
          value: r.code as FinancialStatementRubric,
          badge: r.statement_type,
          label: `${r.group_name} · ${r.name}`,
          hint: `Código oficial: ${r.code}`,
        });
      }
      return list;
    }

    return FINANCIAL_STATEMENT_RUBRICS.map((rub) => ({
      value: rub,
      ...splitPrefixedLabel(FINANCIAL_STATEMENT_RUBRIC_LABELS[rub]),
    }));
  });

  /**
   * Opciones para Centros de Costo de la empresa activa con amarre contable a 94 / 95 y 79.
   */
  const costCenterOptions = computed<readonly UiSelectOption<string>[]>(() => {
    return costCenters.value.map((cc) => ({
      value: cc.code,
      badge: cc.code,
      label: `${cc.name}`,
      hint: cc.destination_debit_account
        ? `Destino: ${cc.destination_debit_account.code} / ${cc.destination_credit_account?.code ?? '7911'}`
        : 'Sin cuenta de destino predeterminada',
    }));
  });

  /**
   * Opciones para Áreas de Flujo de Efectivo (NIC 7).
   */
  const cashFlowAreaOptions = computed<readonly UiSelectOption<string>[]>(() => {
    return cashFlowAreas.value.map((cfa) => ({
      value: cfa.code,
      badge: cfa.activity_category === 'OPERATING' ? 'Operación' : cfa.activity_category === 'INVESTING' ? 'Inversión' : 'Financiamiento',
      label: `${cfa.code} · ${cfa.name}`,
    }));
  });

  /**
   * Categorías estándar de Flujo de Efectivo para la cuenta contable.
   */
  const cashFlowCategoryOptions = computed<readonly UiSelectOption<CashFlowCategory>[]>(() => {
    return CASH_FLOW_CATEGORIES.map((cf) => ({
      value: cf,
      label: CASH_FLOW_CATEGORY_LABELS[cf],
    }));
  });

  /**
   * Instituciones Financieras oficiales de SUNAT (Tabla 03: BCP, BBVA, etc.).
   */
  const financialInstitutionOptions = computed<readonly UiSelectOption<string>[]>(() => {
    return financialInstitutions.value.map((fi) => ({
      value: fi.code,
      badge: fi.code,
      label: String(fi.description ?? fi.name ?? fi.code),
    }));
  });

  /**
   * Carga paralela de todos los catálogos oficiales desde Supabase / Laravel.
   */
  const loadCatalogs = async (): Promise<void> => {
    isLoading.value = true;
    error.value = null;

    try {
      const [
        currenciesRes,
        rubricsRes,
        costCentersRes,
        cashFlowAreasRes,
        sunatBancosRes,
        settingsRes,
      ] = await Promise.allSettled([
        apiClient.fetchCurrencies(),
        apiClient.fetchFinancialStatementRubrics(),
        apiClient.fetchCostCenters(),
        apiClient.fetchCashFlowAreas(),
        apiClient.fetchSunatTable(3),
        apiClient.fetchCompanySettings(),
      ]);

      if (currenciesRes.status === 'fulfilled') {
        currencies.value = currenciesRes.value;
      }
      if (rubricsRes.status === 'fulfilled') {
        rubrics.value = rubricsRes.value;
      }
      if (costCentersRes.status === 'fulfilled') {
        costCenters.value = costCentersRes.value;
      }
      if (cashFlowAreasRes.status === 'fulfilled') {
        cashFlowAreas.value = cashFlowAreasRes.value;
      }
      if (sunatBancosRes.status === 'fulfilled') {
        financialInstitutions.value = sunatBancosRes.value;
      }
      if (settingsRes.status === 'fulfilled') {
        companySettings.value = settingsRes.value;
      }
    } catch (e) {
      error.value = e instanceof Error ? e.message : 'Error al cargar los catálogos';
    } finally {
      isLoading.value = false;
    }
  };

  return {
    currencies,
    rubrics,
    costCenters,
    cashFlowAreas,
    financialInstitutions,
    companySettings,
    currencyOptions,
    rubricOptions,
    costCenterOptions,
    cashFlowAreaOptions,
    cashFlowCategoryOptions,
    financialInstitutionOptions,
    isLoading,
    error,
    loadCatalogs,
  };
}
