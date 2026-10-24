export type EntryType = 'STANDARD' | 'OPENING' | 'ADJUSTMENT' | 'REVERSAL' | 'CLOSING';
export type EntryStatus = 'POSTED' | 'REVERSED';
export type CurrencyCode = 'PEN' | 'USD' | 'EUR';
export type ExchangeRateType = 'BUY' | 'SELL' | 'PARITY';

export interface OperationalAccount {
  readonly id: number;
  readonly uuid?: string;
  readonly code: string;
  readonly name: string;
  readonly level: number;
  readonly account_type: string;
  readonly balance_type: string;
  readonly currency_mode: string;
  readonly requires_cost_center: boolean;
  readonly requires_auxiliary: boolean;
  readonly entity_requirement_type: string;
  readonly requires_cash_flow_area: boolean;
}

export interface JournalEntryLineResponse {
  readonly id: number;
  readonly journal_entry_id: number;
  readonly line_number: number;
  readonly accounting_account_id: number;
  readonly cost_center_id?: number | null;
  readonly entity_id?: number | null;
  readonly entity?: { readonly id: number; readonly tax_id: string; readonly legal_name: string } | null;
  readonly cost_center?: {
    readonly id: number;
    readonly code: string;
    readonly name: string;
  } | null;
  readonly description: string | null;
  readonly debit_local: string;
  readonly credit_local: string;
  readonly debit_foreign: string;
  readonly credit_foreign: string;
  readonly exchange_rate: string;
  readonly account?: {
    readonly id: number;
    readonly code: string;
    readonly name: string;
  };
}

export interface JournalEntryResponse {
  readonly id: number;
  readonly uuid: string;
  readonly company_id: number;
  readonly fiscal_period_id: number;
  readonly entry_number: string;
  readonly entry_date: string;
  readonly entry_type: EntryType;
  readonly status: EntryStatus;
  readonly currency: CurrencyCode;
  readonly exchange_rate: string;
  readonly exchange_rate_type: ExchangeRateType;
  readonly description: string;
  readonly created_by_user_id: number;
  readonly created_at: string;
  readonly lines: readonly JournalEntryLineResponse[];
  readonly fiscal_period?: {
    readonly id: number;
    readonly year: number;
    readonly month: number;
    readonly status: string;
  };
}

export interface CreateJournalEntryLinePayload {
  readonly accounting_account_id: number;
  readonly cost_center_id?: number | null;
  readonly entity_id?: number | null;
  readonly description?: string | null;
  readonly debit_local: string;
  readonly credit_local: string;
  readonly debit_foreign: string;
  readonly credit_foreign: string;
}

export interface CreateJournalEntryPayload {
  readonly entry_date: string;
  readonly entry_number?: string;
  readonly entry_type?: EntryType;
  readonly currency: CurrencyCode;
  readonly exchange_rate: string;
  readonly exchange_rate_type?: ExchangeRateType;
  readonly exchange_rates?: ReadonlyArray<{
    readonly currency: string;
    readonly rate: string;
    readonly buy_rate?: string;
    readonly sell_rate?: string;
  }>;
  readonly description: string;
  readonly lines: readonly CreateJournalEntryLinePayload[];
}

export interface NextCorrelativeResponse {
  readonly next_number: string;
  readonly period_year: number;
  readonly period_month: number;
  readonly is_period_open: boolean;
}
