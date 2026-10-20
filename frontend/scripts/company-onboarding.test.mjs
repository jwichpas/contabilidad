import assert from 'node:assert/strict';
import test from 'node:test';
import {
  isValidPeruvianRuc, newCompanyOnboardingDraft, onboardingStepErrors,
  onboardingIsComplete, companyOnboardingPayload,
} from '../src/modules/company/types/companyOnboarding.types.ts';

function exampleDraft() {
  const d = newCompanyOnboardingDraft(2026);
  d.tax_id = '20100070970';
  d.business_name = 'Empresa Demo de Tacna SAC';
  d.legal_address = 'Avenida Principal 123, Tacna';
  d.legal_form = 'SAC';
  d.activities = [
    { sector_code: 'COMERCIAL', ciiu_code: '4752', description: 'Venta de artículos de ferretería', is_primary: true },
    { sector_code: 'TRANSPORTE_LOGISTICA', ciiu_code: '4923', description: 'Transporte de carga', is_primary: false },
  ];
  d.tax_regime = 'GENERAL';
  d.income_reference_year = 2025;
  d.annual_income = '150000.00';
  d.base_currency_code = 'PEN';
  d.secondary_currency_codes = ['USD'];
  d.pcge_version = 'PCGE_2019';
  d.confirm_legal_data = true;
  return d;
}

test('validates RUC including check digit (not length alone)', () => {
  assert.equal(isValidPeruvianRuc('20100070970'), true);
  assert.equal(isValidPeruvianRuc('20100070971'), false);
  assert.equal(isValidPeruvianRuc('2010007097'), false);
});

test('permits one main activity and secondary CIIU entries', () => {
  const d = exampleDraft();
  assert.deepEqual(onboardingStepErrors(1, d), {});
  d.activities[1].is_primary = true;
  assert.ok(onboardingStepErrors(1, d).activities);
  d.activities[1].is_primary = false;
  d.activities[1].ciiu_code = '4752';
  assert.ok(onboardingStepErrors(1, d).ciiu_1);
});

test('requires natural person for NRUS', () => {
  const d = exampleDraft();
  d.tax_regime = 'NRUS';
  assert.ok(onboardingStepErrors(2, d).tax_regime);
  d.legal_form = 'PERSONA_NATURAL';
  assert.deepEqual(onboardingStepErrors(2, d), {});
});

test('blocks silent selection of PCGE 2026 before mandatory 2028 date', () => {
  const d = exampleDraft();
  d.pcge_version = 'PCGE_2026';
  assert.ok(onboardingStepErrors(3, d).confirm_early_adoption);
  d.confirm_early_adoption = true;
  assert.deepEqual(onboardingStepErrors(3, d), {});
});

test('rejects duplicated currencies and invalid annual income', () => {
  const d = exampleDraft();
  d.secondary_currency_codes = ['PEN'];
  assert.ok(onboardingStepErrors(3, d).secondary_currency_codes);
  d.secondary_currency_codes = ['USD'];
  d.annual_income = '100.999';
  assert.ok(onboardingStepErrors(2, d).annual_income);
});

test('only emits reviewed, validated payloads with early-adoption consent', () => {
  const d = exampleDraft();
  assert.equal(onboardingIsComplete(d), true);
  const payload = companyOnboardingPayload(d);
  assert.equal(payload.tax_id, '20100070970');
  assert.equal(payload.economic_activities.length, 2);
  assert.equal(payload.confirm_early_adoption, false);
  d.confirm_legal_data = false;
  assert.equal(onboardingIsComplete(d), false);
});
