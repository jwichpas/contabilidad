import type { EconomicSector, LegalForm } from './companySettings.types';

export type TaxRegime = 'NRUS' | 'RER' | 'MYPE_TRIBUTARIO' | 'GENERAL';
export type PcgeVersion = 'PCGE_2019' | 'PCGE_2026';

export interface SunatContributorType { code: string; name: string; is_active: boolean }
export interface SunatCiiuClass {
  code: string; division: string; group_code: string;
  class_number: string; name: string; is_active: boolean;
}
export interface SunatTaxCode { code: string; short_name: string; name: string; is_active: boolean }


export interface OnboardingActivity {
  sector_code: EconomicSector;
  ciiu_code: string;
  description: string;
  is_primary: boolean;
}

export interface CompanyOnboardingDraft {
  tax_id: string;
  business_name: string;
  legal_address: string;
  legal_form: LegalForm | '';
  sunat_contributor_type_code: string;
  tax_regime: TaxRegime;
  activities: OnboardingActivity[];
  tax_codes: string[];
  fiscal_year: number;
  income_reference_year: number;
  annual_income: string;
  base_currency_code: string;
  secondary_currency_codes: string[];
  pcge_version: PcgeVersion;
  confirm_legal_data: boolean;
  confirm_early_adoption: boolean;
}

export interface CompanyOnboardingResult {
  id: number;
  tax_id: string;
  business_name: string;
  legal_address: string;
  uuid: string;
  plan_version: PcgeVersion;
  plan_accounts_created: number;
}

export interface PcgeTemplateSummary {
  plan_version: PcgeVersion;
  name: string;
  total_accounts: number;
  is_current_default: boolean;
}

/** Este borrador es privado a la pestaña del navegador y nunca se envía solo. */
export const COMPANY_ONBOARDING_DRAFT_KEY = 'contabilidad.company-onboarding.v1';

export function newCompanyOnboardingDraft(year: number = new Date().getFullYear()): CompanyOnboardingDraft {
  return {
    tax_id: '',
    business_name: '',
    legal_address: '',
    legal_form: '',
    sunat_contributor_type_code: '',
    tax_regime: 'GENERAL',
    activities: [{ sector_code: 'COMERCIAL', ciiu_code: '', description: '', is_primary: true }],
    tax_codes: [],
    fiscal_year: year,
    income_reference_year: year - 1,
    annual_income: '',
    base_currency_code: 'PEN',
    secondary_currency_codes: ['USD'],
    pcge_version: 'PCGE_2019',
    confirm_legal_data: false,
    confirm_early_adoption: false,
  };
}

function rucCheckDigit(ruc: string): number {
  const weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
  const sum = weights.reduce((acc, weight, i) => acc + Number(ruc[i]) * weight, 0);
  const result = 11 - (sum % 11);
  return result === 10 ? 0 : result === 11 ? 1 : result;
}

export function isValidPeruvianRuc(value: string): boolean {
  const ruc = value.trim();
  return /^\d{11}$/.test(ruc)
    && /^(10|15|16|17|20)/.test(ruc)
    && Number(ruc[10]) === rucCheckDigit(ruc);
}

export function onboardingStepErrors(step: number, draft: CompanyOnboardingDraft): Record<string, string> {
  const errors: Record<string, string> = {};

  if (step === 0) {
    if (!isValidPeruvianRuc(draft.tax_id)) errors.tax_id = 'Ingrese un RUC peruano válido (11 dígitos y dígito verificador).';
    if (draft.business_name.trim().length < 3 || draft.business_name.trim().length > 200) {
      errors.business_name = 'La razón social debe contener entre 3 y 200 caracteres.';
    }
    if (draft.legal_address.trim().length < 5 || draft.legal_address.trim().length > 300) {
      errors.legal_address = 'Ingrese el domicilio fiscal (entre 5 y 300 caracteres).';
    }
    if (!draft.legal_form) errors.legal_form = 'Seleccione la forma jurídica.';
    if (!/^\\d{2}$/.test(draft.sunat_contributor_type_code)) errors.sunat_contributor_type_code = 'Seleccione el tipo de contribuyente del Anexo 2 SUNAT.';
  }

  if (step === 1) {
    if (!draft.activities.length || draft.activities.length > 10) {
      errors.activities = 'Registre de una a diez actividades económicas.';
    }
    if (draft.activities.filter(a => a.is_primary).length !== 1) {
      errors.activities = 'Seleccione exactamente una actividad principal.';
    }
    const uniqueCodes = new Set<string>();
    for (const [index, activity] of draft.activities.entries()) {
      if (!/^\d{4}$/.test(activity.ciiu_code)) errors[`ciiu_${index}`] = 'CIIU de cuatro dígitos.';
      if (!activity.description.trim() || activity.description.length > 200) {
        errors[`description_${index}`] = 'Indique la actividad (máximo 200 caracteres).';
      }
      if (uniqueCodes.has(activity.ciiu_code)) errors[`ciiu_${index}`] = 'Este código ya fue agregado.';
      uniqueCodes.add(activity.ciiu_code);
    }
  }

  if (step === 2) {
    if (draft.tax_regime === 'NRUS' && draft.legal_form !== 'PERSONA_NATURAL') {
      errors.tax_regime = 'El Nuevo RUS exige una persona natural que cumpla todos los requisitos.';
    }
    if (!Number.isInteger(draft.fiscal_year) || draft.fiscal_year < 2000 || draft.fiscal_year > 2100) {
      errors.fiscal_year = 'Seleccione un ejercicio válido.';
    }
    if (!Number.isInteger(draft.income_reference_year) ||
        draft.income_reference_year < 2000 || draft.income_reference_year > draft.fiscal_year) {
      errors.income_reference_year = 'Seleccione el año de referencia de ingresos.';
    }
    if (!/^\d+(?:\.\d{1,2})?$/.test(draft.annual_income.trim())) {
      errors.annual_income = 'Ingrese ingresos en soles con hasta dos decimales, sin separadores de miles.';
    }
  }

  if (step === 3) {
    if (!draft.base_currency_code) errors.base_currency_code = 'Seleccione la moneda funcional.';
    if (draft.secondary_currency_codes.includes(draft.base_currency_code)) {
      errors.secondary_currency_codes = 'La moneda funcional no puede ser secundaria.';
    }
    if (new Set(draft.secondary_currency_codes).size !== draft.secondary_currency_codes.length) {
      errors.secondary_currency_codes = 'No repita monedas secundarias.';
    }
    if (draft.pcge_version === 'PCGE_2026' && draft.fiscal_year < 2028 && !draft.confirm_early_adoption) {
      errors.confirm_early_adoption = 'Confirme expresamente la aplicación anticipada del PCGE 2026.';
    }
  }

  if (step === 4 && !draft.confirm_legal_data) {
    errors.confirm_legal_data = 'Confirme que revisó los datos y la clasificación.';
  }
  return errors;
}

export function onboardingIsComplete(draft: CompanyOnboardingDraft): boolean {
  return [0, 1, 2, 3, 4].every(step => Object.keys(onboardingStepErrors(step, draft)).length === 0);
}

export function companyOnboardingPayload(draft: CompanyOnboardingDraft) {
  return {
    tax_id: draft.tax_id.trim(),
    business_name: draft.business_name.trim(),
    legal_address: draft.legal_address.trim(),
    legal_form: draft.legal_form,
    sunat_contributor_type_code: draft.sunat_contributor_type_code,
    tax_regime: draft.tax_regime,
    tax_codes: draft.tax_codes,
    economic_activities: draft.activities.map(a => ({
      sector_code: a.sector_code, ciiu_code: a.ciiu_code,
      description: a.description.trim(), is_primary: a.is_primary,
    })),
    fiscal_year: draft.fiscal_year,
    income_reference_year: draft.income_reference_year,
    annual_income: draft.annual_income.trim(),
    base_currency_code: draft.base_currency_code,
    secondary_currency_codes: draft.secondary_currency_codes,
    pcge_version: draft.pcge_version,
    confirm_early_adoption: draft.confirm_early_adoption,
    confirm_legal_data: draft.confirm_legal_data,
  };
}

export type CreateCompanyOnboardingPayload = ReturnType<typeof companyOnboardingPayload>;
