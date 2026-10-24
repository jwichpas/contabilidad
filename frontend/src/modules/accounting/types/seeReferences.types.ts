export interface SeeCatalogSummary {
  readonly number: string;
  readonly name: string;
  readonly item_count: number;
  readonly classification: string;
}

export interface SeeCatalogItem {
  readonly code: string;
  readonly description: string;
  readonly details: Readonly<Record<string, string>>;
  readonly source_row: number;
  readonly is_ambiguous: boolean;
}

export interface SeeUbigeo {
  readonly code: string;
  readonly district: string;
  readonly province: string;
  readonly department: string;
  readonly population: number;
  readonly area_km2: string;
  readonly latitude: string;
  readonly longitude: string;
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
  };
}
