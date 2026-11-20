export interface TrialBalanceRow {
  readonly account_id: number; readonly code: string; readonly name: string;
  readonly opening_debit: string; readonly opening_credit: string;
  readonly movement_debit: string; readonly movement_credit: string;
  readonly closing_debit: string; readonly closing_credit: string;
}
export interface TrialBalanceReport {
  readonly from: string; readonly to: string; readonly currency: string;
  readonly rows: readonly TrialBalanceRow[];
  readonly totals: Readonly<Record<
    'opening_debit'|'opening_credit'|'movement_debit'|'movement_credit'|
    'closing_debit'|'closing_credit',string>>;
  readonly differences: {readonly opening:string;readonly movements:string;readonly closing:string};
  readonly balanced: boolean; readonly is_preliminary: true; readonly official_sunat_file: false;
}
export interface EquityComponent {
  readonly key: string;readonly label:string;
  readonly opening:string;readonly opening_entries:string;
  readonly other_movements:string;readonly closing:string;
  readonly account_ids: readonly number[];
}
export interface EquityChangesReport {
  readonly from:string;readonly to:string;readonly currency:string;
  readonly components:readonly EquityComponent[];
  readonly prior_unclosed_result:string;readonly period_result:string;
  readonly opening_equity:string;readonly opening_entries:string;
  readonly other_equity_movements:string;readonly closing_equity_including_result:string;
  readonly bridge_difference:string;
  readonly is_preliminary:true;readonly oci_assessed:false;
  readonly warnings:readonly string[];
}
export interface IndirectCashFlowReport {
  readonly from:string;readonly to:string;readonly currency:string; readonly method:'INDIRECT';
  readonly adjustments: Readonly<Record<
    'net_result'|'noncash_depreciation'|'receivables'|'inventory'|
    'trade_payables'|'tax_liabilities'|'other_working_capital',string>>;
  readonly account_sources: Readonly<Record<string,readonly number[]>>;
  readonly operating_indirect:string;readonly operating_direct:string;readonly operating_difference:string;
  readonly investing_local:string;readonly financing_local:string;
  readonly exchange_effect_local:string;readonly other_adjustments_local:string;
  readonly opening_local:string;readonly closing_book_local:string;
  readonly closing_indirect_local:string;readonly closing_difference:string;
  readonly reconciled:boolean;readonly is_preliminary:true;
  readonly warnings:readonly string[];
}
export interface NiifNoteDraft {
  readonly code:string; readonly title:string; readonly revision:number;
  readonly narrative:string;readonly status:'DRAFT';
  readonly created_at:string|null;readonly created_by:number|null;
}
export interface NiifNotesResponse {
  readonly year:number; readonly notes:readonly NiifNoteDraft[];
  readonly is_approved:false;readonly warning:string;
}
