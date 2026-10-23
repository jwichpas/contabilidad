export type NavigationModule = 'catalog' | 'journal' | 'company' | 'company-create' | 'entities' | 'exchange-rates' | 'fiscal-periods';

export interface ActiveCompanyInfo {
  readonly id: number;
  readonly taxId: string;
  readonly businessName: string;
  readonly fiscalRegime: string;
}
