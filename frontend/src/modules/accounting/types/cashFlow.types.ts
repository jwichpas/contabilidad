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
