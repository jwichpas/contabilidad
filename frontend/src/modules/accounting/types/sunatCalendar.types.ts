export interface CalendarOverride {
  code: string;
  label: string;
  type: 'tax' | 'books';
  period: string;
  date: string;
  reference: string;
}

export interface SunatCalendarProfile {
  income_band: string;
  monthly_group: string;
  annual_schedule: string;
  annual_good: boolean;
  plame: boolean;
  itan: string;
  sales_electronic: boolean;
  inventory_electronic: boolean;
  extra_books: string[];
  excluded_books: string[];
  overrides: CalendarOverride[];
}

export interface SunatCalendarReport {
  year: number;
  ruc: string;
  regime: string | null;
  regimes: Record<number, string | null>;
  revision: number;
  profile: SunatCalendarProfile;
  books: { code: string; label: string }[];
  events: {
    code: string; label: string; type: 'tax' | 'books';
    period: string; date: string | null; source: string; basis: string;
  }[];
}
