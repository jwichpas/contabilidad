import type { CurrencyItem } from '@/modules/accounting/types/catalogs.types';

export type LegalForm =
  | 'PERSONA_NATURAL' | 'EIRL' | 'SAC' | 'SRL' | 'SA' | 'SAA' | 'SACS' | 'OTRA';

export type EconomicSector =
  | 'COMERCIAL' | 'SERVICIOS' | 'INDUSTRIAL' | 'AGRICOLA' | 'GANADERIA'
  | 'PESCA' | 'CONSTRUCCION' | 'EXTRACTIVA' | 'TRANSPORTE_LOGISTICA' | 'OTROS';

export interface CompanyEconomicActivity {
  readonly id?: number;
  readonly sector_code: EconomicSector;
  readonly ciiu_code: string;
  readonly description: string;
  readonly is_primary: boolean;
}

export interface CompanyInfo {
  readonly id: number;
  readonly uuid?: string;
  readonly tax_id: string;
  readonly business_name: string;
  readonly legal_address: string;
  readonly is_active: boolean;
}

export interface CompanyCurrencyItem {
  readonly id: number;
  readonly company_id: number;
  readonly currency_id: number;
  readonly is_base: boolean;
  readonly is_active: boolean;
  readonly currency: CurrencyItem;
}

export interface CompanySettingDetails {
  readonly id: number;
  readonly company_id: number;
  readonly base_currency_id: number;
  readonly secondary_currency_id?: number | null;
  readonly tax_regime: 'GENERAL' | 'MYPE_TRIBUTARIO' | 'RER' | 'ESPECIAL' | 'NRUS';
  readonly legal_form?: LegalForm | null;
  readonly default_vat_rate: string;
  readonly is_retention_agent: boolean;
  readonly is_perception_agent: boolean;
  readonly is_good_taxpayer: boolean;
  readonly is_sire_obliged: boolean;
  readonly is_ple_obliged: boolean;
  readonly legal_representative_name?: string | null;
  readonly legal_rep_identity_doc_code?: string | null;
  readonly legal_representative_id_number?: string | null;
  readonly chief_accountant_name?: string | null;
  readonly chief_accountant_cpc_number?: string | null;
  readonly sales_vat_account_id?: number | null;
  readonly purchase_vat_account_id?: number | null;
  readonly fx_gain_account_id?: number | null;
  readonly fx_loss_account_id?: number | null;
  readonly cost_absorption_account_id?: number | null;
  readonly retained_earnings_account_id?: number | null;
  readonly base_currency?: CurrencyItem;
  readonly secondary_currency?: CurrencyItem;
}

export interface CompanySettingsData {
  readonly company: CompanyInfo;
  readonly settings: CompanySettingDetails;
  readonly company_currencies: readonly CompanyCurrencyItem[];
  readonly all_currencies: readonly CurrencyItem[];
  readonly economic_profile_ready?: boolean;
  readonly economic_activities?: readonly CompanyEconomicActivity[];
  readonly economic_sectors?: readonly EconomicSector[];
  readonly legal_forms?: readonly LegalForm[];
}

export interface UpdateCompanySettingsPayload {
  readonly business_name?: string;
  readonly legal_address?: string;
  readonly tax_id?: string;
  readonly base_currency_id?: number;
  readonly secondary_currency_ids?: readonly number[];
  readonly tax_regime?: string;
  readonly legal_form?: LegalForm | null;
  readonly economic_activities?: readonly Omit<CompanyEconomicActivity, 'id'>[];
  readonly default_vat_rate?: number;
  readonly is_retention_agent?: boolean;
  readonly is_perception_agent?: boolean;
  readonly is_good_taxpayer?: boolean;
  readonly is_sire_obliged?: boolean;
  readonly is_ple_obliged?: boolean;
  readonly legal_representative_name?: string;
  readonly legal_rep_identity_doc_code?: string;
  readonly legal_representative_id_number?: string;
  readonly chief_accountant_name?: string;
  readonly chief_accountant_cpc_number?: string;
}

export interface RequiredExchangeRateItem {
  readonly currency_id: number;
  readonly code: string;
  readonly name: string;
  readonly symbol: string;
  readonly buy_rate: string;
  readonly sell_rate: string;
  readonly is_recorded_for_date: boolean;
  readonly source: string;
}

export interface HistoricalExchangeRateItem {
  readonly id: number;
  readonly rate_date: string;
  readonly source_currency: string;
  readonly target_currency: string;
  readonly buy_rate: string;
  readonly sell_rate: string;
}

export interface CompanyExchangeRatesResponse {
  readonly rate_date: string;
  readonly base_currency: {
    readonly code: string;
    readonly name: string;
    readonly symbol: string;
  };
  readonly required_currencies: readonly RequiredExchangeRateItem[];
  readonly recent_history?: readonly HistoricalExchangeRateItem[];
}

export interface SaveCompanyExchangeRatesPayload {
  readonly rate_date: string;
  readonly rates: ReadonlyArray<{
    readonly currency: string;
    readonly buy_rate: number;
    readonly sell_rate: number;
  }>;
}
