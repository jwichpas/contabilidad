import {
  Int16Type,
  Int64Type,
  type Decimal14_4String,
  type Decimal14_6String,
  type Int16,
  type Int64,
  type NumericBrand,
} from '@/shared/types/numeric.types';

/**
 * Identificadores nominales estrictos construidos sobre `Int64` (`long` en C#) e `Int16` (`short` en C#).
 * Impide mezclar un `AccountId` con un `CompanyId` o con un `number` sin validar.
 */
export type AccountId = NumericBrand<Int64, 'AccountId'>;
export type CompanyId = NumericBrand<Int64, 'CompanyId'>;
export type AccountUuid = NumericBrand<string, 'AccountUuid'>;
export type IsoDateTimeString = NumericBrand<string, 'IsoDateTimeString'>;

export type MonetaryAmountString = Decimal14_4String;
export type ExchangeRateString = Decimal14_6String;

/**
 * Constructores tipados con validación en tiempo de ejecución al estilo `AccountId.Parse(long)` de C#.
 */
export const asAccountId = (id: number | string): AccountId =>
  Int64Type.parsePositive(id) as AccountId;

export const asCompanyId = (id: number | string): CompanyId =>
  Int64Type.parsePositive(id) as CompanyId;

export const ACCOUNT_LEVELS = [1, 2, 3, 4, 5, 6] as const;
export type AccountLevel = (typeof ACCOUNT_LEVELS)[number] & Int16;

export const asAccountLevel = (level: number | string): AccountLevel => {
  const shortVal = Int16Type.parse(level);
  if (shortVal < 1 || shortVal > 6) {
    throw new RangeError(`AccountLevel inválido [${shortVal}]: debe estar entre 1 y 6.`);
  }
  return shortVal as AccountLevel;
};

/**
 * 1. Naturaleza Contable (`AccountType`) y Tipo de Saldo (`BalanceType`)
 */
export const ACCOUNT_TYPES = [
  'ASSET',
  'LIABILITY',
  'EQUITY',
  'REVENUE',
  'EXPENSE',
  'COST',
  'MEMORANDUM',
] as const;

export type AccountType = (typeof ACCOUNT_TYPES)[number];

export const ACCOUNT_TYPE_LABELS: Readonly<Record<AccountType, string>> = {
  ASSET: 'Activo',
  LIABILITY: 'Pasivo',
  EQUITY: 'Patrimonio',
  REVENUE: 'Ingresos',
  EXPENSE: 'Gastos',
  COST: 'Costos / Analítica (Elem. 9)',
  MEMORANDUM: 'Cuentas de Orden',
};

export const BALANCE_TYPES = ['DEBIT', 'CREDIT'] as const;
export type BalanceType = (typeof BALANCE_TYPES)[number];

export const BALANCE_TYPE_LABELS: Readonly<Record<BalanceType, string>> = {
  DEBIT: 'Deudor',
  CREDIT: 'Acreedor',
};

/**
 * 2. Configuración de Moneda de la Cuenta (`AccountCurrencyMode`)
 */
export const ACCOUNT_CURRENCY_MODES = ['PEN', 'USD', 'EUR', 'MULTI'] as const;
export type AccountCurrencyMode = (typeof ACCOUNT_CURRENCY_MODES)[number];

export const ACCOUNT_CURRENCY_MODE_LABELS: Readonly<Record<AccountCurrencyMode, string>> = {
  PEN: 'Soles (PEN · Moneda Local)',
  USD: 'Dólares (USD · Moneda Extranjera)',
  EUR: 'Euros (EUR · Moneda Extranjera)',
  MULTI: 'Bimoneda / Multimoneda',
};

/**
 * 3. Configuración de Entidad / Anexo exigido (`EntityRequirementType`)
 */
export const ENTITY_REQUIREMENT_TYPES = [
  'NONE',
  'CUSTOMER',
  'SUPPLIER',
  'EMPLOYEE',
  'SHAREHOLDER',
  'FINANCIAL_INSTITUTION',
  'TAX_AUTHORITY',
  'ANY_THIRD_PARTY',
] as const;

export type EntityRequirementType = (typeof ENTITY_REQUIREMENT_TYPES)[number];

export const ENTITY_REQUIREMENT_LABELS: Readonly<Record<EntityRequirementType, string>> = {
  NONE: 'No exige entidad',
  CUSTOMER: 'Cliente (RUC/DNI)',
  SUPPLIER: 'Proveedor (RUC)',
  EMPLOYEE: 'Trabajador / Planilla',
  SHAREHOLDER: 'Accionista / Socio',
  FINANCIAL_INSTITUTION: 'Entidad Financiera / Banco',
  TAX_AUTHORITY: 'SUNAT / AFP / EsSalud',
  ANY_THIRD_PARTY: 'Cualquier Entidad / Tercero',
};

/**
 * 4. Configuración de Área para Flujo de Efectivo (`CashFlowCategory` - Especial Cuenta 10)
 */
export const CASH_FLOW_CATEGORIES = [
  'NONE',
  'CASH_EQUIVALENT',
  'OPERATING',
  'INVESTING',
  'FINANCING',
] as const;

export type CashFlowCategory = (typeof CASH_FLOW_CATEGORIES)[number];

export const CASH_FLOW_CATEGORY_LABELS: Readonly<Record<CashFlowCategory, string>> = {
  NONE: 'No aplica a Flujo de Efectivo',
  CASH_EQUIVALENT: 'Efectivo y Equivalentes de Efectivo (Cta. 10)',
  OPERATING: 'Actividades de Operación',
  INVESTING: 'Actividades de Inversión',
  FINANCING: 'Actividades de Financiamiento',
};

/**
 * 5. Configuración de Rubro para Estados Financieros (`FinancialStatementRubric`)
 * Ejemplo: 1041 = ESF_ACT_CORR_EFE ("Efectivo y Equivalentes de Efectivo")
 */
export const FINANCIAL_STATEMENT_RUBRICS = [
  'NONE',
  'ESF_ACT_CORR_EFE',
  'ESF_ACT_CORR_CXC_COM',
  'ESF_ACT_CORR_OTR_CXC',
  'ESF_ACT_CORR_INV',
  'ESF_ACT_NCORR_IME',
  'ESF_ACT_NCORR_INT',
  'ESF_PAS_CORR_TRIB',
  'ESF_PAS_CORR_REM',
  'ESF_PAS_CORR_CXP_COM',
  'ESF_PAS_OBLIG_FIN',
  'ESF_PAT_CAP',
  'ESF_PAT_RES_ACUM',
  'ER_ING_VENTAS_NETAS',
  'ER_ING_OTROS_OPER',
  'ERN_COMPRAS_MERC',
  'ERN_GASTOS_PERSONAL',
  'ERN_SERV_TERCEROS',
  'ERN_GASTOS_TRIBUTOS',
  'ERN_OTROS_GASTOS_GESTION',
  'ERF_COSTO_VENTAS',
  'ERF_GASTOS_ADMIN_94',
  'ERF_GASTOS_VENTAS_95',
  'CONTROL_CARGAS_IMPUTABLES_79',
] as const;

export type FinancialStatementRubric = (typeof FINANCIAL_STATEMENT_RUBRICS)[number];

export const FINANCIAL_STATEMENT_RUBRIC_LABELS: Readonly<
  Record<FinancialStatementRubric, string>
> = {
  NONE: 'Sin rubro asignado (Cuenta de Agrupación)',
  ESF_ACT_CORR_EFE: 'ESF · Efectivo y Equivalentes de Efectivo',
  ESF_ACT_CORR_CXC_COM: 'ESF · Cuentas por Cobrar Comerciales (Neto)',
  ESF_ACT_CORR_OTR_CXC: 'ESF · Otras Cuentas por Cobrar',
  ESF_ACT_CORR_INV: 'ESF · Inventarios / Existencias',
  ESF_ACT_NCORR_IME: 'ESF · Propiedades, Planta y Equipo (Neto)',
  ESF_ACT_NCORR_INT: 'ESF · Activos Intangibles',
  ESF_PAS_CORR_TRIB: 'ESF · Tributos y Aportes por Pagar',
  ESF_PAS_CORR_REM: 'ESF · Remuneraciones y Participaciones por Pagar',
  ESF_PAS_CORR_CXP_COM: 'ESF · Cuentas por Pagar Comerciales',
  ESF_PAS_OBLIG_FIN: 'ESF · Obligaciones Financieras',
  ESF_PAT_CAP: 'ESF · Capital Social Emitido',
  ESF_PAT_RES_ACUM: 'ESF · Resultados Acumulados',
  ER_ING_VENTAS_NETAS: 'EERR · Ventas Netas de Bienes y Servicios',
  ER_ING_OTROS_OPER: 'EERR · Otros Ingresos de Gestión',
  ERN_COMPRAS_MERC: 'EERR (Naturaleza) · Compras de Mercaderías',
  ERN_GASTOS_PERSONAL: 'EERR (Naturaleza) · Gastos de Personal y Directores',
  ERN_SERV_TERCEROS: 'EERR (Naturaleza) · Servicios Prestados por Terceros',
  ERN_GASTOS_TRIBUTOS: 'EERR (Naturaleza) · Tributos a Cargo de la Empresa',
  ERN_OTROS_GASTOS_GESTION: 'EERR (Naturaleza) · Otros Gastos de Gestión',
  ERF_COSTO_VENTAS: 'EERR (Función) · Costo de Ventas',
  ERF_GASTOS_ADMIN_94: 'EERR (Función) · Gastos de Administración (Cta. 94)',
  ERF_GASTOS_VENTAS_95: 'EERR (Función) · Gastos de Ventas y Distribución (Cta. 95)',
  CONTROL_CARGAS_IMPUTABLES_79: 'Puente Analítico · Cargas Imputables a Costos y Gastos (Cta. 79)',
};

/**
 * Contrato inmutable de respuesta de cuenta contable con tipado numérico C# y configuraciones completas.
 */
export interface AccountingAccountResponse {
  readonly id: AccountId;
  readonly uuid: AccountUuid;
  readonly company_id: CompanyId;
  readonly parent_id: AccountId | null;
  readonly code: string;
  readonly name: string;
  readonly level: AccountLevel;
  readonly path: string;
  readonly account_type: AccountType;
  readonly account_type_label: string;
  readonly balance_type: BalanceType;
  readonly balance_type_label: string;
  readonly currency_mode: AccountCurrencyMode;
  readonly currency_mode_label: string;
  readonly requires_currency: boolean;
  readonly requires_auxiliary: boolean;
  readonly entity_requirement_type: EntityRequirementType;
  readonly entity_requirement_label: string;
  readonly requires_cost_center: boolean;
  readonly has_automatic_destination: boolean;
  readonly destination_debit_code: string | null;
  readonly destination_credit_code: string | null;
  readonly requires_cash_flow_area: boolean;
  readonly cash_flow_category: CashFlowCategory;
  readonly cash_flow_category_label: string;
  readonly financial_statement_rubric: FinancialStatementRubric;
  readonly financial_statement_rubric_label: string;
  readonly is_operational: boolean;
  readonly is_active: boolean;
  readonly created_at: IsoDateTimeString;
  readonly updated_at: IsoDateTimeString;
  readonly children: readonly AccountingAccountResponse[];
}

export interface FlattenedAccountRow {
  readonly node: AccountingAccountResponse;
  readonly account: AccountingAccountResponse;
  readonly hasChildren: boolean;
  readonly isExpanded: boolean;
}

export interface CreateAccountingAccountPayload {
  readonly parent_id: AccountId | number | null;
  readonly code: string;
  readonly name: string;
  readonly level: AccountLevel;
  readonly account_type: AccountType;
  readonly balance_type: BalanceType;
  readonly currency_mode: AccountCurrencyMode;
  readonly requires_currency: boolean;
  readonly requires_auxiliary: boolean;
  readonly entity_requirement_type: EntityRequirementType;
  readonly requires_cost_center: boolean;
  readonly has_automatic_destination: boolean;
  readonly destination_debit_code: string | null;
  readonly destination_credit_code: string | null;
  readonly requires_cash_flow_area: boolean;
  readonly cash_flow_category: CashFlowCategory;
  readonly financial_statement_rubric: FinancialStatementRubric;
  readonly is_operational: boolean;
  readonly is_active?: boolean;
}

export interface UpdateAccountingAccountPayload {
  readonly name: string;
  readonly currency_mode: AccountCurrencyMode;
  readonly requires_currency: boolean;
  readonly requires_auxiliary: boolean;
  readonly entity_requirement_type: EntityRequirementType;
  readonly requires_cost_center: boolean;
  readonly has_automatic_destination: boolean;
  readonly destination_debit_code: string | null;
  readonly destination_credit_code: string | null;
  readonly requires_cash_flow_area: boolean;
  readonly cash_flow_category: CashFlowCategory;
  readonly financial_statement_rubric: FinancialStatementRubric;
  readonly is_operational: boolean;
}

