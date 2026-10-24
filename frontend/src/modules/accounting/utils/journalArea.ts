export interface CashFlowRule {
  readonly requires_cash_flow_area: boolean;
  readonly cash_flow_category: string;
}
export interface CashFlowChoice {
  readonly is_active: boolean;
  readonly activity_category: string;
}
export function cashFlowMatches(account: CashFlowRule | undefined, area: CashFlowChoice | null): boolean {
  if (!account) return false;
  const category = account.cash_flow_category;
  if (!['NONE', 'CASH_EQUIVALENT', 'OPERATING', 'INVESTING', 'FINANCING'].includes(category)) return false;
  if (!area) return !account.requires_cash_flow_area;
  if (!area.is_active || !['CASH_EQUIVALENT', 'OPERATING', 'INVESTING', 'FINANCING'].includes(area.activity_category)) return false;
  return category === 'NONE' || category === 'CASH_EQUIVALENT' || area.activity_category === category;
}
