import type { EntryStatus, EntryType } from '../types/journalEntry.types';

/**
 * Presenta fechas de libro diario sin convertir el día contable en un instante.
 * Una fecha ISO con sufijo UTC no debe desplazarse por la zona horaria del navegador.
 */
export function formatAccountingDate(value: string): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})(?:$|[T ])/u.exec(value);
  if (!match) return value;

  const year = Number(match[1]);
  const month = Number(match[2]);
  const day = Number(match[3]);
  const check = new Date(Date.UTC(year, month - 1, day));
  if (check.getUTCFullYear() !== year ||
      check.getUTCMonth() !== month - 1 ||
      check.getUTCDate() !== day) return value;

  return `${match[3]}/${match[2]}/${match[1]}`;
}

export function journalEntryTypeLabel(value: EntryType): string {
  switch (value) {
    case 'STANDARD': return 'Diario';
    case 'OPENING': return 'Apertura';
    case 'ADJUSTMENT': return 'Ajuste';
    case 'REVERSAL': return 'Reversión';
    case 'CLOSING': return 'Cierre';
    default: return value;
  }
}

export function journalEntryStatusLabel(value: EntryStatus): string {
  switch (value) {
    case 'POSTED': return 'Registrado';
    case 'REVERSED': return 'Revertido';
    default: return value;
  }
}
