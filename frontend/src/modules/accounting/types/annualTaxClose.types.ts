export type TaxCloseStatus = 'DRAFT' | 'IN_REVIEW' | 'APPROVED';
export type TaxCloseAction = 'submit' | 'return' | 'approve' | 'rectify';

export interface TaxAdjustment {
  label: string;
  direction: 'ADDITION' | 'DEDUCTION';
  kind: 'PERMANENT' | 'TEMPORARY';
  book_base: string;
  tax_base: string;
  account?: string | null;
  reference?: string | null;
}

export interface TaxAdvance {
  month: number;
  assessed: string;
  cash_paid: string;
  itan_used: string;
  reference?: string | null;
}

export interface DeferredTaxLine {
  label: string;
  kind: 'ASSET' | 'LIABILITY';
  opening_tax: string;
  closing_difference: string;
  rate: string;
  recognize_closing: boolean;
  reference?: string | null;
}

export interface TaxCloseInputs {
  book_profit: string;
  book_reference?: string | null;
  prior_losses_used: string;
  participation_rate: string;
  participation_deductible: string;
  participation_admin?: string;
  participation_sales?: string;
  participation_reference?: string | null;
  adjustments: TaxAdjustment[];
  itan_assets: string;
  itan_additions: string;
  itan_deductions: string;
  itan_paid: string;
  itan_at_close: string;
  itan_reference?: string | null;
  advances: TaxAdvance[];
  ciprl_available: string;
  ciprl_applied: string;
  ciprl_reference?: string | null;
  deferred: DeferredTaxLine[];
}

export interface TaxCloseResult {
  adjustment_amounts: string[];
  casilla_103: string;
  casilla_105: string;
  adjusted_income: string;
  participation: string;
  taxable_income: string;
  current_tax: string;
  itan_base: string;
  itan_assessed: string;
  advances_cash: string;
  itan_in_advances: string;
  advances_total: string;
  settlement_before_ciprl: string;
  credit_balance: string;
  cash_to_pay: string;
  asset_opening: string;
  asset_closing: string;
  liability_opening: string;
  liability_closing: string;
  deferred_expense: string;
  tax_expense: string;
  net_profit: string;
  journal_proposals: Array<{
    code: string;
    title: string;
    lines: Array<{ account: string; debit: string; credit: string }>;
  }>;
}

export interface TaxCloseRevision {
  revision: number;
  status: TaxCloseStatus;
}

export interface TaxClose extends TaxCloseRevision {
  year: number;
  rule_version: string;
  latest_revision: number;
  inputs: TaxCloseInputs | null;
  result: TaxCloseResult | null;
  history: Array<TaxCloseRevision & { reason: string | null; created_by: number; created_at: string }>;
}

export type TaxOpeningSource = 'historical' | 'entry';

export interface TaxBalanceAmounts {
  opening_debit: string;
  opening_credit: string;
  movement_debit: string;
  movement_credit: string;
  transfer_debit: string;
  transfer_credit: string;
}

export interface TaxCloseTrialBalance {
  year: number;
  opening_source: TaxOpeningSource | null;
  opening_ambiguous: boolean;
  historical_opening_available: boolean;
  entry_opening_available: boolean;
  rows: Array<TaxBalanceAmounts & { code: string; accounts: string[] }>;
  unmapped: Array<TaxBalanceAmounts & { code: string; name: string }>;
  totals: TaxBalanceAmounts;
  mapped_totals: TaxBalanceAmounts;
  balanced: boolean;
}

export interface TaxJournalDraft {
  date: string;
  type: 'ADJUSTMENT' | 'CLOSING';
  description: string;
  lines: TaxCloseResult['journal_proposals'][number]['lines'];
}
