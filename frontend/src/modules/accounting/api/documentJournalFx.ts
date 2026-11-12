import type { CompanyExchangeRatesResponse } from '@/modules/company/types/companySettings.types';

/**
 * Posting in the bimoneda journal needs a stored DAILY rate for every enabled
 * foreign currency even if the document itself is PEN.
 * The UI must never accept historical/default API suggestions as recorded FX.
 */
export function isDocumentFxReady(
  rates: CompanyExchangeRatesResponse | null,
  postingDate: string,
): boolean {
  return rates !== null && rates.rate_date === postingDate && rates.base_currency.code === 'PEN' &&
    rates.required_currencies.every(currency =>
      currency.is_recorded_for_date === true &&
      Number.isFinite(Number(currency.buy_rate)) && Number(currency.buy_rate) > 0 &&
      Number.isFinite(Number(currency.sell_rate)) && Number(currency.sell_rate) > 0
    );
}


/**
 * Only a successful, tenant-scoped read that specifically reports USD
 * not recorded for the requested date authorizes the fallback action.
 * A network/database GET error must NOT be interpreted as a missing rate.
 */
export function shouldOfferSunatFxImport(
  rates: CompanyExchangeRatesResponse | null,
  postingDate: string,
  currencies: readonly string[],
  lookupFailed = false,
): boolean {
  return !lookupFailed && rates !== null && rates.rate_date === postingDate
    && rates.base_currency.code === 'PEN' && currencies.includes('USD')
    && rates.required_currencies.some(rate => rate.code === 'USD' && !rate.is_recorded_for_date);
}
