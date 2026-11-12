import type { DocumentJournalModule } from '../types/documentJournal.types';

/**
 * The search parameter must be absent when the text is blank.
 * Laravel's ConvertEmptyStringsToNull middleware turns ?search= into null,
 * which fails the existing optional string validation.
 */
export function buildDocumentJournalEntityQuery(
  module: DocumentJournalModule,
  search = '',
): string {
  const query = new URLSearchParams({ per_page: '100' });
  const term = search.trim();

  if (term !== '') {
    query.set('search', term);
  }
  query.set(module === 'purchases' ? 'is_supplier' : 'is_client', '1');

  return query.toString();
}
