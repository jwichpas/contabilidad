import { computed, onScopeDispose, readonly, shallowRef, watch } from 'vue';
import { AccountingApiClient } from '../api/httpClient';
import type { CompanyId } from '../types/accountingAccount.types';
import type { CashFlowQuery, CashFlowReport } from '../types/cashFlow.types';

/** Owns the request lifetime; changing tenant immediately clears previous data. */
export function useCashFlowReport(companyId: () => CompanyId, getAccessToken: () => string | null) {
  const report = shallowRef<CashFlowReport | null>(null);
  const pending = shallowRef(false);
  const error = shallowRef<string | null>(null);
  const client = computed(() => new AccountingApiClient('', { getAccessToken, getActiveCompanyId: companyId }));
  let version = 0;

  function reset(): void {
    version++;
    report.value = null;
    error.value = null;
    pending.value = false;
  }

  async function load(query: CashFlowQuery): Promise<void> {
    const request = ++version;
    const tenant = companyId();
    report.value = null;
    pending.value = true;
    error.value = null;
    try {
      const result = await client.value.fetchCashFlowReport(query);
      if (request === version && tenant === companyId()) report.value = result;
    } catch (cause) {
      if (request === version && tenant === companyId()) {
        error.value = cause instanceof Error ? cause.message : 'No se pudo consultar el flujo de efectivo.';
      }
    } finally {
      if (request === version) pending.value = false;
    }
  }

  watch(companyId, reset, { flush: 'sync' });
  onScopeDispose(reset);
  return { report: readonly(report), pending: readonly(pending), error: readonly(error), load };
}
