import { reportUnauthorizedBearer } from '@/shared/auth/sessionInvalidation';
import {
  accountingAccountTreeApiResponseSchema,
  createAccountingAccountDtoSchema,
  singleAccountingAccountApiResponseSchema,
  type CreateAccountingAccountFormValues,
} from '../schemas/accountingAccount.schema';
import type {
  AccountId,
  AccountingAccountResponse,
  CompanyId,
  CreateAccountingAccountPayload,
  UpdateAccountingAccountPayload,
} from '../types/accountingAccount.types';
import type {
  CashFlowAreaItem,
  CostCenterItem,
  CurrencyItem,
  FinancialStatementRubricItem,
  SunatCatalogItem,
} from '../types/catalogs.types';
import type {
  CreateJournalEntryPayload,
  JournalEntryResponse,
  NextCorrelativeResponse,
  OperationalAccount,
} from '../types/journalEntry.types';
import type { FiscalPeriodAction, FiscalPeriodSummary, FiscalPeriodTransitionResult } from '../types/fiscalPeriod.types';
import type {
  CompanyOnboardingResult, PcgeTemplateSummary, CreateCompanyOnboardingPayload,
  SunatContributorType, SunatCiiuClass, SunatTaxCode,
} from '@/modules/company/types/companyOnboarding.types';
import type { EntityMaster, EntityMasterInput, EntityMasterPage } from '@/modules/company/types/entityMaster.types';
import type {
  CompanyExchangeRatesResponse,
  CompanySettingsData,
  SaveCompanyExchangeRatesPayload,
  UpdateCompanySettingsPayload,
} from '@/modules/company/types/companySettings.types';

/**
 * Proveedor reactivo/dinámico de credenciales y contexto de empresa activa para el cliente HTTP.
 */
export interface HttpAuthContextProvider {
  readonly getAccessToken: () => string | null;
  readonly getActiveCompanyId: () => CompanyId | null;
  readonly onUnauthorized?: () => void;
}

/**
 * Error estructurado de API que conserva los errores de validación 422 de Laravel.
 */
export class ApiHttpError extends Error {
  public constructor(
    public readonly status: number,
    message: string,
    public readonly validationErrors: Readonly<Record<string, readonly string[]>> = {},
  ) {
    super(message);
    this.name = 'ApiHttpError';
  }
}

/**
 * Cliente HTTP tipado sin dependencias pesadas basado en `fetch` estándar,
 * con inyección automática del token Sanctum (`Authorization: Bearer ...`)
 * y del encabezado multi-tenant (`X-Company-Id`).
 */
export class AccountingApiClient {
  private readonly baseUrl: string;

  public constructor(
    baseUrl: string,
    private readonly authContext: HttpAuthContextProvider,
  ) {
    this.baseUrl = baseUrl.replace(/\/+$/, '');
  }

  /**
   * Construye los encabezados por petición inyectando `Authorization` y `X-Company-Id`.
   */
  private buildHeaders(requireTenantHeader: boolean, requestToken: string | null): Headers {
    const headers = new Headers({
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    });

    if (requestToken !== null && requestToken.trim() !== '') {
      headers.set('Authorization', `Bearer ${requestToken}`);
    }

    const activeCompanyId = this.authContext.getActiveCompanyId();
    if (activeCompanyId !== null) {
      headers.set('X-Company-Id', String(activeCompanyId));
    } else if (requireTenantHeader) {
      throw new ApiHttpError(
        400,
        'Operación bloqueada en cliente: No hay una empresa activa seleccionada (X-Company-Id).',
      );
    }

    return headers;
  }

  private async request<TResponse>(
    path: string,
    init: RequestInit,
    requireTenantHeader = true,
  ): Promise<TResponse> {
    // Captura el Bearer realmente enviado; evita invalidar sesiones nuevas
    // por respuestas 401 de solicitudes anteriores.
    const requestToken = this.authContext.getAccessToken();
    const headers = this.buildHeaders(requireTenantHeader, requestToken);
    // The caller can attach an Idempotency-Key. Never allow RequestInit to
    // override the authenticated Bearer or active-company tenant header.
    const idempotencyKey = new Headers(init.headers).get('Idempotency-Key');
    if (idempotencyKey) headers.set('Idempotency-Key', idempotencyKey);
    const response = await fetch(`${this.baseUrl}${path}`, {
      ...init,
      headers,
    });

    if (response.status === 401 && requestToken?.trim()) {
      reportUnauthorizedBearer(requestToken);
      this.authContext.onUnauthorized?.();
    }

    const rawBody: unknown = await response.json().catch(() => null);

    if (!response.ok) {
      const errorObj =
        typeof rawBody === 'object' && rawBody !== null
          ? (rawBody as Record<string, unknown>)
          : {};
      const message =
        typeof errorObj.message === 'string'
          ? errorObj.message
          : `Error HTTP ${response.status} al comunicarse con el servidor contable.`;
      const validationErrors =
        typeof errorObj.errors === 'object' && errorObj.errors !== null
          ? (errorObj.errors as Record<string, readonly string[]>)
          : {};

      throw new ApiHttpError(response.status, message, validationErrors);
    }

    return rawBody as TResponse;
  }

  /**
   * Obtiene el árbol jerárquico del Plan de Cuentas y valida el contrato en tiempo de ejecución con Zod.
   */
  public async fetchAccountTree(params?: {
    readonly onlyActive?: boolean;
    readonly codePrefix?: string;
  }): Promise<readonly AccountingAccountResponse[]> {
    const searchParams = new URLSearchParams();
    if (params?.onlyActive !== undefined) {
      searchParams.set('only_active', params.onlyActive ? '1' : '0');
    }
    if (params?.codePrefix !== undefined && params.codePrefix.trim() !== '') {
      searchParams.set('code_prefix', params.codePrefix.trim());
    }

    const queryString = searchParams.toString();
    const url = `/api/v1/accounting/accounts/tree${queryString ? `?${queryString}` : ''}`;

    const rawPayload = await this.request<unknown>(url, { method: 'GET' });
    const parsed = accountingAccountTreeApiResponseSchema.parse(rawPayload);

    return parsed.data;
  }

  /**
   * Valida el formulario con Zod (incluyendo reglas cruzadas `superRefine`),
   * envía el DTO limpio al backend y valida la respuesta con `singleAccountingAccountApiResponseSchema`.
   */
  public async createAccount(
    formValues: CreateAccountingAccountPayload | CreateAccountingAccountFormValues,
  ): Promise<AccountingAccountResponse> {
    const validatedDto = createAccountingAccountDtoSchema.parse({
      is_active: true,
      ...formValues,
      parent_id: formValues.parent_id === null ? null : Number(formValues.parent_id),
    });

    const rawPayload = await this.request<unknown>('/api/v1/accounting/accounts', {
      method: 'POST',
      body: JSON.stringify(validatedDto),
    });

    return singleAccountingAccountApiResponseSchema.parse(rawPayload).data;
  }

  /**
   * Actualiza la configuración operativa de una cuenta (`PUT /api/v1/accounting/accounts/{id}`).
   * El código, nivel, naturaleza y padre son inmutables y no se envían.
   */
  public async updateAccount(
    accountId: AccountId,
    payload: UpdateAccountingAccountPayload,
  ): Promise<AccountingAccountResponse> {
    const rawPayload = await this.request<unknown>(`/api/v1/accounting/accounts/${accountId}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });

    return singleAccountingAccountApiResponseSchema.parse(rawPayload).data;
  }

  /**
   * Desactiva lógicamente una cuenta contable (`PATCH /api/v1/accounting/accounts/{id}/deactivate`).
   */
  public async deactivateAccount(accountId: AccountId): Promise<AccountingAccountResponse> {
    const rawPayload = await this.request<unknown>(
      `/api/v1/accounting/accounts/${accountId}/deactivate`,
      {
        method: 'PATCH',
      },
    );

    return singleAccountingAccountApiResponseSchema.parse(rawPayload).data;
  }

  /**
   * Catálogos oficiales de monedas disponibles (PEN, USD, EUR).
   */
  public async fetchCurrencies(): Promise<readonly CurrencyItem[]> {
    const rawPayload = await this.request<{ data: CurrencyItem[] }>(
      '/api/v1/catalogs/currencies',
      { method: 'GET' },
      false,
    );
    return rawPayload.data;
  }

  /**
   * Catálogo oficial de Rubros de Estados Financieros (SMV / NIIF 18 / PCGE 2026).
   */
  public async fetchFinancialStatementRubrics(params?: {
    readonly statementType?: string;
  }): Promise<readonly FinancialStatementRubricItem[]> {
    const searchParams = new URLSearchParams();
    if (params?.statementType) {
      searchParams.set('statement_type', params.statementType);
    }
    const query = searchParams.toString();
    const rawPayload = await this.request<{ data: FinancialStatementRubricItem[] }>(
      `/api/v1/catalogs/financial-statement-rubrics${query ? `?${query}` : ''}`,
      { method: 'GET' },
      false,
    );
    return rawPayload.data;
  }

  /**
   * Centros de costos con asignación de cuentas de amarre (94 / 95 y 79).
   */
  public async fetchCostCenters(): Promise<readonly CostCenterItem[]> {
    const rawPayload = await this.request<{ data: CostCenterItem[] }>(
      '/api/v1/company/cost-centers',
      { method: 'GET' },
      true,
    );
    return rawPayload.data;
  }

  /**
   * Áreas de flujo de efectivo por categoría de actividad (NIC 7).
   */
  public async fetchCashFlowAreas(params?: {
    readonly category?: string;
  }): Promise<readonly CashFlowAreaItem[]> {
    const searchParams = new URLSearchParams();
    if (params?.category) {
      searchParams.set('activity_category', params.category);
    }
    const query = searchParams.toString();
    const rawPayload = await this.request<{ data: CashFlowAreaItem[] }>(
      `/api/v1/company/cash-flow-areas${query ? `?${query}` : ''}`,
      { method: 'GET' },
      true,
    );
    return rawPayload.data;
  }

  /**
   * Catálogos y tablas de referencia de SUNAT (ej. tabla 02 comprobantes, 03 bancos, etc.).
   */
  public async fetchSunatTable(
    tableNumber: number | string,
  ): Promise<readonly SunatCatalogItem[]> {
    const num = String(tableNumber).padStart(2, '0');
    const rawPayload = await this.request<{ data: SunatCatalogItem[] }>(
      `/api/v1/catalogs/sunat/${num}`,
      { method: 'GET' },
      false,
    );
    return rawPayload.data;
  }

  /**
   * Catálogo plano de cuentas operativas para digitación en asientos contables.
   */
  public async fetchOperationalAccounts(): Promise<readonly OperationalAccount[]> {
    const rawPayload = await this.request<{ data: OperationalAccount[] }>(
      '/api/v1/accounting/accounts/operational',
      { method: 'GET' },
      true,
    );
    return rawPayload.data;
  }

  /**
   * Precalcula el siguiente correlativo contable para una fecha dada.
   */
  public async fetchNextJournalEntryNumber(date: string): Promise<NextCorrelativeResponse> {
    const rawPayload = await this.request<{ data: NextCorrelativeResponse }>(
      `/api/v1/accounting/journal-entries/next-number?date=${encodeURIComponent(date)}`,
      { method: 'GET' },
      true,
    );
    return rawPayload.data;
  }

  /**
   * Lista los asientos contables registrados en el libro diario.
   */
  public async fetchJournalEntries(params?: {
    readonly year?: number;
    readonly month?: number;
    readonly limit?: number;
  }): Promise<readonly JournalEntryResponse[]> {
    const searchParams = new URLSearchParams();
    if (params?.year) searchParams.set('year', String(params.year));
    if (params?.month) searchParams.set('month', String(params.month));
    if (params?.limit) searchParams.set('limit', String(params.limit));

    const query = searchParams.toString();
    const rawPayload = await this.request<{ data: JournalEntryResponse[] }>(
      `/api/v1/accounting/journal-entries${query ? `?${query}` : ''}`,
      { method: 'GET' },
      true,
    );
    return rawPayload.data;
  }

  /**
   * Registra y asienta un asiento contable validando partida doble bimoneda.
   */
  public async createJournalEntry(
    payload: CreateJournalEntryPayload,
    idempotencyKey: string = crypto.randomUUID(),
  ): Promise<JournalEntryResponse> {
    const rawPayload = await this.request<{ data: JournalEntryResponse }>(
      '/api/v1/accounting/journal-entries',
      {
        method: 'POST',
        headers: { 'Idempotency-Key': idempotencyKey },
        body: JSON.stringify(payload),
      },
      true,
    );
    return rawPayload.data;
  }

  /**
   * Lectura de períodos existentes en empresa activa.
   */
  public async fetchFiscalPeriods(year: number): Promise<readonly FiscalPeriodSummary[]> {
    const response = await this.request<{ data: FiscalPeriodSummary[] }>(
      `/api/v1/accounting/fiscal-periods?year=${encodeURIComponent(String(year))}`,
      { method: 'GET' },
      true,
    );
    return response.data;
  }

  /** Creates exactly one OPEN month within the selected company. */
  public async openFiscalPeriod(
    year: number,
    month: number,
    reason: string,
  ): Promise<FiscalPeriodTransitionResult> {
    return await this.request<FiscalPeriodTransitionResult>(
      '/api/v1/accounting/fiscal-periods',
      {
        method: 'POST',
        body: JSON.stringify({ year, month, reason }),
      },
      true,
    );
  }

  public async changeFiscalPeriodStatus(
    periodId: number,
    action: FiscalPeriodAction,
    reason: string,
  ): Promise<FiscalPeriodTransitionResult> {
    return await this.request<FiscalPeriodTransitionResult>(
      `/api/v1/accounting/fiscal-periods/${encodeURIComponent(String(periodId))}/${action}`,
      {
        method: 'POST',
        body: JSON.stringify({ reason }),
      },
      true,
    );
  }

  /**
   * Alta de empresas (no requiere un tenant NUEVO; sí exige Sanctum).
   * El servidor comprobará rol y migraciones pendientes antes de admitir la creación.
   */
  /** RUC Annex 2, independent of SUNAT PLE table 02. */
  public async fetchSunatContributorTypes(): Promise<readonly SunatContributorType[]> {
    const response = await this.request<{data: SunatContributorType[]}>(
      '/api/v1/catalogs/sunat-ruc/contributor-types', {method:'GET'}, false,
    );
    return response.data;
  }

  /** INEI/SUNAT CIIU Revision 4 snapshot, full official descriptions. */
  public async fetchSunatCiiuRev4(): Promise<readonly SunatCiiuClass[]> {
    const response = await this.request<{data: SunatCiiuClass[]}>(
      '/api/v1/catalogs/sunat-ruc/ciiu', {method:'GET'}, false,
    );
    return response.data;
  }

  /** RUC Annex 10 tax codes, not PLE electronic-document table 10. */
  public async fetchSunatRucTaxCodes(): Promise<readonly SunatTaxCode[]> {
    const response = await this.request<{data: SunatTaxCode[]}>(
      '/api/v1/catalogs/sunat-ruc/tax-codes', {method:'GET'}, false,
    );
    return response.data;
  }

  public async fetchCompanyOnboardingCapability(): Promise<{ can_create: boolean; reason: string | null }> {
    const response = await this.request<{
      data: { can_create: boolean; reason: string | null };
    }>('/api/v1/tenant/company-onboarding/capabilities', { method: 'GET' }, false);
    return response.data;
  }

  public async fetchPcgeTemplateSummaries(): Promise<readonly PcgeTemplateSummary[]> {
    const response = await this.request<{ data: PcgeTemplateSummary[] }>(
      '/api/v1/catalogs/chart-of-accounts-templates',
      { method: 'GET' }, false,
    );
    return response.data;
  }

  public async createCompanyFromWizard(
    payload: CreateCompanyOnboardingPayload,
  ): Promise<CompanyOnboardingResult> {
    const response = await this.request<{ data: CompanyOnboardingResult }>(
      '/api/v1/tenant/companies', {
        method: 'POST', body: JSON.stringify(payload),
      }, false,
    );
    return response.data;
  }

  /** Master entities: always scoped by Bearer and active company, never by a client-supplied tenant. */
  public async fetchEntities(params: {
    readonly search?: string;
    readonly role?: 'all' | 'is_client' | 'is_supplier' | 'is_employee';
    readonly page?: number;
  } = {}): Promise<EntityMasterPage> {
    const query = new URLSearchParams();
    if (params.search?.trim()) query.set('search', params.search.trim());
    if (params.role && params.role !== 'all') query.set(params.role, '1');
    query.set('per_page', '20');
    if (params.page && params.page > 1) query.set('page', String(params.page));
    return await this.request<EntityMasterPage>(
      '/api/v1/company/entities?' + query.toString(), { method: 'GET' }, true,
    );
  }

  public async createEntity(payload: EntityMasterInput): Promise<EntityMaster> {
    const response = await this.request<{ data: EntityMaster }>(
      '/api/v1/company/entities', { method: 'POST', body: JSON.stringify(payload) }, true,
    );
    return response.data;
  }

  public async updateEntity(id: number, payload: EntityMasterInput): Promise<EntityMaster> {
    const response = await this.request<{ data: EntityMaster }>(
      '/api/v1/company/entities/' + encodeURIComponent(String(id)),
      { method: 'PUT', body: JSON.stringify(payload) }, true,
    );
    return response.data;
  }

  public async deactivateEntity(id: number): Promise<EntityMaster> {
    const response = await this.request<{ data: EntityMaster }>(
      '/api/v1/company/entities/' + encodeURIComponent(String(id)) + '/deactivate',
      { method: 'PATCH' }, true,
    );
    return response.data;
  }

  /**
   * Obtiene la configuración general, perfil y régimen de divisas de la empresa activa.
   */
  public async fetchCompanySettings(): Promise<CompanySettingsData> {
    const rawPayload = await this.request<{ data: CompanySettingsData }>(
      '/api/v1/company/settings',
      { method: 'GET' },
      true,
    );
    return rawPayload.data;
  }

  /**
   * Actualiza el perfil de la empresa, su régimen de divisas (base y secundarias) y parámetros tributarios.
   */
  public async updateCompanySettings(
    payload: UpdateCompanySettingsPayload,
  ): Promise<{ message: string; data: CompanySettingsData }> {
    return await this.request<{ message: string; data: CompanySettingsData }>(
      '/api/v1/company/settings',
      {
        method: 'PUT',
        body: JSON.stringify(payload),
      },
      true,
    );
  }

  /**
   * Obtiene los tipos de cambio de las monedas secundarias configuradas para una fecha contable.
   */
  public async fetchCompanyExchangeRates(date: string): Promise<CompanyExchangeRatesResponse> {
    const rawPayload = await this.request<{ data: CompanyExchangeRatesResponse }>(
      `/api/v1/company/exchange-rates?date=${encodeURIComponent(date)}`,
      { method: 'GET' },
      true,
    );
    return rawPayload.data;
  }

  /**
   * Registra o actualiza tipos de cambio diarios para la empresa activa.
   */
  public async saveCompanyExchangeRates(
    payload: SaveCompanyExchangeRatesPayload,
  ): Promise<{ message: string; data: unknown }> {
    return await this.request<{ message: string; data: unknown }>(
      '/api/v1/company/exchange-rates',
      {
        method: 'POST',
        body: JSON.stringify(payload),
      },
      true,
    );
  }
}
