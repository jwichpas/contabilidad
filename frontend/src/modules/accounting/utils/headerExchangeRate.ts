import type { CompanyExchangeRatesResponse, RequiredExchangeRateItem } from '../../company/types/companySettings.types';

/** No mostrar valores sugeridos, de otra fecha o de otra moneda base como tasa registrada. */
export function selectRecordedUsdPenRate(
  response: CompanyExchangeRatesResponse | null | undefined,
  expectedDate: string,
): RequiredExchangeRateItem | null {
  if (!response || response.rate_date !== expectedDate || response.base_currency.code !== 'PEN') {
    return null;
  }
  return response.required_currencies.find(rate => rate.code === 'USD' && rate.is_recorded_for_date) ?? null;
}
