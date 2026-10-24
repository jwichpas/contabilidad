/** Reset all account-dependent dimensions after switching the account of a journal line.
 * Preserve the description and monetary amounts: a changed account must not silently alter them. */
export interface JournalAccountDimensions {
  costCenterId: number | null;
  companyAreaId: number | null;
  cashFlowAreaId: number | null;
  entity: unknown | null;
  showOptionalCostCenter?: boolean;
  showOptionalEntity?: boolean;
}

export function resetAccountDimensions(line: JournalAccountDimensions): void {
  line.costCenterId = null;
  line.companyAreaId = null;
  line.cashFlowAreaId = null;
  line.entity = null;
  // Closed optional pickers belong to the previous account configuration.
  line.showOptionalCostCenter = false;
  line.showOptionalEntity = false;
}
