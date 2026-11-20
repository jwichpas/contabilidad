export type FinancialReportCode = 'ESF' | 'ERI' | 'EFE' | 'ECP';
export interface FinancialReportLayoutSettings {
  readonly labels: Readonly<Record<string, string>>;
  readonly hidden_codes: readonly string[];
  /** Reorder detail items only within their immutable section. */
  readonly line_order: readonly string[];
  readonly hide_zero: boolean;
}
export interface FinancialReportLayoutCatalogEntry {
  readonly label: string;
  readonly group: string;
  readonly kind: 'detail' | 'protected';
}
export interface FinancialReportLayoutResponse {
  readonly data: {
    readonly revision: number;
    readonly settings: FinancialReportLayoutSettings;
    readonly created_at: string | null;
  };
  readonly catalog: Readonly<Record<string, FinancialReportLayoutCatalogEntry>>;
  readonly history: readonly {
    readonly revision: number; readonly created_at: string; readonly created_by: number;
  }[];
}
