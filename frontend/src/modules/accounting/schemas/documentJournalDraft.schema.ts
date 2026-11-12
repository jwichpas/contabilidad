import { z } from 'zod';
import type { DocumentJournalModule, TaxCategory } from '../types/documentJournal.types';

const amount = z.string().regex(/^\d+(?:\.\d{1,4})?$/, 'Ingrese un importe válido (hasta 4 decimales).');
const positive = amount.refine(value => Number(value) > 0 && Number.isFinite(Number(value)), 'La base debe ser mayor a cero.');
const nonNegative = amount.refine(value => Number(value) >= 0 && Number.isFinite(Number(value)), 'El importe debe ser mayor o igual a cero.');

const purchaseCategories = ['P_TAXED_EXCLUSIVE', 'P_TAXED_MIXED', 'P_TAXED_NON_TAXED', 'P_TAXED_NO_CREDIT', 'P_NON_TAXED'];
const saleCategories = ['S_TAXED', 'S_EXONERATED', 'S_UNAFFECTED', 'S_EXPORT'];
const taxedCategories = new Set(['P_TAXED_EXCLUSIVE', 'P_TAXED_MIXED', 'P_TAXED_NON_TAXED', 'P_TAXED_NO_CREDIT', 'S_TAXED']);

const allocation = z.object({
  category: z.enum([
    'P_TAXED_EXCLUSIVE', 'P_TAXED_MIXED', 'P_TAXED_NON_TAXED', 'P_TAXED_NO_CREDIT', 'P_NON_TAXED',
    'S_TAXED', 'S_EXONERATED', 'S_UNAFFECTED', 'S_EXPORT',
  ]),
  base_amount: positive,
  tax_amount: nonNegative,
  creditable_tax_amount: nonNegative,
});

export function createDocumentJournalDraftSchema(module: DocumentJournalModule) {
  return z.object({
    document_type: z.enum(['01', '03']),
    series: z.string().trim().min(1, 'Ingrese la serie.').max(20, 'Serie demasiado larga.')
      .regex(/^[a-zA-Z0-9-]+$/, 'La serie solo admite letras, números y guiones.'),
    number: z.string().trim().min(1, 'Ingrese el número del comprobante.').max(20, 'Número demasiado largo.')
      .regex(/^[a-zA-Z0-9-]+$/, 'El número solo admite letras, números y guiones.'),
    entity_id: z.string().regex(/^[1-9]\d*$/, 'Seleccione un cliente o proveedor.'),
    issue_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Indique una fecha de emisión válida.'),
    entry_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Indique una fecha contable válida.'),
    currency: z.string().regex(/^[A-Z]{3}$/, 'Seleccione una moneda válida.'),
    tax_period: z.string().regex(/^\d{4}-(0[1-9]|1[0-2])$/, 'Seleccione el período tributario.'),
    due_date: z.string().refine(value => value === '' || /^\d{4}-\d{2}-\d{2}$/.test(value), 'Fecha de vencimiento inválida.'),
    cost_center_id: z.string(),
    company_area_id: z.string(),
    cash_flow_area_id: z.string(),
    description: z.string().max(180, 'La glosa debe tener máximo 180 caracteres.'),
    tax_allocations: z.array(allocation).min(1, 'Agregue al menos una clasificación tributaria.'),
  }).superRefine((value, ctx) => {
    if (value.entry_date < value.issue_date) {
      ctx.addIssue({ code: 'custom', path: ['entry_date'], message: 'La fecha contable no puede ser anterior a la emisión.' });
    }
    const allowed = module === 'purchases' ? purchaseCategories : saleCategories;
    const seen = new Set<string>();
    value.tax_allocations.forEach((row, index) => {
      const issue = (field: string, message: string) => ctx.addIssue({
        code: 'custom', path: ['tax_allocations', index, field], message,
      });
      if (!allowed.includes(row.category)) issue('category', 'Esta clasificación no corresponde al módulo.');
      if (seen.has(row.category)) issue('category', 'La clasificación está repetida.');
      seen.add(row.category);
      const tax = Number(row.tax_amount);
      if (taxedCategories.has(row.category) && tax === 0) issue('tax_amount', 'Las operaciones gravadas requieren IGV positivo.');
      if (!taxedCategories.has(row.category) && tax !== 0) issue('tax_amount', 'Esta clasificación no admite IGV.');
      if (module === 'purchases') {
        if (row.category === 'P_TAXED_EXCLUSIVE' && value.document_type === '03') {
          issue('category', 'Una boleta de compra no genera crédito fiscal automático.');
        }
        if (row.category === 'P_TAXED_MIXED' && Number(row.creditable_tax_amount) > tax) {
          issue('creditable_tax_amount', 'El crédito fiscal no puede superar el IGV.');
        }
        if (row.category === 'P_TAXED_MIXED' && value.document_type === '03' && Number(row.creditable_tax_amount) > 0) {
          issue('creditable_tax_amount', 'Una boleta no admite crédito fiscal automático.');
        }
      }
      if (row.category === 'S_EXPORT' && value.document_type !== '01') {
        issue('category', 'En este flujo, registre exportaciones con factura (01).');
      }
    });
  });
}

export type DocumentJournalDraftValues = z.input<ReturnType<typeof createDocumentJournalDraftSchema>>;
