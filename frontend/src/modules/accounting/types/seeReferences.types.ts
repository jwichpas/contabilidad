export interface SeeCatalogSummary {
  readonly number: string;
  readonly name: string;
  readonly item_count: number;
  readonly classification: string;
  readonly latest_version: string;
  readonly historical_item_count: number;
  readonly available_versions: readonly {
    readonly version: string;
    readonly name: string;
    readonly item_count: number;
    readonly classification: string;
    readonly source_url: string;
  }[];
}

export interface SeeCatalogItem {
  readonly code: string;
  readonly description: string;
  readonly details: Readonly<Record<string, string>>;
  readonly source_row: number;
  readonly is_ambiguous: boolean;
}

export interface SeeCompanyVisibilityRevision {
  readonly revision: number;
  readonly source_version: string;
  readonly visible_codes: readonly string[];
}

export interface SeeCompanyVisibility {
  readonly currencies: readonly string[];
  readonly catalogs: Readonly<Record<'03' | '04', SeeCompanyVisibilityRevision | null>>;
}

export interface SeeUbigeo {
  readonly code: string;
  readonly district: string;
  readonly province: string;
  readonly department: string;
  readonly population: number | null;
  readonly area_km2: string | null;
  readonly latitude: string | null;
  readonly longitude: string | null;
}

export interface SeePage<T> {
  readonly data: readonly T[];
  readonly meta: {
    readonly total: number;
    readonly current_page: number;
    readonly last_page: number;
    readonly classification: string;
    readonly catalog_number?: string;
    readonly version?: string;
    readonly source_url?: string | null;
    readonly name?: string;
    readonly item_count?: number;
    readonly source_sha256?: string;
    readonly historical_item_count?: number;
    readonly available_versions?: readonly {
      readonly version: string;
      readonly item_count: number;
      readonly source_url: string;
    }[];
  };
}
