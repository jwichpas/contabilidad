/** Asientos por comprobante, siempre aislados por empresa y módulo. */
export type DocumentJournalModule = 'purchases' | 'sales';

export interface DocumentJournalSettings {
  readonly id?: number;
  readonly module: 'PURCHASE' | 'SALE';
  readonly subdiary_code: string;
  readonly main_account_id: number | null;
  readonly tax_account_id: number | null;
  readonly settlement_account_id: number | null;
  readonly default_tax_rate: string;
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
  readonly taxable_amount: string;
  readonly tax_amount: string;
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
