import type { TaxJournalDraft } from '@/modules/accounting/types/annualTaxClose.types';

export type Revision<T> = { reference: string; revision: number; data: T };
export type Worker = {
  code: string; dni: string; surname: string; second_surname: string | null; given_names: string; position: string;
  effective_month: string; hired_on: string; ended_on: string | null; salary: string; daily_hours: number;
  pension: 'ONP' | 'AFP'; afp: string | null; cuspp: string | null; vacation_granted: number; reference: string;
};
export type Attendance = {
  worker: string; paid_days: number; vacation_paid_days: number; ordinary_minutes: number; overtime_25: number; overtime_35: number;
  taxable_extra: string; nontaxable_extra: string; income_tax: string; other_deductions: string; reference: string;
  afp_rates: { insurance: string; commission: string; insurance_ceiling: string; source: string } | null;
};
export type AttendanceDay = { worker: string; date: string; ordinary: number; extra: number; status: string; reference: string };
export type Vacation = { worker: string; from: string; to: string; days: number; reference: string };
export type Benefit = {
  worker: string; kind: 'CTS' | 'GRATIFICATION'; base: string; months: number; days: number;
  previous_gratification: string | null; health: 'ESSALUD' | 'EPS'; posting_date: string; withholding: string; reference: string;
  result: { basis: string; amount: string; bonus: string; net: string }; journal: TaxJournalDraft | null;
};
export type Payroll = {
  approved: boolean; health_minimum: string; source: string;
  rows: { worker: Worker; amounts: Record<string, string> }[];
  totals: Record<string, string>; journal: TaxJournalDraft | null;
};
export type PayrollWorkspace = {
  workers: Revision<Worker>[]; eligible: Revision<Worker>[]; attendance: Revision<Attendance>[];
  daily: Revision<AttendanceDay>[]; vacations: Revision<Vacation>[]; benefits: Revision<Benefit>[]; payroll: Revision<Payroll> | null;
};
