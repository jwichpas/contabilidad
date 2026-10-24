<?php

declare(strict_types=1);

use App\Models\AccountingAccount;
use App\Models\Company;
use App\Models\CompanyArea;
use App\Models\CashFlowArea;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

// DatabaseTransactions inherited from Pest.php; no seeds or committed fixtures.
beforeEach(function (): void {
    $this->tenant = app(TenantContext::class);
    $this->user = User::query()->create(['name' => 'Journal area fixture',
        'email' => 'je_'.bin2hex(random_bytes(6)).'@example.test', 'password' => 'Fixture-Only-123!']);
    $this->company = Company::query()->create(['tax_id' => '20'.random_int(100000000, 999999999),
        'business_name' => 'Journal area fixture', 'legal_address' => 'Fixture', 'is_active' => true]);
    $this->tenant->run($this->company->id, function (): void {
        DB::table('company_user')->insert(['company_id' => $this->company->id, 'user_id' => $this->user->id,
            'role' => 'ADMIN', 'is_active' => DB::raw('TRUE'), 'created_at' => now(), 'updated_at' => now()]);
        $parent = null;
        foreach (['1', '12', '121', '1212', '12120', '121201'] as $i => $code) {
            $parent = AccountingAccount::query()->create(['code' => $code, 'name' => 'Fixture '.$code,
                'parent_id' => $parent?->id, 'level' => $i + 1,
                'path' => $parent === null ? $code : $parent->path.'.'.$code,
                'account_type' => 'ASSET', 'balance_type' => 'DEBIT', 'currency_mode' => 'PEN',
                'is_operational' => $i === 5, 'is_active' => true,
                'entity_requirement_type' => 'NONE', 'requires_auxiliary' => false,
                'requires_company_area' => $i === 5,
                'requires_cash_flow_area' => $i === 5,
                'cash_flow_category' => $i === 5 ? 'OPERATING' : 'NONE']);
        }
        \App\Models\CompanySubdiary::query()->create(['code' => 'GENERAL', 'name' => 'Diario general']);
        \App\Models\CompanySubdiary::query()->create(['code' => 'BANCOS', 'name' => 'Bancos']);
        $this->account = $parent;
        $this->area = CompanyArea::query()->create(['code' => 'TEST-ADM', 'name' => 'Área ficticia',
            'manager_name' => 'PRIVATE-FIXTURE', 'is_active' => true]);
        $this->flow = CashFlowArea::query()->create(['code' => 'TEST-OPE', 'name' => 'Flujo ficticio',
            'activity_category' => 'OPERATING', 'flow_direction' => 'BOTH', 'is_active' => true]);
    });
    $this->user->update(['current_company_id' => $this->company->id]);
    $this->actingAs($this->user)->withHeader('X-Company-Id', (string) $this->company->id);
    $line = ['accounting_account_id' => $this->account->id, 'company_area_id' => $this->area->id, 'cash_flow_area_id' => $this->flow->id,
        'debit_local' => '40.0000', 'credit_local' => '0.0000',
        'debit_foreign' => '10.0000', 'credit_foreign' => '0.0000'];
    $this->payload = ['entry_date' => '2026-10-24', 'currency' => 'PEN', 'exchange_rate' => '4.000000',
        'description' => 'Prueba reversible de áreas', 'lines' => [$line, array_replace($line,
            ['debit_local' => '0.0000', 'credit_local' => '40.0000', 'debit_foreign' => '0.0000', 'credit_foreign' => '10.0000'])]];
});


it('returns only the fields needed by journal area selectors', function (): void {
    $this->getJson('/api/v1/company/areas')->assertOk()
        ->assertJsonPath('data.0.id', $this->area->id)
        ->assertJsonPath('data.0.name', 'Área ficticia')
        ->assertJsonMissingPath('data.0.manager_name')
        ->assertJsonMissingPath('data.0.description')
        ->assertJsonMissingPath('data.0.uuid')
        ->assertJsonMissingPath('data.0.created_at');

    $this->getJson('/api/v1/company/cash-flow-areas')->assertOk()
        ->assertJsonPath('data.0.id', $this->flow->id)
        ->assertJsonPath('data.0.activity_category', 'OPERATING')
        ->assertJsonMissingPath('data.0.uuid')
        ->assertJsonMissingPath('data.0.created_at');
});

it('posts both areas and preserves idempotent replay with limited public fields', function (): void {
    $this->withHeader('Idempotency-Key', 'journal-area-'.bin2hex(random_bytes(10)));
    $first = $this->postJson('/api/v1/accounting/journal-entries', $this->payload)->assertCreated()
        ->assertJsonPath('data.lines.0.company_area_id', $this->area->id)
        ->assertJsonPath('data.lines.0.cash_flow_area_id', $this->flow->id)
        ->assertJsonPath('data.lines.0.company_area.name', 'Área ficticia')
        ->assertJsonMissingPath('data.lines.0.company_area.manager_name');
    $this->postJson('/api/v1/accounting/journal-entries', $this->payload)->assertOk()
        ->assertJsonPath('data.id', $first->json('data.id'));
    $changed = $this->payload;
    $changed['lines'][0]['company_area_id'] = null;
    $this->postJson('/api/v1/accounting/journal-entries', $changed)->assertConflict();
    $this->getJson('/api/v1/accounting/journal-entries')->assertOk()
        ->assertJsonPath('data.0.lines.0.cash_flow_area.code', 'TEST-OPE');
});

it('rejects missing inactive mismatched and foreign areas before creating a journal period or rate', function (): void {
    $missing = $this->payload;
    unset($missing['lines'][0]['cash_flow_area_id']);
    $this->postJson('/api/v1/accounting/journal-entries', $missing)->assertUnprocessable();
    $this->tenant->run($this->company->id, fn () => $this->flow->update(['activity_category' => 'INVESTING']));
    $this->postJson('/api/v1/accounting/journal-entries', $this->payload)->assertUnprocessable();
    $this->tenant->run($this->company->id, fn () => $this->flow->update(['activity_category' => 'OPERATING', 'is_active' => false]));
    $this->postJson('/api/v1/accounting/journal-entries', $this->payload)->assertUnprocessable();
    $this->tenant->run($this->company->id, function (): void {
        $this->flow->update(['is_active' => true]);
        $this->area->update(['is_active' => false]);
    });
    $this->postJson('/api/v1/accounting/journal-entries', $this->payload)->assertUnprocessable();
    $this->tenant->run($this->company->id, fn () => $this->area->update(['is_active' => true]));
    $other = Company::query()->create(['tax_id' => '20'.random_int(100000000, 999999999),
        'business_name' => 'Other fixture', 'legal_address' => 'Fixture', 'is_active' => true]);
    $foreign = $this->tenant->run($other->id, fn () => [
        'company_area_id' => CompanyArea::query()->create(['code' => 'OTHER', 'name' => 'Other', 'is_active' => true])->id,
        'cash_flow_area_id' => CashFlowArea::query()->create(['code' => 'OTHER', 'name' => 'Other',
            'activity_category' => 'OPERATING', 'flow_direction' => 'BOTH', 'is_active' => true])->id,
    ]);
    foreach ($foreign as $field => $id) {
        $invalid = $this->payload;
        $invalid['lines'][0][$field] = $id;
        $this->postJson('/api/v1/accounting/journal-entries', $invalid)->assertUnprocessable();
    }
    $this->tenant->run($this->company->id, function (): void {
        expect(JournalEntry::query()->count())->toBe(0);
        expect(DB::table('fiscal_periods')->where('company_id', $this->company->id)->count())->toBe(0);
        expect(DB::table('exchange_rates')->where('company_id', $this->company->id)->count())->toBe(0);
    });
});

it('copies inactive historical area references on reversal', function (): void {
    $first = $this->postJson('/api/v1/accounting/journal-entries', $this->payload)->assertCreated();
    $this->tenant->run($this->company->id, function (): void {
        $this->area->update(['is_active' => false]);
        $this->flow->update(['is_active' => false]);
    });
    $this->postJson('/api/v1/accounting/journal-entries/'.$first->json('data.id').'/reverse', [
        'fiscal_period_id' => $first->json('data.fiscal_period_id'), 'reversal_date' => '2026-10-24',
        'reason' => 'Extorno ficticio de áreas',
    ])->assertCreated()->assertJsonPath('data.lines.0.company_area_id', $this->area->id)
        ->assertJsonPath('data.lines.0.cash_flow_area_id', $this->flow->id)
        ->assertJsonPath('data.lines.0.credit_local', '40.0000');
    $this->tenant->run($this->company->id, function () use ($first): void {
        $original = JournalEntry::query()->with('lines')->findOrFail($first->json('data.id'));
        expect($original->lines[0]->company_area_id)->toBe($this->area->id)
            ->and($original->lines[0]->cash_flow_area_id)->toBe($this->flow->id)
            ->and((string) $original->lines[0]->debit_local)->toBe('40.0000');
    });
});

it('omits areas when the account does not configure them and exposes current requirements', function (): void {
    $this->getJson('/api/v1/accounting/accounts/operational')->assertOk()
        ->assertJsonPath('data.0.cash_flow_category', 'OPERATING')
        ->assertJsonPath('data.0.requires_company_area', true);
    $this->tenant->run($this->company->id, fn () => $this->account->update([
        'requires_company_area' => false, 'requires_cash_flow_area' => false, 'cash_flow_category' => 'NONE',
    ]));
    $optional = $this->payload;
    foreach ($optional['lines'] as &$line) {
        unset($line['company_area_id'], $line['cash_flow_area_id']);
    }
    unset($line);
    $this->postJson('/api/v1/accounting/journal-entries', $optional)->assertCreated()
        ->assertJsonPath('data.lines.0.company_area_id', null)
        ->assertJsonPath('data.lines.0.cash_flow_area_id', null);
});

it('rejects dimensions when the account does not configure them', function (): void {
    $this->tenant->run($this->company->id, fn () => $this->account->update([
        'requires_company_area' => false, 'requires_cash_flow_area' => false, 'cash_flow_category' => 'NONE',
    ]));
    foreach (['company_area_id', 'cash_flow_area_id', 'cost_center_id'] as $field) {
        $invalid = $this->payload;
        foreach ($invalid['lines'] as &$line) {
            unset($line['company_area_id'], $line['cash_flow_area_id']);
        }
        unset($line);
        $invalid['lines'][0][$field] = $field === 'cost_center_id' ? 123456 : $this->payload['lines'][0][$field];
        $this->postJson('/api/v1/accounting/journal-entries', $invalid)->assertUnprocessable();
    }
    $this->tenant->run($this->company->id, function (): void {
        expect(JournalEntry::query()->count())->toBe(0);
        expect(DB::table('fiscal_periods')->where('company_id', $this->company->id)->count())->toBe(0);
        expect(DB::table('exchange_rates')->where('company_id', $this->company->id)->count())->toBe(0);
    });
});

it('persists the selected subdiary and copies it to the reversal without editing the original', function (): void {
    $payload = $this->payload;
    $payload['subdiary_code'] = 'BANCOS';
    $entry = $this->postJson('/api/v1/accounting/journal-entries', $payload)->assertCreated()
        ->assertJsonPath('data.subdiary_code', 'BANCOS');
    $entryId = (int) $entry->json('data.id');
    $this->getJson('/api/v1/accounting/journal-entries')->assertOk()
        ->assertJsonPath('data.0.subdiary_code', 'BANCOS');
    $this->postJson('/api/v1/accounting/journal-entries/'.$entryId.'/reverse', [
        'fiscal_period_id' => $entry->json('data.fiscal_period_id'),
        'reversal_date' => '2026-10-24',
        'reason' => 'Extorno ficticio de subdiario',
    ])->assertCreated()->assertJsonPath('data.subdiary_code', 'BANCOS');
    $this->tenant->run($this->company->id, function () use ($entryId): void {
        expect(JournalEntry::query()->findOrFail($entryId)->subdiary_code)->toBe('BANCOS');
    });
});

it('rejects an invalid subdiary before opening periods or creating journal data', function (): void {
    $payload = $this->payload;
    $payload['subdiary_code'] = 'SUBDIARIO_INEXISTENTE';
    $this->postJson('/api/v1/accounting/journal-entries', $payload)->assertUnprocessable();
    $this->tenant->run($this->company->id, function (): void {
        expect(JournalEntry::query()->count())->toBe(0);
        expect(DB::table('fiscal_periods')->where('company_id', $this->company->id)->count())->toBe(0);
    });
});

it('loads journal editor catalogs and subdiaries in a single read-only request', function (): void {
    $response = $this->getJson('/api/v1/accounting/journal-entries/editor-bootstrap?date=2026-10-24')
        ->assertOk()
        ->assertJsonPath('data.accounts.0.id', $this->account->id)
        ->assertJsonMissingPath('data.accounts.0.uuid')
        ->assertJsonMissingPath('data.accounts.0.level')
        ->assertJsonMissingPath('data.accounts.0.account_type')
        ->assertJsonMissingPath('data.accounts.0.balance_type')
        ->assertJsonMissingPath('data.accounts.0.currency_mode')
        ->assertJsonPath('data.company_areas.0.id', $this->area->id)
        ->assertJsonPath('data.cash_flow_areas.0.id', $this->flow->id)
        ->assertJsonFragment(['code' => 'GENERAL', 'name' => 'Diario general'])
        ->assertJsonPath('data.next_number.period_month', 10)
        ->assertJsonPath('data.exchange_rates.rate_date', '2026-10-24');
    $timing = (string) $response->headers->get('Server-Timing');
    expect($timing)
        ->toContain('accounts;dur=')
        ->toContain('schema_accounts;dur=')
        ->toContain('schema_subdiaries;dur=')
        ->toContain('subdiaries;dur=')
        ->toContain('controller_total;dur=');
    $this->tenant->run($this->company->id, function (): void {
        expect(JournalEntry::query()->count())->toBe(0);
        expect(DB::table('fiscal_periods')->where('company_id', $this->company->id)->count())->toBe(0);
    });
});

it('rejects new postings to an inactive subdiary but preserves reversal classification', function (): void {
    $payload = $this->payload;
    $payload['subdiary_code'] = 'BANCOS';
    $posted = $this->postJson('/api/v1/accounting/journal-entries', $payload)->assertCreated();
    $this->tenant->run($this->company->id, fn () =>
        \App\Models\CompanySubdiary::query()->where('code', 'BANCOS')->update(['is_active' => false])
    );
    $this->postJson('/api/v1/accounting/journal-entries', $payload)->assertUnprocessable();
    $this->postJson('/api/v1/accounting/journal-entries/'.$posted->json('data.id').'/reverse', [
        'fiscal_period_id' => $posted->json('data.fiscal_period_id'),
        'reversal_date' => '2026-10-24',
        'reason' => 'Extorno de subdiario ya inactivo',
    ])->assertCreated()->assertJsonPath('data.subdiary_code', 'BANCOS');
});
