import type { CostCenterInput } from '../types/costCenterMaster.types';

/** Explicit payload allowlist: tenant, state and identifiers never come from a form. */
export function costCenterPayload(draft: CostCenterInput): CostCenterInput {
  const code = draft.code.trim().toUpperCase();
  const name = draft.name.trim();
  if (!/^[A-Z0-9][A-Z0-9_-]{0,19}$/.test(code)) {
    throw new Error('Use un código de hasta 20 caracteres: letras, números, guion o guion bajo.');
  }
  if (!name || name.length > 150) {
    throw new Error('Ingrese un nombre de hasta 150 caracteres.');
  }
  const debit = draft.destination_debit_account_id;
  const credit = draft.destination_credit_account_id;
  if (!Number.isSafeInteger(debit) || debit <= 0 || !Number.isSafeInteger(credit) || credit <= 0 || debit === credit) {
    throw new Error('Seleccione dos cuentas de destino distintas.');
  }
  return { code, name, destination_debit_account_id: debit, destination_credit_account_id: credit };
}
