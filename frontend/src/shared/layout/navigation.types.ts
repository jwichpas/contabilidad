export type NavigationModule = 'catalog' | 'journal' | 'company' | 'exchange-rates';

export interface ActiveCompanyInfo {
  readonly id: number;
  readonly taxId: string;
  readonly businessName: string;
  readonly fiscalRegime: string;
}
