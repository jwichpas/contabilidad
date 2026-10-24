<?php

declare(strict_types=1);

use App\Models\AccountingAccount;
use App\Models\Company;
use App\Models\Entity;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

// DatabaseTransactions inherited from Pest.php; no seeds or committed fixtures.
beforeEach(function (): void {
    $this->tenant = app(TenantContext::class);
    $this->user = User::query()->create(['name' => 'Journal entity fixture',
        'email' => 'je_'.bin2hex(random_bytes(6)).'@example.test', 'password' => 'Fixture-Only-123!']);
    $this->company = Company::query()->create(['tax_id' => '20'.random_int(100000000, 999999999),
        'business_name' => 'Journal entity fixture', 'legal_address' => 'Fixture', 'is_active' => true]);
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
                'entity_requirement_type' => $i === 5 ? 'CUSTOMER' : 'NONE',
                'requires_auxiliary' => $i === 5]);
        }
        \App\Models\CompanySubdiary::query()->create(['code' => 'GENERAL', 'name' => 'Diario general']);
        $this->account = $parent;
        $this->entity = Entity::query()->create(['identity_document_type_code' => '1', 'tax_id' => '12345678',
            'legal_name' => 'Cliente ficticio', 'country_code' => 'PE', 'is_client' => true, 'is_active' => true,
            'bank_account_number' => 'PRIVATE-FIXTURE']);
    });
    $this->user->update(['current_company_id' => $this->company->id]);
    $this->actingAs($this->user)->withHeader('X-Company-Id', (string) $this->company->id);
    $line = ['accounting_account_id' => $this->account->id, 'entity_id' => $this->entity->id,
        'debit_local' => '40.0000', 'credit_local' => '0.0000',
        'debit_foreign' => '10.0000', 'credit_foreign' => '0.0000'];
    $this->payload = ['entry_date' => '2026-10-24', 'currency' => 'PEN', 'exchange_rate' => '4.000000',
        'description' => 'Prueba reversible de dimensión anexo', 'lines' => [$line, array_replace($line,
            ['debit_local' => '0.0000', 'credit_local' => '40.0000', 'debit_foreign' => '0.0000', 'credit_foreign' => '10.0000'])]];
});

it('persists the entity and replays idempotently without exposing bank details', function (): void {
    $this->withHeader('Idempotency-Key', 'journal-entity-'.bin2hex(random_bytes(10)));
    $first = $this->postJson('/api/v1/accounting/journal-entries', $this->payload)->assertCreated()
        ->assertJsonPath('data.lines.0.entity_id', $this->entity->id)
        ->assertJsonPath('data.lines.0.entity.legal_name', 'Cliente ficticio')
        ->assertJsonMissingPath('data.lines.0.entity.bank_account_number');
    $this->postJson('/api/v1/accounting/journal-entries', $this->payload)->assertOk()
        ->assertJsonPath('data.id', $first->json('data.id'));
    $this->getJson('/api/v1/accounting/journal-entries')->assertOk()
        ->assertJsonPath('data.0.lines.0.entity_id', $this->entity->id)
        ->assertJsonMissingPath('data.0.lines.0.entity.bank_account_number');
    $changed = $this->payload;
    $changed['lines'][0]['entity_id'] = null;
    $this->postJson('/api/v1/accounting/journal-entries', $changed)->assertConflict();
});

it('rejects missing mismatched inactive and foreign entities without posting a journal', function (): void {
    $missing = $this->payload;
    unset($missing['lines'][0]['entity_id']);
    $this->postJson('/api/v1/accounting/journal-entries', $missing)->assertUnprocessable();
    $this->tenant->run($this->company->id, fn () => $this->entity->update(['is_client' => false, 'is_supplier' => true]));
    $this->postJson('/api/v1/accounting/journal-entries', $this->payload)->assertUnprocessable();
    $this->tenant->run($this->company->id, fn () => $this->entity->update(['is_client' => true, 'is_active' => false]));
    $this->postJson('/api/v1/accounting/journal-entries', $this->payload)->assertUnprocessable();
    $other = Company::query()->create(['tax_id' => '20'.random_int(100000000, 999999999),
        'business_name' => 'Other fixture', 'legal_address' => 'Fixture', 'is_active' => true]);
    $foreign = $this->tenant->run($other->id, fn () => Entity::query()->create([
        'identity_document_type_code' => '1', 'tax_id' => '12345678', 'legal_name' => 'Other entity',
        'country_code' => 'PE', 'is_client' => true, 'is_active' => true]));
    $invalid = $this->payload;
    $invalid['lines'][0]['entity_id'] = $foreign->id;
    $this->postJson('/api/v1/accounting/journal-entries', $invalid)->assertUnprocessable();
    $this->tenant->run($this->company->id, function (): void {
        expect(JournalEntry::query()->count())->toBe(0);
        expect(DB::table('fiscal_periods')->where('company_id', $this->company->id)->count())->toBe(0);
        expect(DB::table('exchange_rates')->where('company_id', $this->company->id)->count())->toBe(0);
    });
});

it('copies an inactive historical entity on reversal without rewriting original lines', function (): void {
    $first = $this->postJson('/api/v1/accounting/journal-entries', $this->payload)->assertCreated();
    $this->tenant->run($this->company->id, fn () => $this->entity->update(['is_active' => false]));
    $this->postJson('/api/v1/accounting/journal-entries/'.$first->json('data.id').'/reverse', [
        'fiscal_period_id' => $first->json('data.fiscal_period_id'), 'reversal_date' => '2026-10-24',
        'reason' => 'Extorno ficticio para prueba',
    ])->assertCreated()->assertJsonPath('data.lines.0.entity_id', $this->entity->id)
        ->assertJsonPath('data.lines.0.credit_local', '40.0000');
    $this->tenant->run($this->company->id, function () use ($first): void {
        $original = JournalEntry::query()->with('lines')->findOrFail($first->json('data.id'));
        expect($original->lines[0]->entity_id)->toBe($this->entity->id)
            ->and((string) $original->lines[0]->debit_local)->toBe('40.0000');
    });
});

it('omits anexo on accounts without a configured requirement and reads updated rules without stale cache', function (): void {
    $this->getJson('/api/v1/accounting/accounts/operational')->assertOk()
        ->assertJsonPath('data.0.entity_requirement_type', 'CUSTOMER');
    $this->tenant->run($this->company->id, fn () => $this->account->update([
        'entity_requirement_type' => 'NONE', 'requires_auxiliary' => false,
    ]));
    $this->getJson('/api/v1/accounting/accounts/operational')->assertOk()
        ->assertJsonPath('data.0.entity_requirement_type', 'NONE');
    $this->postJson('/api/v1/accounting/journal-entries', $this->payload)->assertUnprocessable();
    $optional = $this->payload;
    foreach ($optional['lines'] as &$line) {
        unset($line['entity_id']);
    }
    unset($line);
    $this->postJson('/api/v1/accounting/journal-entries', $optional)->assertCreated()
        ->assertJsonPath('data.lines.0.entity_id', null);
});
