<?php

declare(strict_types=1);

use App\Models\AccountingAccount;
use App\Models\Company;
use App\Models\CompanySubdiary;
use App\Models\FiscalPeriod;
use App\Services\Accounting\PostJournalEntryService;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

const TAX_CLOSE_PATH = '/api/v1/accounting/annual-tax-close/2025';

beforeEach(function (): void {
    $this->tenant = app(TenantContext::class);
    $this->company = Company::query()->create([
        'tax_id' => '20'.random_int(100000000, 999999999),
        'business_name' => 'Laboratorio de cierre fiscal', 'legal_address' => 'DEV', 'is_active' => true,
    ]);
    $this->user = User::query()->create([
        'name' => 'Contador fiscal', 'email' => 'fiscal_'.bin2hex(random_bytes(7)).'@example.test',
        'password' => 'testing-only-2026!',
    ]);
    $this->tenant->run((int) $this->company->id, function (): void {
        DB::table('company_user')->insert([
            'company_id' => $this->company->id, 'user_id' => $this->user->id,
            'role' => 'ADMIN', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });
    $this->user->update(['current_company_id' => $this->company->id]);
    Sanctum::actingAs($this->user, ['*']);
    $this->withHeader('X-Company-Id', (string) $this->company->id);
    $this->inputs = json_decode(
        file_get_contents(__DIR__.'/../../Fixtures/annual_tax_close_sunat_2025.json'),
        true, 512, JSON_THROW_ON_ERROR
    );
});

it('reproduces the 39 adjustments and freezes each approved revision', function (): void {
    $this->getJson(TAX_CLOSE_PATH)->assertOk()->assertJsonPath('data.revision', 0);
    $this->putJson(TAX_CLOSE_PATH, ['expected_revision' => 0, 'inputs' => $this->inputs])
        ->assertOk()->assertJsonPath('data.revision', 1);
    $snapshot = $this->getJson(TAX_CLOSE_PATH)->assertOk()
        ->assertJsonCount(39, 'data.inputs.adjustments')
        ->assertJsonPath('data.result.casilla_103', '981084.00')
        ->assertJsonPath('data.result.casilla_105', '394411.00')
        ->assertJsonPath('data.result.adjusted_income', '15848751.00')
        ->assertJsonPath('data.result.participation', '1267900.00')
        ->assertJsonPath('data.result.taxable_income', '14580851.00')
        ->assertJsonPath('data.result.current_tax', '4301351.00')
        ->assertJsonPath('data.result.itan_assessed', '171406.00')
        ->assertJsonPath('data.result.advances_cash', '1025167.00')
        ->assertJsonPath('data.result.advances_total', '1196573.00')
        ->assertJsonPath('data.result.settlement_before_ciprl', '3104778.00')
        ->assertJsonPath('data.result.asset_closing', '104336.00')
        ->assertJsonPath('data.result.liability_closing', '3638.00')
        ->assertJsonPath('data.result.tax_expense', '4200653.00')
        ->assertJsonPath('data.result.net_profit', '9793525.00')
        ->assertJsonCount(5, 'data.result.journal_proposals')
        ->assertJsonPath('data.result.journal_proposals.1.lines.0.debit', '633319.00');
    foreach ($snapshot->json('data.result.journal_proposals') as $journal) {
        $debit = $credit = '0.00';
        foreach ($journal['lines'] as $line) {
            $debit = bcadd($debit, $line['debit'], 2);
            $credit = bcadd($credit, $line['credit'], 2);
        }
        expect($debit)->toBe($credit);
    }

    $this->putJson(TAX_CLOSE_PATH, ['expected_revision' => 0, 'inputs' => $this->inputs])->assertConflict();
    $this->postJson(TAX_CLOSE_PATH.'/submit', ['expected_revision' => 1])
        ->assertOk()->assertJsonPath('data.status', 'IN_REVIEW');
    $this->postJson(TAX_CLOSE_PATH.'/approve', ['expected_revision' => 2])
        ->assertOk()->assertJsonPath('data.status', 'APPROVED');
    $this->putJson(TAX_CLOSE_PATH, ['expected_revision' => 3, 'inputs' => $this->inputs])->assertConflict();
    $this->postJson(TAX_CLOSE_PATH.'/rectify', ['expected_revision' => 3, 'reason' => 'Nuevo sustento'])
        ->assertOk()->assertJsonPath('data.revision', 4);
    $this->getJson(TAX_CLOSE_PATH.'?revision=3')->assertOk()->assertJsonPath('data.status', 'APPROVED')
        ->assertJsonPath('data.latest_revision', 4);

    $companyId = $this->company->id;
    $this->tenant->run((int) $companyId, function () use ($companyId): void {
        expect(DB::table('audit_logs')->where('company_id', $companyId)
            ->where('event_type', 'ANNUAL_TAX_CLOSE_REVISION')->count())->toBe(4);
    });
});

it('rejects a second application of ITAN, duplicate months and excess CIPRL', function (): void {
    $payload = ['expected_revision' => 0, 'inputs' => $this->inputs];
    $payload['inputs']['itan_at_close'] = '1';
    $this->putJson(TAX_CLOSE_PATH, $payload)->assertUnprocessable()->assertJsonValidationErrors('inputs.itan_at_close');
    $payload['inputs'] = $this->inputs;
    $payload['inputs']['advances'][1]['month'] = 1;
    $this->putJson(TAX_CLOSE_PATH, $payload)->assertUnprocessable()->assertJsonValidationErrors('inputs.advances.1.month');
    $payload['inputs'] = $this->inputs;
    $payload['inputs']['ciprl_applied'] = '330000.01';
    $this->putJson(TAX_CLOSE_PATH, $payload)->assertUnprocessable()->assertJsonValidationErrors('inputs.ciprl_applied');
    $payload['inputs']['ciprl_applied'] = '330000';
    $this->putJson(TAX_CLOSE_PATH, $payload)->assertOk();
    $this->getJson(TAX_CLOSE_PATH)->assertOk()->assertJsonPath('data.result.cash_to_pay', '2774778.00');
});

it('requires evidence for submission and keeps write permissions scoped to the company role', function (): void {
    $other = Company::query()->create([
        'tax_id' => '20'.random_int(100000000, 999999999),
        'business_name' => 'Empresa ajena', 'legal_address' => 'DEV', 'is_active' => true,
    ]);
    $this->getJson(TAX_CLOSE_PATH, ['X-Company-Id' => (string) $other->id])->assertForbidden();

    $this->tenant->run((int) $this->company->id, function (): void {
        DB::table('company_user')->where('company_id', $this->company->id)
            ->where('user_id', $this->user->id)->update(['role' => 'ACCOUNTANT']);
    });
    $payload = ['expected_revision' => 0, 'inputs' => $this->inputs];
    $payload['inputs']['book_reference'] = null;
    $this->putJson(TAX_CLOSE_PATH, $payload)->assertOk();
    $this->postJson(TAX_CLOSE_PATH.'/submit', ['expected_revision' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('inputs.book_reference');
    $this->putJson(TAX_CLOSE_PATH, ['expected_revision' => 1, 'inputs' => $this->inputs])->assertOk();
    $this->postJson(TAX_CLOSE_PATH.'/submit', ['expected_revision' => 2])->assertOk();
    $this->postJson(TAX_CLOSE_PATH.'/approve', ['expected_revision' => 3])->assertForbidden();
    $this->getJson(str_replace('2025', '2026', TAX_CLOSE_PATH))->assertNotFound();
});

it('preserves losses and separates a deferred-tax reversal from the current tax', function (): void {
    $inputs = $this->inputs;
    $inputs['book_profit'] = '-10000.50';
    $inputs['adjustments'] = [];
    $inputs['participation_deductible'] = '0';
    $inputs['participation_admin'] = '0';
    $inputs['participation_sales'] = '0';
    $inputs['advances'] = [];
    $inputs['deferred'] = [[
        'label' => 'Reversión', 'kind' => 'ASSET', 'opening_tax' => '1200.00',
        'closing_difference' => '0', 'rate' => '29.5', 'recognize_closing' => false,
    ]];
    $this->putJson(TAX_CLOSE_PATH, ['expected_revision' => 0, 'inputs' => $inputs])->assertOk();
    $this->getJson(TAX_CLOSE_PATH)->assertOk()
        ->assertJsonPath('data.result.adjusted_income', '-10001.00')
        ->assertJsonPath('data.result.taxable_income', '0.00')
        ->assertJsonPath('data.result.participation', '0.00')
        ->assertJsonPath('data.result.current_tax', '0.00')
        ->assertJsonPath('data.result.deferred_expense', '1200.00');
});

it('prepares the FV 710 working balance from the ledger with separate opening and closing movements', function (): void {
    $closing = $this->tenant->run((int) $this->company->id, function () {
        DB::table('company_settings')->insert([
            'company_id' => $this->company->id,
            'base_currency_id' => DB::table('currencies')->where('code', 'PEN')->value('id'),
            'tax_regime' => 'GENERAL', 'default_vat_rate' => '18.00',
        ]);
        CompanySubdiary::query()->create(['code' => 'GENERAL', 'name' => 'General']);
        $account = static function (array $codes, string $type = 'ASSET'): int {
            $parent = null;
            foreach ($codes as $i => $code) {
                $parent = AccountingAccount::query()->firstOrCreate(['code' => $code], [
                    'name' => 'Cuenta '.$code, 'parent_id' => $parent?->id, 'level' => $i + 1,
                    'path' => $parent === null ? $code : $parent->path.'.'.$code,
                    'account_type' => $type, 'balance_type' => $type === 'REVENUE' ? 'CREDIT' : 'DEBIT', 'currency_mode' => 'MULTI',
                    'is_operational' => $i === 5, 'is_active' => true, 'requires_auxiliary' => false,
                    'entity_requirement_type' => 'NONE', 'requires_cash_flow_area' => false,
                    'cash_flow_category' => 'NONE', 'financial_statement_rubric' => 'ESF_ACT_CORR_CXC_COM',
                ]);
            }

            return (int) $parent->id;
        };
        $debit = $account(['1', '10', '104', '1041', '10410', '104101']);
        $credit = $account(['1', '12', '121', '1212', '12120', '121201']);
        $revenue = $account(['7', '70', '701', '7011', '70110', '701101'], 'REVENUE');
        $retained = $account(['5', '59', '591', '5911', '59110', '591101'], 'EQUITY');
        $post = function (string $date, string $type, string $amount, ?int $dr = null, ?int $cr = null) use ($debit, $credit) {
            $period = FiscalPeriod::query()->firstOrCreate([
                'year' => (int) substr($date, 0, 4), 'month' => (int) substr($date, 5, 2),
            ], [
                'start_date' => date('Y-m-01', strtotime($date)), 'end_date' => date('Y-m-t', strtotime($date)),
                'status' => 'OPEN',
            ]);
            return app(PostJournalEntryService::class)->post([
                'fiscal_period_id' => $period->id, 'entry_date' => $date,
                'entry_type' => $type, 'currency' => 'PEN', 'exchange_rate' => '1.000000',
                'exchange_rate_type' => 'PARITY', 'description' => 'Movimiento para balance anual',
                'created_by_user_id' => $this->user->id,
                'lines' => [
                    ['accounting_account_id' => $dr ?? $debit, 'debit_local' => $amount,
                        'credit_local' => '0', 'debit_foreign' => $amount, 'credit_foreign' => '0'],
                    ['accounting_account_id' => $cr ?? $credit, 'debit_local' => '0',
                        'credit_local' => $amount, 'debit_foreign' => '0', 'credit_foreign' => $amount],
                ],
            ]);
        };
        $post('2024-12-31', 'STANDARD', '10.25');
        $post('2025-01-01', 'OPENING', '10.25');
        $post('2025-02-01', 'STANDARD', '20.55');
        $post('2025-12-30', 'ADJUSTMENT', '5.10');

        $post('2025-02-02', 'STANDARD', '200.00', $credit, $revenue);

        return $post('2025-12-31', 'CLOSING', '200.00', $revenue, $retained);
    });

    $url = TAX_CLOSE_PATH.'/trial-balance';
    $this->getJson($url)->assertOk()->assertJsonPath('data.opening_ambiguous', true)
        ->assertJsonPath('data.balanced', false);
    $this->getJson($url.'?opening=entry')->assertOk()->assertJsonPath('data.balanced', true)
        ->assertJsonPath('data.rows.0.code', '1041')->assertJsonPath('data.rows.0.accounts.0', '104101')
        ->assertJsonPath('data.rows.0.opening_debit', '10.2500')
        ->assertJsonPath('data.rows.0.movement_debit', '25.6500')
        ->assertJsonPath('data.rows.0.transfer_debit', '0.0000')
        ->assertJsonPath('data.totals.transfer_debit', '200.0000');

    $statements = '/api/v1/accounting/reports/financial-snapshot?as_of=2025-12-31';
    $this->getJson($statements)->assertOk()
        ->assertJsonPath('data.income_statement.net', '200.0000')
        ->assertJsonPath('data.totals.result', '0.0000');
    $this->postJson('/api/v1/accounting/journal-entries/'.$closing->id.'/reverse', [
        'fiscal_period_id' => $closing->fiscal_period_id, 'reversal_date' => '2025-12-31',
        'reason' => 'Extornar el cierre de prueba',
    ])->assertCreated();
    $this->getJson($url.'?opening=historical')->assertOk()
        ->assertJsonPath('data.rows.0.opening_debit', '10.2500')
        ->assertJsonPath('data.rows.0.movement_debit', '25.6500')
        ->assertJsonPath('data.totals.transfer_debit', '400.0000')
        ->assertJsonPath('data.totals.transfer_credit', '400.0000');
    $this->getJson($statements)->assertOk()
        ->assertJsonPath('data.income_statement.net', '200.0000')
        ->assertJsonPath('data.totals.result', '200.0000');
});
