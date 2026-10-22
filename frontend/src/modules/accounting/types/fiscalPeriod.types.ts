export type FiscalPeriodStatus = 'OPEN' | 'CLOSED' | 'LOCKED';

export type FiscalPeriodAction = 'close' | 'reopen' | 'lock';

export interface FiscalPeriodSummary {
  readonly id: number;
  readonly year: number;
  readonly month: number;
  readonly start_date: string;
  readonly end_date: string;
  readonly status: FiscalPeriodStatus;
  readonly closed_at: string | null;
  readonly entry_count: number;
}

export interface FiscalPeriodTransitionResult {
  readonly message: string;
  readonly data: {
    readonly id: number;
    readonly year: number;
    readonly month: number;
    readonly status: FiscalPeriodStatus;
    readonly closed_at: string | null;
  };
}
