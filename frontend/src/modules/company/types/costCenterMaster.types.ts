export interface CostCenterAccount {
  readonly id: number;
  readonly code: string;
  readonly name: string;
}

export interface CostCenterMaster {
  readonly id: number;
  readonly code: string;
  readonly name: string;
  readonly is_active: boolean;
  readonly destination_debit_account_id: number;
  readonly destination_credit_account_id: number;
  readonly destination_debit_account: CostCenterAccount | null;
  readonly destination_credit_account: CostCenterAccount | null;
}

export interface CostCenterInput {
  readonly code: string;
  readonly name: string;
  readonly destination_debit_account_id: number;
  readonly destination_credit_account_id: number;
}

export type CostCenterStatus = 'active' | 'inactive' | 'all';

export interface CostCenterPage {
  readonly data: readonly CostCenterMaster[];
  readonly meta: {
    readonly current_page: number;
    readonly last_page: number;
    readonly per_page: number;
    readonly total: number;
  };
}
