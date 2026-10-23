<?php

declare(strict_types=1);

use App\Models\AccountingAccount;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\Accounting\PostJournalEntryService;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

// tests/Pest.php wraps every Feature test in DatabaseTransactions.
// No migrations, owner connection, committed fixtures or matrix promotion.
beforeEach(function (): void {
    $nonce = bin2hex(random_bytes(5));
    $this->tenant = app(TenantContext::class);
    $this->admin = User::query()->create([
        'name' => 'Cost center test admin', 'email' => 'cc_'.$nonce.'@example.test',
        'password' => 'Fictitious-Test-Only-123!',
    ]);
    foreach (['A', 'B'] as $label) {
        $company = Company::query()->create([
            'tax_id' => '20'.random_int(100000000, 999999999),
            'business_name' => 'Cost center fixture '.$label.' '.$nonce,
            'legal_address' => 'Fixture', 'is_active' => true,
        ]);
        $this->{'company'.$label} = $company;
        $this->{'accounts'.$label} = $this->tenant->run($company->id, function () use ($company, $label): array {
            DB::table('company_user')->insert([
                'company_id' => $company->id, 'user_id' => $this->admin->id,
                'role' => $label === 'A' ? 'ADMIN' : 'CHIEF_ACCOUNTANT',
                'is_active' => DB::raw('TRUE'), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $accounts = [];
            foreach (['941100', '951100', '791000'] as $leaf) {
                $parent = null;
                for ($level = 1; $level <= 6; ++$level) {
                    $code = substr($leaf, 0, $level);
                    $accounts[$code] ??= AccountingAccount::query()->create([
                        'code' => $code, 'name' => 'Fixture '.$code, 'parent_id' => $parent?->id,
                        'level' => $level, 'path' => $parent === null ? $code : $parent->path.'.'.$code,
                        'account_type' => str_starts_with($code, '9') ? 'COST' : 'REVENUE',
                        'balance_type' => str_starts_with($code, '9') ? 'DEBIT' : 'CREDIT',
                        'currency_mode' => 'PEN', 'is_operational' => $level === 6, 'is_active' => true,
                    ]);
                    $parent = $accounts[$code];
                }
            }

            return $accounts;
        });
    }
    $this->admin->update(['current_company_id' => $this->companyA->id]);
    $this->payload = [
        'code' => 'CC-LOCAL', 'name' => 'Centro ficticio',
        'destination_debit_account_id' => $this->accountsA['941100']->id,
        'destination_credit_account_id' => $this->accountsA['791000']->id,
    ];
    $this->actingAs($this->admin)->withHeader('X-Company-Id', (string) $this->companyA->id);
});

it('creates a normalized center with audit and preserves the journal dropdown contract', function (): void {
    $created = $this->postJson('/api/v1/company/cost-centers', array_replace($this->payload, [
        'code' => ' cc-local ', 'name' => ' Centro ficticio ',
    ]))->assertCreated()->assertJsonPath('data.code', 'CC-LOCAL')
        ->assertJsonPath('data.name', 'Centro ficticio')
        ->assertJsonPath('data.destination_debit_account.code', '941100');
    $id = (int) $created->json('data.id');
    $this->getJson('/api/v1/company/cost-centers')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $id)->assertJsonMissingPath('meta');
    $this->getJson('/api/v1/company/cost-centers/manage?search=LOCAL&per_page=1')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
    $this->getJson('/api/v1/company/cost-centers/'.$id)->assertOk()->assertJsonPath('data.id', $id);
    $this->tenant->run($this->companyA->id, function () use ($id): void {
        $audit = AuditLog::query()->where('company_id', $this->companyA->id)
            ->where('auditable_type', CostCenter::class)->where('auditable_id', (string) $id)->firstOrFail();
        expect($audit->event_type)->toBe('COST_CENTER_CREATED')
            ->and($audit->old_values)->toBeNull()->and($audit->new_values['code'])->toBe('CC-LOCAL');
    });
});

it('rejects duplicate tenant codes but lets another company use the same code', function (): void {
    $this->postJson('/api/v1/company/cost-centers', $this->payload)->assertCreated();
    $this->postJson('/api/v1/company/cost-centers', $this->payload)
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    // The failed UNIQUE insert must not poison the surrounding transaction.
    $this->getJson('/api/v1/company/cost-centers/manage')->assertOk()->assertJsonPath('meta.total', 1);
    $this->withHeader('X-Company-Id', (string) $this->companyB->id);
    $this->postJson('/api/v1/company/cost-centers', array_replace($this->payload, [
        'destination_debit_account_id' => $this->accountsB['941100']->id,
        'destination_credit_account_id' => $this->accountsB['791000']->id,
    ]))->assertCreated(); // CHIEF_ACCOUNTANT in B.
});

it('rejects tenant injection and invalid destination accounts without creating centers', function (): void {
    foreach (['company_id' => $this->companyB->id, 'id' => 10, 'uuid' => 'injected', 'is_active' => false] as $field => $value) {
        $this->postJson('/api/v1/company/cost-centers', array_replace($this->payload, [$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    foreach ([$this->accountsB['941100']->id, $this->accountsA['94']->id, $this->accountsA['791000']->id] as $id) {
        $this->postJson('/api/v1/company/cost-centers', array_replace($this->payload, [
            'destination_debit_account_id' => $id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('destination_debit_account_id');
    }
    $this->postJson('/api/v1/company/cost-centers', array_replace($this->payload, [
        'destination_credit_account_id' => $this->accountsB['791000']->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('destination_credit_account_id');
    $this->tenant->run($this->companyA->id, fn () => $this->accountsA['941100']->update(['is_active' => false]));
    $this->postJson('/api/v1/company/cost-centers', $this->payload)
        ->assertUnprocessable()->assertJsonValidationErrors('destination_debit_account_id');
    $this->getJson('/api/v1/company/cost-centers/manage')->assertOk()->assertJsonPath('meta.total', 0);
});

it('edits an unused center and deactivates it once without deleting its history', function (): void {
    $id = (int) $this->postJson('/api/v1/company/cost-centers', $this->payload)->assertCreated()->json('data.id');
    $this->putJson('/api/v1/company/cost-centers/'.$id, [
        'name' => 'Centro modificado', 'destination_debit_account_id' => $this->accountsA['951100']->id,
    ])->assertOk()->assertJsonPath('data.destination_debit_account.code', '951100');
    $this->putJson('/api/v1/company/cost-centers/'.$id, ['name' => 'Centro modificado'])->assertOk();
    $this->patchJson('/api/v1/company/cost-centers/'.$id.'/deactivate')->assertOk()->assertJsonPath('data.is_active', false);
    $this->patchJson('/api/v1/company/cost-centers/'.$id.'/deactivate')->assertOk();
    $this->getJson('/api/v1/company/cost-centers')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/company/cost-centers/manage?status=inactive')->assertOk()->assertJsonPath('meta.total', 1);
    $this->putJson('/api/v1/company/cost-centers/'.$id, ['name' => 'No permitido'])->assertUnprocessable();
    $this->tenant->run($this->companyA->id, function () use ($id): void {
        expect(CostCenter::query()->find($id))->not->toBeNull();
        $events = AuditLog::query()->where('company_id', $this->companyA->id)
            ->where('auditable_type', CostCenter::class)->where('auditable_id', (string) $id)
            ->orderBy('id')->pluck('event_type')->all();
        expect($events)->toBe(['COST_CENTER_CREATED', 'COST_CENTER_UPDATED', 'COST_CENTER_DEACTIVATED']);
    });
});

it('enforces tenant isolation even for writers with two valid memberships', function (): void {
    $id = (int) $this->postJson('/api/v1/company/cost-centers', $this->payload)->assertCreated()->json('data.id');
    $this->withHeader('X-Company-Id', (string) $this->companyB->id);
    $this->getJson('/api/v1/company/cost-centers/manage?status=all')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/company/cost-centers/'.$id)->assertNotFound();
    $this->putJson('/api/v1/company/cost-centers/'.$id, ['name' => 'Cross tenant'])->assertNotFound();
    $this->patchJson('/api/v1/company/cost-centers/'.$id.'/deactivate')->assertNotFound();
});

it('allows read-only roles to consult but rejects every write', function (string $role): void {
    $id = (int) $this->postJson('/api/v1/company/cost-centers', $this->payload)->assertCreated()->json('data.id');
    $this->tenant->run($this->companyA->id, function () use ($role): void {
        DB::table('company_user')->where('company_id', $this->companyA->id)
            ->where('user_id', $this->admin->id)->update(['role' => $role]);
    });
    $this->getJson('/api/v1/company/cost-centers/manage?status=all')->assertOk();
    $this->getJson('/api/v1/company/cost-centers/'.$id)->assertOk();
    $this->postJson('/api/v1/company/cost-centers', $this->payload)->assertForbidden();
    $this->putJson('/api/v1/company/cost-centers/'.$id, ['name' => 'No autorizado'])->assertForbidden();
    $this->patchJson('/api/v1/company/cost-centers/'.$id.'/deactivate')->assertForbidden();
})->with(['ACCOUNTANT', 'AUDITOR']);

it('rolls back the center if obligatory auditing fails', function (): void {
    $dispatcher = AuditLog::getEventDispatcher();
    $isolated = clone $dispatcher;
    $isolated->listen('eloquent.creating: '.AuditLog::class, function (AuditLog $log): void {
        if ($log->event_type === 'COST_CENTER_CREATED') {
            throw new RuntimeException('Simulated audit failure');
        }
    });
    AuditLog::setEventDispatcher($isolated);
    $this->withoutExceptionHandling();
    try {
        expect(fn () => $this->postJson('/api/v1/company/cost-centers', $this->payload))
            ->toThrow(RuntimeException::class, 'Simulated audit failure');
    } finally {
        AuditLog::setEventDispatcher($dispatcher);
        $this->withExceptionHandling();
    }
    $this->getJson('/api/v1/company/cost-centers/manage')->assertOk()->assertJsonPath('meta.total', 0);
});

it('preserves destinations already used by journal lines and allows historical consultation after deactivation', function (): void {
    $id = (int) $this->postJson('/api/v1/company/cost-centers', $this->payload)->assertCreated()->json('data.id');
    $entryId = $this->tenant->run($this->companyA->id, function () use ($id): int {
        $period = FiscalPeriod::query()->create([
            'year' => 2026, 'month' => 10, 'start_date' => '2026-10-01',
            'end_date' => '2026-10-31', 'status' => 'OPEN',
        ]);
        $entry = app(PostJournalEntryService::class)->post([
            'fiscal_period_id' => $period->id, 'entry_date' => '2026-10-23',
            'currency' => 'PEN', 'exchange_rate' => '4.000000', 'exchange_rate_type' => 'SELL',
            'description' => 'Fixture centro de costo', 'created_by_user_id' => $this->admin->id,
            'lines' => [
                ['accounting_account_id' => $this->accountsA['941100']->id, 'cost_center_id' => $id,
                 'debit_local' => '40.0000', 'credit_local' => '0.0000', 'debit_foreign' => '10.0000', 'credit_foreign' => '0.0000'],
                ['accounting_account_id' => $this->accountsA['791000']->id,
                 'debit_local' => '0.0000', 'credit_local' => '40.0000', 'debit_foreign' => '0.0000', 'credit_foreign' => '10.0000'],
            ],
        ]);

        return (int) $entry->id;
    });
    $this->putJson('/api/v1/company/cost-centers/'.$id, [
        'destination_debit_account_id' => $this->accountsA['951100']->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('destination_debit_account_id');
    $this->putJson('/api/v1/company/cost-centers/'.$id, ['name' => 'Nombre auditado'])->assertOk();
    $this->patchJson('/api/v1/company/cost-centers/'.$id.'/deactivate')->assertOk();
    $this->tenant->run($this->companyA->id, function () use ($entryId, $id): void {
        $line = DB::table('journal_entry_lines')->where('company_id', $this->companyA->id)
            ->where('journal_entry_id', $entryId)->where('cost_center_id', $id)->first();
        expect($line)->not->toBeNull()->and((string) $line->debit_local)->toBe('40.0000');
    });
});

it('refuses destination changes and deactivation for the existing DEMO draft matrix', function (): void {
    // Read existing matrix only: app_backend deliberately has no INSERT on routes.
    // Missing fixture is an explicit skip, never reported as matrix protection PASS.
    $demo = Company::query()->where('tax_id', '20999990011')->first();
    if ($demo === null) {
        $this->markTestSkipped('Requires the existing DEMO A matrix fixture.');
    }
    $fixture = $this->tenant->run($demo->id, function () use ($demo): ?array {
        $route = DB::table('accounting_destination_routes as r')
            ->join('accounting_destination_rule_versions as v', function ($join): void {
                $join->on('v.company_id', '=', 'r.company_id')->on('v.id', '=', 'r.rule_version_id');
            })->where('r.company_id', $demo->id)->whereIn('v.status', ['DRAFT', 'ACTIVE'])
            ->first(['r.cost_center_id']);
        if ($route === null) {
            return null;
        }
        DB::table('company_user')->insert([
            'company_id' => $demo->id, 'user_id' => $this->admin->id, 'role' => 'ADMIN',
            'is_active' => DB::raw('TRUE'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $center = CostCenter::query()->findOrFail($route->cost_center_id);
        $other = AccountingAccount::query()->whereIn('code', ['941100', '951100'])
            ->where('id', '<>', $center->destination_debit_account_id)->firstOrFail();

        return ['id' => (int) $center->id, 'debit' => (int) $center->destination_debit_account_id,
            'other' => (int) $other->id, 'active' => (bool) $center->is_active];
    });
    if ($fixture === null) {
        $this->markTestSkipped('Requires a DRAFT or ACTIVE route in DEMO A.');
    }
    $this->withHeader('X-Company-Id', (string) $demo->id);
    $this->putJson('/api/v1/company/cost-centers/'.$fixture['id'], [
        'destination_debit_account_id' => $fixture['other'],
    ])->assertUnprocessable()->assertJsonValidationErrors('destination_debit_account_id');
    $this->patchJson('/api/v1/company/cost-centers/'.$fixture['id'].'/deactivate')
        ->assertUnprocessable()->assertJsonValidationErrors('cost_center');
    $this->getJson('/api/v1/company/cost-centers/'.$fixture['id'])->assertOk()
        ->assertJsonPath('data.destination_debit_account_id', $fixture['debit'])
        ->assertJsonPath('data.is_active', $fixture['active']);
});
