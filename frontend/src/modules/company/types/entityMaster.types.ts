/** Public-safe entity fields returned by the Laravel entities API. */
export interface EntityMaster {
  readonly id: number;
  readonly uuid: string;
  readonly company_id: number;
  readonly identity_document_type_code: string;
  readonly tax_id: string;
  readonly legal_name: string;
  readonly commercial_name: string | null;
  readonly fiscal_address: string | null;
  readonly country_code: string;
  readonly email: string | null;
  readonly phone: string | null;
  readonly is_client: boolean;
  readonly is_supplier: boolean;
  readonly is_employee: boolean;
  readonly is_financial_institution: boolean;
  readonly is_shareholder: boolean;
  readonly is_tax_authority: boolean;
  readonly is_related_party: boolean;
  readonly is_retention_agent: boolean;
  readonly is_good_taxpayer: boolean;
  readonly is_active: boolean;
  readonly created_at: string;
  readonly updated_at: string;
}

/** Deliberately omits tenant, audit and banking fields. */
export interface EntityMasterInput {
  readonly identity_document_type_code: string;
  readonly tax_id: string;
  readonly legal_name: string;
  readonly commercial_name?: string | null;
  readonly fiscal_address?: string | null;
  readonly country_code: string;
  readonly email?: string | null;
  readonly phone?: string | null;
  readonly is_client: boolean;
  readonly is_supplier: boolean;
  readonly is_employee: boolean;
  readonly is_financial_institution: boolean;
  readonly is_shareholder: boolean;
  readonly is_tax_authority: boolean;
  readonly is_related_party: boolean;
  readonly is_retention_agent: boolean;
  readonly is_good_taxpayer: boolean;
}

export interface EntityMasterPage {
  readonly data: readonly EntityMaster[];
  readonly current_page: number;
  readonly last_page: number;
  readonly per_page: number;
  readonly total: number;
}
