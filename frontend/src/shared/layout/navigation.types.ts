export type NavigationModule = 'catalog' | 'see-references' | 'sunat-references' | 'journal' | 'purchases' | 'sales' | 'financial-statements' | 'company' | 'company-create' | 'entities' | 'fiscal-history' | 'cost-centers' | 'subdiaries' | 'exchange-rates' | 'fiscal-periods';

export interface ActiveCompanyInfo {
  readonly id: number;
  readonly taxId: string;
  readonly businessName: string;
  readonly fiscalRegime: string;
}

