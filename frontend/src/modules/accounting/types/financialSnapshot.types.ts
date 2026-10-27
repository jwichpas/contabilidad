export interface FinancialSnapshotAccount {
  readonly id: number;
  readonly code: string;
  readonly name: string;
  readonly account_type: string;
  readonly rubric: string;
  readonly section: 'assets' | 'liabilities' | 'equity' | 'result' | 'unclassified' | 'excluded';
  readonly debit_local: string;
  readonly credit_local: string;
  readonly debit_foreign: string;
  readonly credit_foreign: string;
  readonly amount_local: string;
  readonly period_amount_local: string | null;
}

export interface FinancialSnapshotRubric {
  readonly code: string;
  readonly name: string;
  readonly group_name: string;
  readonly section: FinancialSnapshotAccount['section'];
  readonly presentation_order: number;
  readonly amount_local: string;
  readonly period_amount_local: string;
  readonly account_ids: readonly number[];
}

export interface FinancialSnapshot {
  readonly as_of: string;
  readonly currency: string; // ISO 4217: moneda funcional de la empresa
  readonly is_preliminary: boolean;
  readonly analytic_routing_status: 'ACTIVE' | 'DRAFT' | 'NOT_CONFIGURED';
  readonly accounts: readonly FinancialSnapshotAccount[];
  readonly rubrics: readonly FinancialSnapshotRubric[];
  readonly unmapped_account_ids: readonly number[];
  readonly income_statement: {
    readonly from: string;
    readonly to: string;
    readonly revenue: string;
    readonly expenses: string;
    readonly net: string;
  };
  readonly totals: Readonly<Record<'assets' | 'liabilities' | 'equity' | 'result' | 'unclassified' | 'excluded' | 'debit_local' | 'credit_local' | 'liabilities_equity_result' | 'difference' | 'trial_difference', string>>;
}

export interface GeneralLedgerPage {
  readonly account: { readonly id: number; readonly code: string; readonly name: string };
  readonly from: string;
  readonly to: string;
  readonly opening_local: string;
  readonly opening_foreign: string;
  readonly rows: readonly {
    readonly id: number;
    readonly entry_id: number;
    readonly entry_date: string;
    readonly entry_number: string;
    readonly description: string;
    readonly status: string;
    readonly debit_local: string;
    readonly credit_local: string;
    readonly debit_foreign: string;
    readonly credit_foreign: string;
    readonly balance_local: string;
    readonly balance_foreign: string;
  }[];
  readonly page: number;
  readonly last_page: number;
  readonly total: number;
}
