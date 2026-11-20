export const payrollRoutes = {
  'payroll-workers': { hash: '#gestion-de-planillas/trabajadores', label: 'Trabajadores' },
  'payroll-attendance': { hash: '#gestion-de-planillas/asistencia', label: 'Asistencia' },
  'payroll-monthly': { hash: '#gestion-de-planillas/planilla-mensual', label: 'Planilla mensual' },
  'payroll-benefits': { hash: '#gestion-de-planillas/beneficios', label: 'CTS y gratificaciones' },
  'payroll-vacations': { hash: '#gestion-de-planillas/vacaciones', label: 'Vacaciones' },
} as const;

export type PayrollModule = keyof typeof payrollRoutes;

export function isPayrollModule(module: string): module is PayrollModule {
  return Object.hasOwn(payrollRoutes, module);
}

export function resolvePayrollModule(hash: string): PayrollModule | undefined {
  if (hash === '#gestion-de-planillas') return 'payroll-workers';
  return Object.keys(payrollRoutes).find((module): module is PayrollModule =>
    isPayrollModule(module) && payrollRoutes[module].hash === hash,
  );
}

export type NavigationModule = 'catalog' | 'see-references' | 'sunat-references' | 'journal' | 'purchases' | 'sales' | 'financial-statements' | 'annual-tax-close' | 'sunat-calendar' | 'monthly-work' | PayrollModule | 'external-tax-reports' | 'loans' | 'fixed-assets' | 'cash-flow' | 'company' | 'company-create' | 'entities' | 'fiscal-history' | 'cost-centers' | 'subdiaries' | 'exchange-rates' | 'fiscal-periods';

export interface ActiveCompanyInfo {
  readonly id: number;
  readonly taxId: string;
  readonly businessName: string;
  readonly legalAddress?: string;
  readonly fiscalRegime: string;
}

