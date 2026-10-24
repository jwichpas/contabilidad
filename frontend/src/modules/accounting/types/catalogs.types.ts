export interface CurrencyItem {
  id: number;
  code: string;
  name: string;
  symbol: string;
  iso_numeric_code: string | null;
  sunat_table_04_code: string | null;
  decimal_places: number;
  is_active: boolean;
}

export interface FinancialStatementRubricItem {
  id: number;
  code: string;
  name: string;
  statement_type: string;
  group_name: string;
  presentation_order: number;
  is_active: boolean;
}

export interface CostCenterItem {
  id: number;
  company_id: number;
  code: string;
  name: string;
  destination_debit_account_id: number | null;
  destination_credit_account_id: number | null;
  is_active: boolean;
  destination_debit_account?: {
    id: number;
    code: string;
    name: string;
  } | null;
  destination_credit_account?: {
    id: number;
    code: string;
    name: string;
  } | null;
}

export interface CompanyAreaItem {
  readonly id: number;
  readonly company_id: number;
  readonly code: string;
  readonly name: string;
  readonly is_active: boolean;
}

export interface CashFlowAreaItem {
  id: number;
  company_id: number;
  code: string;
  name: string;
  activity_category: string;
  flow_direction: string;
  is_active: boolean;
}

export interface SunatCatalogItem {
  id: number;
  code: string;
  description: string;
  is_active?: boolean;
  [key: string]: unknown;
}

export interface CompanySettingItem {
  id: number;
  company_id: number;
  functional_currency_code: string;
  presentation_currency_code: string;
  chart_of_accounts_version: string;
  current_fiscal_year: number;
  current_fiscal_month: number;
  cost_center_required: boolean;
  cash_flow_required: boolean;
  enable_dual_currency: boolean;
}

