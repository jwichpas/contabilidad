import { z } from 'zod';
import {
  ACCOUNT_CURRENCY_MODES,
  ACCOUNT_TYPES,
  BALANCE_TYPES,
  CASH_FLOW_CATEGORIES,
  ENTITY_REQUIREMENT_TYPES,
  FINANCIAL_STATEMENT_RUBRICS,
  asAccountId,
  asAccountLevel,
  asCompanyId,
  type AccountId,
  type AccountLevel,
  type AccountUuid,
  type AccountingAccountResponse,
  type CompanyId,
  type ExchangeRateString,
  type IsoDateTimeString,
  type MonetaryAmountString,
} from '../types/accountingAccount.types';

/**
 * Esquemas primitivos reutilizables con tipado numérico fuerte al estilo C# (`Int64`, `Int16`, `System.Decimal`).
 */
export const accountIdSchema = z
  .number({
    required_error: 'El identificador de la cuenta (Int64) es obligatorio.',
    invalid_type_error: 'El identificador de la cuenta debe ser un entero Int64.',
  })
  .int('El identificador de la cuenta debe ser un número entero.')
  .positive('El identificador de la cuenta debe ser un entero positivo.')
  .transform((val): AccountId => asAccountId(val));

export const companyIdSchema = z
  .number({
    required_error: 'El identificador de la empresa (Int64) es obligatorio.',
    invalid_type_error: 'El identificador de la empresa debe ser un entero Int64.',
  })
  .int('El identificador de la empresa debe ser un número entero.')
  .positive('El identificador de la empresa debe ser un entero positivo.')
  .transform((val): CompanyId => asCompanyId(val));

export const monetaryAmountStringSchema = z
  .string({
    required_error: 'El importe monetario Decimal(14,4) es obligatorio.',
    invalid_type_error: 'El importe monetario debe enviarse como representación Decimal exacta.',
  })
  .trim()
  .regex(
    /^-?\d{1,10}(?:\.\d{1,4})?$/,
    'El importe debe tener hasta 10 dígitos enteros y un máximo de 4 decimales (NUMERIC(14,4)).',
  )
  .transform((val): MonetaryAmountString => val as MonetaryAmountString);

export const exchangeRateStringSchema = z
  .string({
    required_error: 'El tipo de cambio Decimal(14,6) es obligatorio.',
    invalid_type_error: 'El tipo de cambio debe enviarse como representación Decimal exacta.',
  })
  .trim()
  .regex(
    /^\d{1,8}(?:\.\d{1,6})?$/,
    'El tipo de cambio debe ser positivo con hasta 8 enteros y 6 decimales (NUMERIC(14,6)).',
  )
  .transform((val): ExchangeRateString => val as ExchangeRateString);

export const accountLevelSchema = z
  .union(
    [
      z.literal(1),
      z.literal(2),
      z.literal(3),
      z.literal(4),
      z.literal(5),
      z.literal(6),
    ],
    {
      errorMap: () => ({
        message:
          'El nivel contable (Int16) debe estar en el rango de 1 a 6.',
      }),
    },
  )
  .transform((val): AccountLevel => asAccountLevel(val));

export const accountTypeSchema = z.enum(ACCOUNT_TYPES, {
  errorMap: () => ({
    message:
      'Seleccione una naturaleza de cuenta contable válida (Activo, Pasivo, Patrimonio, Ingresos, Gastos, Costos o Cuentas de Orden).',
  }),
});

export const balanceTypeSchema = z.enum(BALANCE_TYPES, {
  errorMap: () => ({
    message: 'Seleccione un tipo de saldo válido (Deudor o Acreedor).',
  }),
});

export const accountCurrencyModeSchema = z.enum(ACCOUNT_CURRENCY_MODES, {
  errorMap: () => ({
    message: 'Seleccione una moneda de configuración válida (PEN, USD, EUR o MULTI).',
  }),
});

export const entityRequirementTypeSchema = z.enum(ENTITY_REQUIREMENT_TYPES, {
  errorMap: () => ({
    message: 'Seleccione el tipo de Entidad/Tercero exigido por la cuenta.',
  }),
});

export const cashFlowCategorySchema = z.enum(CASH_FLOW_CATEGORIES, {
  errorMap: () => ({
    message: 'Seleccione la categoría de Área de Flujo de Efectivo (NIC 7).',
  }),
});

export const financialStatementRubricSchema = z.enum(FINANCIAL_STATEMENT_RUBRICS, {
  errorMap: () => ({
    message: 'Seleccione un rubro válido de presentación en Estados Financieros.',
  }),
});

/**
 * Esquema Zod `accountingAccountSchema` con reglas cruzadas `superRefine` para:
 * - Moneda (`currency_mode`: PEN, USD, EUR, MULTI)
 * - Entidad (`entity_requirement_type`)
 * - Centro de Costos y Destino Automático a Cuentas 94/95 con contrapartida 79
 * - Área de Flujo de Efectivo para la Cuenta 10
 * - Rubro para Estados Financieros (ej. 1041 = Efectivo y Equivalentes de Efectivo)
 */
export const accountingAccountSchema = z
  .object({
    parent_id: accountIdSchema.nullable().optional().default(null),
    parent_code: z.string().trim().optional().nullable(),
    parent_level: z.number().int().min(1).max(5).optional().nullable(),
    parent_is_operational: z.boolean().optional().nullable(),
    code: z
      .string({
        required_error: 'El código contable es obligatorio.',
        invalid_type_error: 'El código contable debe ser una cadena de texto.',
      })
      .trim()
      .min(1, 'El código contable debe contener al menos 1 carácter.')
      .max(20, 'El código contable no puede exceder los 20 caracteres.')
      .regex(
        /^[A-Za-z0-9]+$/,
        'El código contable solo admite caracteres alfanuméricos sin espacios ni símbolos.',
      )
      .transform((val) => val.toUpperCase()),
    name: z
      .string({
        required_error: 'La denominación de la cuenta contable es obligatoria.',
        invalid_type_error: 'La denominación de la cuenta debe ser texto.',
      })
      .trim()
      .min(2, 'La denominación de la cuenta debe tener al menos 2 caracteres.')
      .max(180, 'La denominación de la cuenta no puede superar los 180 caracteres.'),
    level: accountLevelSchema,
    account_type: accountTypeSchema,
    balance_type: balanceTypeSchema,
    currency_mode: accountCurrencyModeSchema.default('PEN'),
    requires_currency: z.boolean({
      required_error: 'Debe indicar si la cuenta exige moneda extranjera.',
      invalid_type_error: 'El indicador de moneda extranjera debe ser booleano.',
    }),
    requires_auxiliary: z.boolean({
      required_error: 'Debe indicar si la cuenta exige entidad/anexo.',
      invalid_type_error: 'El indicador de entidad debe ser booleano.',
    }),
    entity_requirement_type: entityRequirementTypeSchema.default('NONE'),
    requires_cost_center: z.boolean().default(false),
    requires_company_area: z.boolean().default(false),
    has_automatic_destination: z.boolean().default(false),
    destination_debit_code: z.string().trim().nullable().optional().default(null),
    destination_credit_code: z.string().trim().nullable().optional().default(null),
    requires_cash_flow_area: z.boolean().default(false),
    cash_flow_category: cashFlowCategorySchema.default('NONE'),
    financial_statement_rubric: financialStatementRubricSchema.default('NONE'),
    is_operational: z.boolean({
      required_error: 'Debe indicar si la cuenta es operativa (imputable en asientos).',
      invalid_type_error: 'El indicador de cuenta operativa debe ser booleano.',
    }),
    is_active: z
      .boolean({
        invalid_type_error: 'El estado activo de la cuenta debe ser booleano.',
      })
      .default(true),
  })
  .superRefine((data, ctx) => {
    // Regla 1: Nivel 1 (Elemento) no puede tener cuenta padre.
    if (data.level === 1 && data.parent_id !== null) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['parent_id'],
        message:
          'Las cuentas de nivel 1 (Elemento) son cuentas raíz y no deben asociarse a una cuenta padre.',
      });
    }

    // Regla 2: Niveles 2 al 6 requieren obligatoriamente `parent_id`.
    if (data.level > 1 && data.parent_id === null) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['parent_id'],
        message: `Las cuentas de nivel ${data.level} requieren obligatoriamente seleccionar una cuenta padre de nivel ${data.level - 1}.`,
      });
    }

    // Regla 3: `is_operational` solo está permitido a partir del nivel 3 (Subcuentas en adelante).
    if (data.is_operational && data.level < 3) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['is_operational'],
        message:
          'Solo las cuentas a partir del nivel 3 pueden marcarse como operativas para imputación de asientos.',
      });
    }

    // Regla 4: Las cuentas no operativas son de agrupación y no deben exigir entidad ni centro de costos.
    if (!data.is_operational && (data.requires_auxiliary || data.entity_requirement_type !== 'NONE')) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['entity_requirement_type'],
        message: 'Solo las cuentas operativas pueden exigir Entidad / Anexo contable.',
      });
    }

    if (!data.is_operational && data.requires_company_area) {
      ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['requires_company_area'],
        message: 'Solo las cuentas operativas pueden exigir un área organizativa.' });
    }

    if (!data.is_operational && (data.requires_cost_center || data.has_automatic_destination)) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['requires_cost_center'],
        message:
          'Solo las cuentas operativas pueden exigir Centro de Costos o destino automático a 94/95.',
      });
    }

    // Regla 5: Si exige destino automático (amarre Elemento 6 -> Elemento 9: 94/95 contra 79), validar códigos.
    if (data.has_automatic_destination) {
      const debit = (data.destination_debit_code ?? '').trim();
      const credit = (data.destination_credit_code ?? '').trim();

      if (!/^9\d+$/.test(debit)) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ['destination_debit_code'],
          message:
            'Indique la cuenta de destino en el Elemento 9 (ej. 9411 Gastos Administrativos o 9511 Gastos de Ventas).',
        });
      }

      if (!/^7[89]\d*$/.test(credit)) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ['destination_credit_code'],
          message:
            'Indique la cuenta contrapartida de cargas imputables (ej. 7911).',
        });
      }
    }

    // Regla 6: Si exige Área de Flujo de Efectivo (Cuenta 10), debe seleccionar categoría NIC 7.
    if (data.requires_cash_flow_area && data.cash_flow_category === 'NONE') {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['cash_flow_category'],
        message:
          'Seleccione la categoría de Flujo de Efectivo (ej. Efectivo y Equivalentes u Operación).',
      });
    }

    // Regla 7: Toda cuenta operativa debe tener un Rubro de Estado Financiero configurado.
    if (data.is_operational && data.financial_statement_rubric === 'NONE') {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['financial_statement_rubric'],
        message:
          'Las cuentas operativas deben asociarse a un Rubro de Estado Financiero (ej. 1041 = Efectivo y Equivalentes de Efectivo).',
      });
    }

    // Regla 8: Jerarquía respecto a la cuenta padre.
    if (data.parent_is_operational === true) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ['parent_id'],
        message: 'No se pueden crear subcuentas bajo una cuenta padre que ya es operativa.',
      });
    }

    if (data.parent_level !== undefined && data.parent_level !== null) {
      if (data.level !== data.parent_level + 1) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ['level'],
          message: `El nivel de la subcuenta (${data.level}) debe ser exactamente el nivel de la cuenta padre (${data.parent_level}) + 1.`,
        });
      }
    }

    if (data.parent_code !== undefined && data.parent_code !== null && data.parent_code !== '') {
      const normalizedParentCode = data.parent_code.trim().toUpperCase();
      if (
        !data.code.startsWith(normalizedParentCode) ||
        data.code.length <= normalizedParentCode.length
      ) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ['code'],
          message: `El código de la subcuenta (${data.code}) debe comenzar con el código de la cuenta padre (${normalizedParentCode}) y agregar dígitos específicos.`,
        });
      }
    }
  });

export const createAccountingAccountDtoSchema = accountingAccountSchema.transform(
  ({ parent_code: _pc, parent_level: _pl, parent_is_operational: _po, ...payload }) => payload,
);

export type CreateAccountingAccountFormValues = z.input<typeof accountingAccountSchema>;
export type CreateAccountingAccountDTO = z.output<typeof createAccountingAccountDtoSchema>;

/**
 * Esquema recursivo de validación en tiempo de ejecución para un nodo devuelto por la API.
 */
export const accountingAccountResponseSchema: z.ZodType<
  AccountingAccountResponse,
  z.ZodTypeDef,
  unknown
> = z.lazy(() =>
  z.object({
    id: accountIdSchema,
    uuid: z
      .string()
      .uuid('El UUID de la cuenta devuelto por el servidor no es válido.')
      .transform((v): AccountUuid => v as AccountUuid),
    company_id: companyIdSchema,
    parent_id: accountIdSchema.nullable(),
    code: z.string().min(1).max(20),
    name: z.string().min(1).max(180),
    level: accountLevelSchema,
    path: z.string().min(1).max(120),
    account_type: accountTypeSchema,
    account_type_label: z.string(),
    balance_type: balanceTypeSchema,
    balance_type_label: z.string(),
    currency_mode: accountCurrencyModeSchema,
    currency_mode_label: z.string(),
    requires_currency: z.boolean(),
    requires_auxiliary: z.boolean(),
    entity_requirement_type: entityRequirementTypeSchema,
    entity_requirement_label: z.string(),
    requires_cost_center: z.boolean(),
    requires_company_area: z.boolean(),
    has_automatic_destination: z.boolean(),
    destination_debit_code: z.string().nullable(),
    destination_credit_code: z.string().nullable(),
    requires_cash_flow_area: z.boolean(),
    cash_flow_category: cashFlowCategorySchema,
    cash_flow_category_label: z.string(),
    financial_statement_rubric: financialStatementRubricSchema,
    financial_statement_rubric_label: z.string(),
    is_operational: z.boolean(),
    is_active: z.boolean(),
    created_at: z
      .string()
      .datetime({ offset: true, message: 'Fecha created_at debe estar en formato ISO 8601.' })
      .transform((v): IsoDateTimeString => v as IsoDateTimeString),
    updated_at: z
      .string()
      .datetime({ offset: true, message: 'Fecha updated_at debe estar en formato ISO 8601.' })
      .transform((v): IsoDateTimeString => v as IsoDateTimeString),
    children: z.array(accountingAccountResponseSchema).default([]),
  }),
);

export const singleAccountingAccountApiResponseSchema = z.object({
  data: accountingAccountResponseSchema,
});

export const accountingAccountTreeApiResponseSchema = z.object({
  data: z.array(accountingAccountResponseSchema),
});

export type SingleAccountingAccountApiResponse = z.infer<
  typeof singleAccountingAccountApiResponseSchema
>;
export type AccountingAccountTreeApiResponse = z.infer<
  typeof accountingAccountTreeApiResponseSchema
>;
