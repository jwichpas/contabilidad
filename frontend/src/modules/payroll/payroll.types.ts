import type { TaxJournalDraft } from '@/modules/accounting/types/annualTaxClose.types';

export type Revision<T> = { reference: string; revision: number; data: T };
export type Worker = {
  code: string; dni: string; surname: string; second_surname: string | null; given_names: string; position: string;
  effective_month: string; hired_on: string; ended_on: string | null; salary: string; daily_hours: number;
  payslip_profile?: { sex?: 'F' | 'M' | null; birth_date?: string | null; address?: string | null; nationality?: string | null } | null;
  family_allowance?: boolean; night_shift?: boolean; afp_commission?: 'FLOW' | 'BALANCE' | null;
  pension: 'ONP' | 'AFP'; afp: string | null; cuspp: string | null; vacation_granted: number; reference: string;
};
export type ProvisionInputs = {
  gratification_base: string; gratification_month: boolean; cts_base: string; prior_gratification: string;
  cts_days: number; vacation_base: string; vacation_days: number; coverage: 'ESSALUD' | 'EPS'; reference: string;
};
export type WorkInput = {
  worker: string; paid_days: number; vacation_paid_days: number; ordinary_minutes: number; overtime_25: number; overtime_35: number;
  reference: string | null;
};
type PayrollMoneyInput = {
  taxable_extra: string; nontaxable_extra: string; income_tax: string; other_deductions: string;
  employer_insurance?: string | null;
  provisions?: ProvisionInputs | null;
  afp_rates: { insurance: string; commission: string; insurance_ceiling: string; source: string } | null;
};
export type Attendance = WorkInput & Partial<PayrollMoneyInput>;
export type PayrollInput = WorkInput & PayrollMoneyInput & { worked_days?: number | null; input_source: 'manual' | 'attendance'; jornada_confirmed: boolean };
export type PayrollInputDraft = PayrollInput & { expected_revision: number; import_attendance?: boolean };
export type AttendanceDay = { worker: string; date: string; ordinary: number; extra: number; status: string; reference: string };
export type Vacation = { worker: string; from: string; to: string; days: number; reference: string };
export type Benefit = {
  worker: string; kind: 'CTS' | 'GRATIFICATION'; base: string; months: number; days: number;
  previous_gratification: string | null; health: 'ESSALUD' | 'EPS'; posting_date: string; withholding: string; reference: string;
  result: { basis: string; amount: string; bonus: string; net: string }; journal: TaxJournalDraft | null;
};
export type PayrollRow = {
  worker: Worker;
  worker_revision: number;
  attendance_revision: number | null;
  input_revision?: number;
  vacation_periods?: { from: string; to: string }[];
  attendance: WorkInput & PayrollMoneyInput & { worked_days?: number | null; jornada_confirmed?: boolean; input_source?: PayrollInput['input_source'] };
  amounts: Record<string, string>;
};
export type Payroll = {
  employer?: { name: string; ruc: string; address?: string | null };
  approved: boolean; input_mode?: 'independent'; health_minimum: string; source: string; parameters?: PayrollParameters;
  rows: PayrollRow[];
  totals: Record<string, string>; journal: TaxJournalDraft | null;
};
export type PayrollParameterKind = 'rmv' | 'uit' | 'afp';
export type PayrollAfpCode = 'HA' | 'IN' | 'PR' | 'RI';
export type PayrollAfpRates = {
  contribution: string;
  insurance: string;
  insurance_ceiling: string;
  administrators: Record<PayrollAfpCode, { flow: string; balance: string }>;
};
export type PayrollParameterEntry = {
  kind: PayrollParameterKind;
  period: string;
  source: string;
  url?: string | null;
} & ({ kind: 'rmv' | 'uit'; amount: string } | ({ kind: 'afp' } & PayrollAfpRates));
export type PayrollParameterRevision = Revision<PayrollParameterEntry> & {
  created_at: string | null;
  created_by: number | null;
};
export type PayrollParameters = {
  minimum: { amount: string; from: string; rule: string; url: string | null } | null;
  family_allowance: string | null;
  night_minimum: string | null;
  uit: string | null;
  tax_deduction: string | null;
  overtime: [string, string];
  onp: string;
  afp_contribution: string;
  essalud: string;
  gratification_bonus: { essalud: string; eps: string };
  afp: {
    source: string; url?: string | null; insurance: string; insurance_ceiling: string;
    administrators: Record<string, { flow: string; balance: string }>;
  } | null;
};

export type PayrollWorkspace = {
  workers: Revision<Worker>[]; eligible: Revision<Worker>[]; attendance: Revision<Attendance>[];
  parameters?: PayrollParameters;
  parameters_changed?: boolean;
  payroll_inputs?: Revision<PayrollInput>[];
  attendance_pending?: { worker: string; message: string }[];
  daily: Revision<AttendanceDay>[]; vacations: Revision<Vacation>[]; benefits: Revision<Benefit>[]; payroll: Revision<Payroll> | null;
};
