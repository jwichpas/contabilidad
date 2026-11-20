export type MonthlyProfile = {
  prico: boolean;
  retention: boolean;
  perception: boolean;
  workers: boolean;
  assets: boolean;
  afp: boolean;
  cts: boolean;
  gratification: boolean;
  internal_due: string;
  evidence: string;
};

export type MonthlyTask = {
  code: string;
  label: string;
  due: string | null;
  kind: 'tax' | 'books' | 'labor' | 'internal';
  source: string;
  status: 'done' | 'undated' | 'overdue' | 'upcoming' | 'pending';
  revision: number;
  evidence: string | null;
};

export type MonthlyWork = {
  period: string;
  revision: number;
  profile: MonthlyProfile | null;
  tasks: MonthlyTask[];
  notifications: MonthlyTask[];
  kpi: Record<'total' | 'done' | 'overdue' | 'upcoming' | 'undated', number>;
  workpapers: {
    depreciation: { book: string; tax: string; assets: number; tax_pending: number } | null;
    payroll: { gross: string; net: string; employer_cost: string; workers: number } | null;
  };
};

export type PayrollRow = Record<
  'worker' | 'regular' | 'other' | 'pension' | 'income_tax' | 'other_deductions' | 'employer_contributions', string
>;
export type PayrollPaper = {
  source: string;
  rows: (PayrollRow & { net: string; gross: string; employer_cost: string })[];
  totals: NonNullable<MonthlyWork['workpapers']['payroll']>;
};
export type DepreciationPaper = {
  rows: {
    reference: string;
    label: string;
    source_revision: number;
    book: string;
    tax: string | null;
    carrying_amount: string;
  }[];
  totals: NonNullable<MonthlyWork['workpapers']['depreciation']>;
};
export type MonthlyPaper<T> = { revision: number; data: T | null };
