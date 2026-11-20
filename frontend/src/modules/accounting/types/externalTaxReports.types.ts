export type DaotDocument = {
  id: number;
  module: 'PURCHASE' | 'SALE';
  document: string;
  date: string;
  currency: string;
  basis: string;
  name: string;
  identity: string;
  identity_type: string;
  amount_pen: string | null;
  status: 'pending' | 'included' | 'excluded';
  reason: string;
  revision: number;
};

export type DaotReport = {
  year: number;
  ruc: string;
  threshold: number;
  due: string;
  status: 'UNCONFIRMED' | 'REQUIRED' | 'NOT_REQUIRED';
  counts: Record<DaotDocument['status'], number>;
  rows: (Pick<DaotDocument, 'module' | 'identity' | 'identity_type' | 'name'> & { amount_pen: string; documents: number; over_threshold: boolean })[];
  documents: { data: DaotDocument[]; next_page_url: string | null };
};

export type PdbInput = {
  id: number;
  surname?: string;
  second_surname?: string;
  first_name?: string;
  second_name?: string;
  foreign_without_id?: boolean;
  customs_reference?: string;
  tax_paid_on?: string;
  retention?: boolean;
  detraction?: Record<string, string>;
  perception?: Record<string, string>;
  reference?: Record<string, string>;
  shipment?: Record<string, string>;
  total_pen?: string;
  payments?: Record<string, string>[];
  service?: Record<string, string>;
};

export type PdbReport = {
  period: string;
  ruc: string;
  documents: number;
  export_candidates: { id: number; document: string; period: string; name: string }[];
  problems: { document_id: number | null; document: string; file: string; message: string }[];
  files: { name: string; rows: number; review_required: boolean; base64: string | null }[];
  source: string;
};
