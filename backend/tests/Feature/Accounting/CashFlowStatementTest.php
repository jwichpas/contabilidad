<?php

declare(strict_types=1);

use App\Models\AccountingAccount;
use App\Models\CashFlowArea;
use App\Models\Company;
use App\Models\CompanySubdiary;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\Accounting\PostJournalEntryService;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

// DatabaseTransactions inherited from Pest.php. Run only against configured DEV.
beforeEach(function (): void {
    $this->tenant = app(TenantContext::class);
    $this->company = Company::query()->create([
        'tax_id' => '20'.random_int(100000000, 999999999), 'business_name' => 'DEMO flujo reversible',
        'legal_address' => 'Prueba', 'is_active' => true,
    ]);
    $this->user = User::query()->create([
        'name' => 'Flujo fixture', 'email' => 'flow_'.bin2hex(random_bytes(6)).'@example.test',
        'password' => 'Fixture-Only-2026!',
    ]);
    $this->tenant->run($this->company->id, function (): void {
        DB::table('company_user')->insert([
            'company_id' => $this->company->id, 'user_id' => $this->user->id, 'role' => 'ADMIN',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $usd = DB::table('currencies')->where('code', 'USD')->where('is_active', true)->first();
        DB::table('company_settings')->insert([
            'company_id' => $this->company->id, 'base_currency_id' => $usd->id,
            'tax_regime' => 'GENERAL', 'default_vat_rate' => '18.00',
        ]);
    });
    $this->user->update(['current_company_id' => $this->company->id]);
    Sanctum::actingAs($this->user, ['*']);
    $this->withHeader('X-Company-Id', (string) $this->company->id);
});

it('returns an exact empty USD statement without creating periods or journal entries', function (): void {
    $this->getJson('/api/v1/accounting/reports/cash-flow?from=2026-01-01&to=2026-02-28')
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.method', 'DIRECT')
        ->assertJsonPath('data.totals.opening_local', '0.0000')
        ->assertJsonPath('data.totals.difference_local', '0.0000')
        ->assertJsonCount(0, 'data.details.rows');
    $this->tenant->run($this->company->id, function (): void {
        expect(DB::table('journal_entries')->where('company_id', $this->company->id)->count())->toBe(0);
        expect(DB::table('fiscal_periods')->where('company_id', $this->company->id)->count())->toBe(0);
    });
});

it('reads persisted cash-side areas and preserves historical areas after reversal', function (): void {
    $posted = $this->tenant->run($this->company->id, function () {
        CompanySubdiary::query()->create(['code' => 'GENERAL', 'name' => 'General']);
        $create = static function (array $codes, bool $cash): AccountingAccount {
            $parent = null;
            foreach ($codes as $index => $code) {
                $parent = AccountingAccount::query()->firstOrCreate(['code' => $code], [
                    'name' => 'Fixture '.$code, 'parent_id' => $parent?->id, 'level' => $index + 1,
                    'path' => $parent === null ? $code : $parent->path.'.'.$code,
                    'account_type' => 'ASSET', 'balance_type' => 'DEBIT', 'currency_mode' => 'MULTI',
                    'is_operational' => $index === 5, 'is_active' => true,
                    'requires_auxiliary' => false, 'entity_requirement_type' => 'NONE',
                    'requires_cash_flow_area' => $cash && $index === 5,
                    'cash_flow_category' => $cash && $index === 5 ? 'CASH_EQUIVALENT' : 'NONE',
                    'financial_statement_rubric' => $cash ? 'ESF_ACT_CORR_EFE' : 'ESF_ACT_CORR_CXC_COM',
                ]);
            }
            return $parent;
        };
        $cash = $create(['1', '10', '104', '1041', '10410', '104101'], true);
        $customer = $create(['1', '12', '121', '1212', '12120', '121201'], false);
        $this->flow = CashFlowArea::query()->create([
            'code' => 'FLOW-TEST', 'name' => 'Cobros fixture', 'activity_category' => 'OPERATING',
            'flow_direction' => 'INFLOW', 'is_active' => true,
        ]);
        $period = FiscalPeriod::query()->create([
            'year' => 2026, 'month' => 2, 'start_date' => '2026-02-01', 'end_date' => '2026-02-28', 'status' => 'OPEN',
        ]);
        return app(PostJournalEntryService::class)->post([
            'fiscal_period_id' => $period->id, 'entry_date' => '2026-02-05', 'currency' => 'USD',
            'exchange_rate' => '1.000000', 'exchange_rate_type' => 'PARITY',
            'description' => 'Cobro reversible fixture', 'created_by_user_id' => $this->user->id,
            'lines' => [
                ['accounting_account_id' => $cash->id, 'cash_flow_area_id' => $this->flow->id,
                    'debit_local' => '25.0001', 'credit_local' => '0.0000', 'debit_foreign' => '25.0001', 'credit_foreign' => '0.0000'],
                ['accounting_account_id' => $customer->id,
                    'debit_local' => '0.0000', 'credit_local' => '25.0001', 'debit_foreign' => '0.0000', 'credit_foreign' => '25.0001'],
            ],
        ]);
    });
    $url = '/api/v1/accounting/reports/cash-flow?from=2026-02-01&to=2026-02-28';
    $this->getJson($url)->assertOk()
        ->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.totals.operating_local', '25.0001')
        ->assertJsonPath('data.totals.closing_local', '25.0001')->assertJsonPath('data.is_reconciled', true)
        ->assertJsonCount(1, 'data.details.rows')->assertJsonPath('data.details.rows.0.area_code', 'FLOW-TEST');
    $this->getJson($url.'&section=FINANCING')->assertOk()
        ->assertJsonPath('data.details.total', 0)->assertJsonPath('data.totals.operating_local', '25.0001');
    $this->tenant->run($this->company->id, fn () => $this->flow->update(['is_active' => false]));
    $this->postJson('/api/v1/accounting/journal-entries/'.$posted->id.'/reverse', [
        'fiscal_period_id' => $posted->fiscal_period_id, 'reversal_date' => '2026-02-06', 'reason' => 'Extorno fixture flujo',
    ])->assertCreated();
    $this->getJson($url)->assertOk()->assertJsonPath('data.totals.operating_local', '0.0000')
        ->assertJsonPath('data.totals.closing_local', '0.0000')->assertJsonPath('data.counts.UNCLASSIFIED', 0)
        ->assertJsonPath('data.groups.0.inflow_local', '0.0000')->assertJsonPath('data.groups.0.outflow_local', '0.0000')
        ->assertJsonCount(2, 'data.details.rows');
});

it('rejects invalid date filters and denies a foreign company without exposing data', function (): void {
    foreach (['from=2026-03-01&to=2026-02-28', 'from=2026-01-01&to=2026-02-28&section=UNKNOWN', 'from=2026-01-01&to=2026-02-28&page=0'] as $query) {
        $this->getJson('/api/v1/accounting/reports/cash-flow?'.$query)->assertUnprocessable();
    }
    $other = Company::query()->create([
        'tax_id' => '20'.random_int(100000000, 999999999), 'business_name' => 'DEMO ajena flujo',
        'legal_address' => 'Prueba', 'is_active' => true,
    ]);
    $this->getJson('/api/v1/accounting/reports/cash-flow?from=2026-01-01&to=2026-02-28', ['X-Company-Id' => (string) $other->id])->assertForbidden();
});

it('fails closed when functional currency is missing', function (): void {
    $incomplete = Company::query()->create([
        'tax_id' => '20'.random_int(100000000, 999999999), 'business_name' => 'DEMO sin configuración',
        'legal_address' => 'Prueba', 'is_active' => true,
    ]);
    $this->tenant->run($incomplete->id, function () use ($incomplete): void {
        DB::table('company_user')->insert([
            'company_id' => $incomplete->id, 'user_id' => $this->user->id, 'role' => 'ADMIN',
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });
    $this->getJson('/api/v1/accounting/reports/cash-flow?from=2026-01-01&to=2026-02-28', ['X-Company-Id' => (string) $incomplete->id])->assertConflict();
});
