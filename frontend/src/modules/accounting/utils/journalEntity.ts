export const entityRoleFilters = {
  CUSTOMER: 'is_client', SUPPLIER: 'is_supplier', EMPLOYEE: 'is_employee',
  SHAREHOLDER: 'is_shareholder', FINANCIAL_INSTITUTION: 'is_financial_institution',
  TAX_AUTHORITY: 'is_tax_authority',
} as const;

export type EntityRoleFilter = typeof entityRoleFilters[keyof typeof entityRoleFilters];
export interface EntityAccountRule {
  readonly requires_auxiliary: boolean;
  readonly entity_requirement_type?: string;
}
export function entityRequired(account: EntityAccountRule | undefined): boolean {
  return !!account && (account.requires_auxiliary ||
    (!!account.entity_requirement_type && account.entity_requirement_type !== 'NONE'));
}
export function entityRoleFilter(type: string | undefined): EntityRoleFilter | undefined {
  return type && Object.hasOwn(entityRoleFilters, type)
    ? entityRoleFilters[type as keyof typeof entityRoleFilters] : undefined;
}
export function entityMatches(account: EntityAccountRule | undefined,
  entity: ({ readonly is_active: boolean } & Partial<Record<EntityRoleFilter, boolean>>) | null): boolean {
  const type = account?.entity_requirement_type;
  if (type && type !== 'NONE' && type !== 'ANY_THIRD_PARTY' && !entityRoleFilter(type)) return false;
  if (!entity) return !entityRequired(account);
  if (!entityRequired(account)) return false;
  if (!entity.is_active) return false;
  const role = entityRoleFilter(account?.entity_requirement_type);
  return !role || entity[role] === true;
}
