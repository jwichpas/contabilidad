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
}

export interface FinancialSnapshot {
  readonly as_of: string;
  readonly currency: 'PEN';
  readonly is_preliminary: boolean;
  readonly accounts: readonly FinancialSnapshotAccount[];
  readonly totals: Readonly<Record<'assets' | 'liabilities' | 'equity' | 'result' | 'unclassified' | 'excluded' | 'debit_local' | 'credit_local' | 'liabilities_equity_result' | 'difference' | 'trial_difference', string>>;
}
