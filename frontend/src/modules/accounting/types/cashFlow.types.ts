export type CashFlowSection = 'OPERATING' | 'INVESTING' | 'FINANCING' | 'UNCLASSIFIED' | 'TRANSFER' | 'EXCHANGE_EFFECT' | 'OPENING' | 'OTHER_ADJUSTMENT';
export type CashFlowFilter = CashFlowSection | 'ALL';

export interface CashFlowMovement {
  readonly id: number;
  readonly entry_id: number;
  readonly entry_date: string;
  readonly entry_number: string;
  readonly entry_type: string;
  readonly status: string;
  readonly description: string;
  readonly account_code: string;
  readonly account_name: string;
  readonly area_code: string | null;
  readonly area_name: string | null;
  readonly section: CashFlowSection;
  readonly reason: string | null;
  readonly amount_local: string;
}

export interface CashFlowGroup {
  readonly section: 'OPERATING' | 'INVESTING' | 'FINANCING';
  readonly area_id: number;
  readonly code: string;
  readonly name: string;
  readonly inflow_local: string;
  readonly outflow_local: string;
  readonly net_local: string;
  readonly count: number;
  readonly concept_code?: string | null;
  readonly concept_source?: 'COUNTERPART' | 'COMPANY_ACCOUNT' | 'COMPANY_AREA' | null;
}

export interface CashFlowReport {
  readonly from: string;
  readonly to: string;
  readonly method: 'DIRECT';
  readonly currency: string;
  readonly is_preliminary: boolean;
  readonly classification_complete: boolean;
  readonly is_reconciled: boolean;
  readonly counts: Readonly<Record<CashFlowSection, number>>;
  readonly totals: Readonly<Record<
    'opening_book_local' | 'opening_entries_local' | 'opening_local' | 'closing_local' |
    'operating_local' | 'investing_local' | 'financing_local' | 'net_flow_local' | 'change_local' |
    'exchange_effect_local' | 'other_adjustments_local' | 'unclassified_local' |
    'expected_closing_local' | 'difference_local', string
  >>;
  readonly groups: readonly CashFlowGroup[];
  readonly cash_accounts: readonly {
    readonly id: number; readonly code: string; readonly name: string;
    readonly opening_local: string; readonly closing_local: string;
  }[];
  readonly unconfigured_accounts: readonly { readonly id: number; readonly code: string; readonly name: string }[];
  readonly details: {
    readonly section: CashFlowFilter;
    readonly area_id: number | null;
    readonly rows: readonly CashFlowMovement[];
    readonly page: number; readonly per_page: number; readonly total: number; readonly last_page: number;
  };
}

export interface CashFlowQuery {
  readonly from: string; readonly to: string;
  readonly section?: CashFlowFilter; readonly area_id?: number; readonly page?: number;
}

export const CASH_FLOW_LABELS: Readonly<Record<CashFlowFilter, string>> = {
  ALL: 'Todos los movimientos', OPERATING: 'Operación', INVESTING: 'Inversión', FINANCING: 'Financiamiento',
  UNCLASSIFIED: 'Sin clasificar', TRANSFER: 'Transferencias internas', EXCHANGE_EFFECT: 'Efecto de cambio',
  OPENING: 'Apertura al inicio', OTHER_ADJUSTMENT: 'Otros ajustes a revisar',
};

export const CASH_FLOW_REASONS: Readonly<Record<string, string>> = {
  MISSING_AREA: 'Falta un área de operación, inversión o financiamiento en la partida de efectivo.',
  AREA_DIRECTION: 'El área no corresponde al sentido del cobro o pago original.',
  MIXED_CASH_SIDES: 'Asiento con entradas y salidas de efectivo y otras contrapartidas; requiere separar la transferencia del cobro o pago.',
  UNKNOWN_REVERSAL: 'No se pudo identificar el tipo del asiento original.',
  LATE_OPENING: 'Apertura dentro del período: revisar para evitar duplicar saldos.',
  CLOSING_CASH: 'El asiento de cierre mueve efectivo y requiere revisión.',
};

export type CashFlowActivity = 'OPERATING' | 'INVESTING' | 'FINANCING';
export type CashFlowDirection = 'INFLOW' | 'OUTFLOW';

export interface CashFlowCustomLine {
  readonly code: string;
  readonly label: string;
  readonly section: CashFlowActivity;
  readonly direction: CashFlowDirection;
}
export interface CashFlowAccountPresentationRule {
  readonly account_code: string;
  readonly section: CashFlowActivity;
  readonly direction: CashFlowDirection;
  readonly target_code: string;
}
export interface CashFlowAreaPresentationRule {
  readonly area_code: string;
  readonly section: CashFlowActivity;
  readonly direction: CashFlowDirection;
  readonly target_code: string;
}
export interface CashFlowPresentationSettings {
  readonly hide_zero: boolean;
  readonly labels: Readonly<Record<string, string>>;
  readonly hidden_codes: readonly string[];
  readonly custom_lines: readonly CashFlowCustomLine[];
  readonly account_rules: readonly CashFlowAccountPresentationRule[];
  readonly area_rules: readonly CashFlowAreaPresentationRule[];
}
export interface CashFlowPresentationRevision {
  readonly revision: number;
  readonly settings: CashFlowPresentationSettings;
  readonly created_at?: string | null;
  readonly created_by?: number | null;
}
export interface CashFlowPresentationResponse {
  readonly data: CashFlowPresentationRevision;
  readonly standard: Readonly<Record<string, {
    readonly label: string;
    readonly kind: string;
    readonly section: CashFlowActivity | null;
    readonly direction: CashFlowDirection | null;
  }>>;
  readonly history: readonly { readonly revision: number; readonly created_at: string; readonly created_by: number }[];
}

// Twelve months are calculated server-side using a single tenant-scoped cash-flow
// statement snapshot, never guessed from counterpart descriptions.
export interface AnnualCashFlowMonth {
  readonly month: number;
  readonly cash_delta_local: string;
  readonly opening_entries_local: string;
  readonly opening_local: string;
  readonly closing_local: string;
  readonly operating_local: string;
  readonly investing_local: string;
  readonly financing_local: string;
  readonly net_flow_local: string;
  readonly exchange_effect_local: string;
  readonly other_adjustments_local: string;
  readonly unclassified_local: string;
  readonly expected_closing_local: string;
  readonly difference_local: string;
  readonly unclassified_count: number;
  readonly other_adjustments_count: number;
  readonly is_reconciled: boolean;
  readonly groups: readonly CashFlowGroup[];
}
export interface AnnualCashFlowReport extends CashFlowReport {
  readonly presentation: CashFlowPresentationRevision;
  readonly year: number;
  readonly through_month: number;
  readonly monthly: readonly AnnualCashFlowMonth[];
}
