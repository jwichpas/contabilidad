/** A fiscal policy snapshot, append-only per company/year/effective date. */
export interface CompanyFiscalProfile {
  readonly id: number;
  readonly company_id: number;
  readonly fiscal_year: number;
  readonly revision: number;
  readonly effective_date: string;
  readonly tax_regime: FiscalTaxRegime;
  readonly legal_form: FiscalLegalForm;
  readonly income_reference_year: number | null;
  readonly annual_income: string | null;
  readonly default_vat_rate: string;
  readonly is_retention_agent: boolean;
  readonly is_perception_agent: boolean;
  readonly is_good_taxpayer: boolean;
  readonly is_sire_obliged: boolean;
  readonly is_ple_obliged: boolean;
  readonly change_reason: string;
  readonly created_by: number;
  readonly created_at: string;
}

export type FiscalTaxRegime = 'GENERAL' | 'MYPE_TRIBUTARIO' | 'RER' | 'ESPECIAL' | 'NRUS';
export type FiscalLegalForm =
  | 'PERSONA_NATURAL' | 'EIRL' | 'SAC' | 'SRL' | 'SA' | 'SAA' | 'SACS' | 'OTRA';

/** Never accepts client-selected company_id, revision, created_by or record ID. */
export interface CreateCompanyFiscalProfilePayload {
  readonly fiscal_year: number;
  readonly effective_date: string;
  readonly tax_regime: FiscalTaxRegime;
  readonly legal_form: FiscalLegalForm;
  readonly income_reference_year: number | null;
  readonly annual_income: string | null;
  readonly default_vat_rate: string;
  readonly is_retention_agent: boolean;
  readonly is_perception_agent: boolean;
  readonly is_good_taxpayer: boolean;
  readonly is_sire_obliged: boolean;
  readonly is_ple_obliged: boolean;
  readonly change_reason: string;
}
