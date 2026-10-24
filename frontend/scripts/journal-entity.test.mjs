import { test } from 'node:test';
import assert from 'node:assert/strict';
import { entityMatches, entityRequired, entityRoleFilter, entityRoleFilters } from '../src/modules/accounting/utils/journalEntity.ts';

test('journal requires an entity for auxiliary or explicit account requirements', () => {
  assert.equal(entityRequired({ requires_auxiliary: false, entity_requirement_type: 'NONE' }), false);
  assert.equal(entityRequired({ requires_auxiliary: true, entity_requirement_type: 'NONE' }), true);
  assert.equal(entityMatches({ requires_auxiliary: false, entity_requirement_type: 'ANY_THIRD_PARTY' }, null), false);
});
test('each account role filters and validates the matching active entity flag', () => {
  for (const [type, role] of Object.entries(entityRoleFilters)) {
    const account = { requires_auxiliary: false, entity_requirement_type: type };
    assert.equal(entityRoleFilter(type), role);
    assert.equal(entityMatches(account, { is_active: true, [role]: true }), true);
    assert.equal(entityMatches(account, { is_active: true, [role]: false }), false);
    assert.equal(entityMatches(account, { is_active: false, [role]: true }), false);
  }
});
test('optional entities still must be active and multi-role entities are accepted', () => {
  const optional = { requires_auxiliary: false, entity_requirement_type: 'NONE' };
  assert.equal(entityMatches(optional, null), true);
  assert.equal(entityMatches(optional, { is_active: false }), false);
  const entity = { is_active: true, is_client: true, is_supplier: true };
  for (const type of ['CUSTOMER', 'SUPPLIER', 'ANY_THIRD_PARTY']) {
    assert.equal(entityMatches({ requires_auxiliary: false, entity_requirement_type: type }, entity), true);
  }
  assert.equal(entityRoleFilter('NONE'), undefined);
});
