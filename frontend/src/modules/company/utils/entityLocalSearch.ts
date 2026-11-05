import type { EntityMaster, EntityMasterPage } from '../types/entityMaster.types';

export type EntitySearchRole = 'all' | 'is_client' | 'is_supplier' | 'is_employee';

/** Ignore case and accents, but retain all data within the active company. */
const normalized = (value: string): string =>
  value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es-PE').trim();

/** Only an unfiltered, fully downloaded first page may be searched locally. */
export function isCompleteEntityDirectory(page: EntityMasterPage | null): boolean {
  return page !== null
    && page.current_page === 1
    && page.last_page === 1
    && page.data.length === page.total;
}

export function filterEntitiesLocally(
  items: readonly EntityMaster[],
  search: string,
  role: EntitySearchRole,
): readonly EntityMaster[] {
  const term = normalized(search);
  return items.filter((item) => {
    if (role !== 'all' && !item[role]) return false;
    if (!term) return true;
    return [
      item.legal_name,
      item.commercial_name ?? '',
      item.tax_id,
      item.identity_document_type_code,
    ].some((field) => normalized(field).includes(term));
  });
}
