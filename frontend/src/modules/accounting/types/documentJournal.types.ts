/** Asientos por comprobante, siempre aislados por empresa y módulo. */
export type DocumentJournalModule = 'purchases' | 'sales';
export type PurchaseTaxCategory =
  | 'P_TAXED_EXCLUSIVE' | 'P_TAXED_MIXED' | 'P_TAXED_NON_TAXED'
  | 'P_TAXED_NO_CREDIT' | 'P_NON_TAXED';
export type SaleTaxCategory = 'S_TAXED' | 'S_EXONERATED' | 'S_UNAFFECTED' | 'S_EXPORT';
export type TaxCategory = PurchaseTaxCategory | SaleTaxCategory;

export const TAX_CATEGORY_LABELS: Record<TaxCategory, string> = {
  P_TAXED_EXCLUSIVE: 'Compra gravada · destino gravado/exportación',
  P_TAXED_MIXED: 'Compra gravada · destino mixto (prorrata)',
  P_TAXED_NON_TAXED: 'Compra gravada · destino no gravado',
  P_TAXED_NO_CREDIT: 'Compra gravada · sin crédito fiscal',
  P_NON_TAXED: 'Compra no gravada',
  S_TAXED: 'Venta gravada',
  S_EXONERATED: 'Venta exonerada',
  S_UNAFFECTED: 'Venta inafecta',
  S_EXPORT: 'Exportación',
};

export interface TaxAllocationInput {
  readonly category: TaxCategory;
  readonly base_amount: string;
  readonly tax_amount: string;
  readonly creditable_tax_amount?: string;
}

export interface DocumentJournalSettings {
  readonly id?: number;
  readonly module: 'PURCHASE' | 'SALE';
  readonly subdiary_code: string;
  readonly main_account_id: number | null;
  readonly tax_account_id: number | null;
  readonly settlement_account_id: number | null;
  readonly default_tax_rate: string;
  readonly category_account_map: Readonly<Record<string, number>> | null;
}

export type UpdateDocumentJournalSettings = Pick<DocumentJournalSettings,
  'subdiary_code' | 'main_account_id' | 'tax_account_id' | 'settlement_account_id' | 'default_tax_rate'>;

export interface DocumentJournalInput {
  readonly document_type: '01' | '03';
  readonly series: string;
  readonly number: string;
  readonly entity_id: number;
  readonly issue_date: string;
  readonly entry_date: string;
  readonly currency: string;
  readonly taxable_amount?: string;
  readonly tax_amount?: string;
  readonly tax_period?: string;
  readonly due_date?: string | null;
  readonly tax_allocations: readonly TaxAllocationInput[];
  readonly cost_center_id?: number | null;
  readonly company_area_id?: number | null;
  readonly cash_flow_area_id?: number | null;
  readonly description?: string;
}

export interface DocumentJournalRecord {
  readonly id: number;
  readonly module: 'PURCHASE' | 'SALE';
  readonly document_type: string;
  readonly series: string;
  readonly number: string;
  readonly entry_date: string;
  readonly currency: string;
  readonly total_amount: string;
  readonly journal_entry_id: number;
  readonly tax_register?: TaxRegisterEntry;
  readonly entity: { readonly id: number; readonly tax_id: string; readonly legal_name: string };
  readonly journal: { readonly id: number; readonly entry_number: string; readonly status: string; readonly subdiary_code: string };
}

export interface DocumentJournalPage {
  readonly data: readonly DocumentJournalRecord[];
  readonly current_page: number;
  readonly last_page: number;
  readonly total: number;
}

export interface DocumentJournalEntity {
  readonly id: number;
  readonly tax_id: string;
  readonly legal_name: string;
}

export interface DocumentJournalEntityPage {
  readonly data: readonly DocumentJournalEntity[];
}


export interface TaxAllocationRecord {
  readonly id: number;
  readonly category: TaxCategory;
  readonly base_amount: string;
  readonly tax_amount: string;
  readonly creditable_tax_amount: string;
}

export interface TaxRegisterEntry {
  readonly id: number;
  readonly module: 'PURCHASE' | 'SALE';
  readonly tax_period: string;
  readonly source: 'INTERNAL';
  readonly reconciliation_status: 'PENDING_REVIEW';
  readonly counterparty_tax_id: string;
  readonly counterparty_name: string;
  readonly due_date?: string | null;
  readonly allocations: readonly TaxAllocationRecord[];
  readonly document: DocumentJournalRecord;
}

export interface TaxRegisterPage {
  readonly data: readonly TaxRegisterEntry[];
  readonly current_page: number;
  readonly last_page: number;
  readonly total: number;
}

export interface TaxRegisterSummary {
  readonly module: 'PURCHASE' | 'SALE';
  readonly period: string;
  readonly registration: 'INTERNAL_PENDING_SIRE_RECONCILIATION';
  readonly breakdown: readonly {
    readonly category: TaxCategory;
    readonly currency: string;
    readonly document_count: number;
    readonly base_amount: string;
    readonly tax_amount: string;
    readonly creditable_tax_amount: string;
  }[];
}
