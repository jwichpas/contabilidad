<?php

declare(strict_types=1);

use App\Models\AccountingAccount;
use App\Models\AccountingAccountTemplate;
use App\Models\Company;
use App\Models\CompanySubdiary;
use App\Models\CompanyUser;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\InitializeCompanyChartOfAccountsService;
use App\Support\Tenant\TenantContext;
use Database\Seeders\MasterChartOfAccountsSeeder;

beforeEach(function (): void {
    $this->tenant = app(TenantContext::class);
    $this->company = Company::query()->create([
        'tax_id' => '20'.random_int(100000000, 999999999),
        'business_name' => 'Documentos de prueba',
        'legal_address' => 'Dirección ficticia',
        'is_active' => true,
    ]);
    $this->user = User::query()->create([
        'name' => 'Tester documentos',
        'email' => 'document_'.bin2hex(random_bytes(6)).'@example.test',
        'password' => 'Test-Only-Password!',
    ]);
    $this->tenant->run($this->company->id, function (): void {
        CompanyUser::query()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'role' => 'ADMIN',
            'is_active' => true,
        ]);
        CompanySubdiary::query()->create(['code' => 'COMPRAS', 'name' => 'Compras']);
        CompanySubdiary::query()->create(['code' => 'VENTAS', 'name' => 'Ventas']);
    });
    $this->user->update(['current_company_id' => $this->company->id]);

    if (! AccountingAccountTemplate::query()->where('plan_version', 'PCGE_2019')->exists()) {
        app(MasterChartOfAccountsSeeder::class)->seedTemplates();
    }
    app(InitializeCompanyChartOfAccountsService::class)
        ->initialize((int) $this->company->id, 'PCGE_2019');

    $this->accounts = $this->tenant->run($this->company->id, function (): array {
        return AccountingAccount::query()
            ->operationalActive()->where('entity_requirement_type', 'NONE')
            ->where('requires_auxiliary', false)
            ->where('requires_cost_center', false)
            ->where('has_automatic_destination', false)
            ->where('requires_company_area', false)
            ->where('requires_cash_flow_area', false)
            ->orderBy('code')->limit(4)->get()->all();
    });
    expect($this->accounts)->toHaveCount(4);
    $this->actingAs($this->user)->withHeader('X-Company-Id', (string) $this->company->id);
    $this->entityId = (int) $this->postJson('/api/v1/company/entities', [
        'identity_document_type_code' => '6',
        'tax_id' => '20'.random_int(100000000, 999999999),
        'legal_name' => 'Proveedor Cliente de Prueba SAC',
        'country_code' => 'PER',
        'is_client' => true,
        'is_supplier' => true,
    ])->assertCreated()->json('data.id');
});

it('keeps purchase and sales parameters independent and rejects forged companies', function (): void {
    $settings = [
        'subdiary_code' => 'COMPRAS',
        'main_account_id' => $this->accounts[0]->id,
        'tax_account_id' => $this->accounts[2]->id,
        'settlement_account_id' => $this->accounts[1]->id,
        'default_tax_rate' => '18.0000',
    ];
    $this->getJson('/api/v1/accounting/document-journals/purchases/settings')
        ->assertOk()->assertJsonPath('data.main_account_id', null);

    $this->putJson('/api/v1/accounting/document-journals/purchases/settings', $settings)
        ->assertOk()->assertJsonPath('data.subdiary_code', 'COMPRAS');
    $this->getJson('/api/v1/accounting/document-journals/sales/settings')
        ->assertOk()->assertJsonPath('data.main_account_id', null);

    $this->putJson('/api/v1/accounting/document-journals/sales/settings', array_merge($settings, [
        'subdiary_code' => 'VENTAS',
    ]))->assertOk()->assertJsonPath('data.subdiary_code', 'VENTAS');

    $this->putJson('/api/v1/accounting/document-journals/purchases/settings', array_merge($settings, [
        'company_id' => 999999,
    ]))->assertUnprocessable()->assertJsonValidationErrors('company_id');

    $this->getJson('/api/v1/accounting/document-journals/purchases/settings')
        ->assertJsonPath('data.subdiary_code', 'COMPRAS');
});

it('posts one balanced journal per invoice and rejects a duplicate without new entries', function (): void {
    $this->putJson('/api/v1/accounting/document-journals/purchases/settings', [
        'subdiary_code' => 'COMPRAS',
        'main_account_id' => $this->accounts[0]->id,
        'tax_account_id' => $this->accounts[2]->id,
        'settlement_account_id' => $this->accounts[1]->id,
        'default_tax_rate' => '18.0000',
    ])->assertOk();

    $payload = [
        'document_type' => '01', 'series' => 'F001', 'number' => '00001',
        'entity_id' => $this->entityId,
        'issue_date' => '2026-11-12', 'entry_date' => '2026-11-12',
        'currency' => 'PEN', 'taxable_amount' => '100.00', 'tax_amount' => '18.00',
    ];
    $created = $this->postJson('/api/v1/accounting/document-journals/purchases/documents', $payload)
        ->assertCreated()->assertJsonPath('data.total_amount', '118.0000');
    $journalId = (int) $created->json('data.journal_entry_id');

    $this->tenant->run($this->company->id, function () use ($journalId): void {
        $entry = JournalEntry::query()->findOrFail($journalId);
        expect($entry->subdiary_code)->toBe('COMPRAS');
        expect($entry->lines)->toHaveCount(3);
    });
    $this->postJson('/api/v1/accounting/document-journals/purchases/documents', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('number');
    $this->getJson('/api/v1/accounting/document-journals/purchases/documents')
        ->assertOk()->assertJsonCount(1, 'data');
});

it('rejects unknown or unsupported document types and missing setup', function (): void {
    $this->postJson('/api/v1/accounting/document-journals/sales/documents', [
        'document_type' => '01', 'series' => 'F001', 'number' => '1',
        'entity_id' => $this->entityId,
        'issue_date' => '2026-11-12', 'entry_date' => '2026-11-12',
        'currency' => 'PEN', 'taxable_amount' => '100', 'tax_amount' => '0',
    ])->assertUnprocessable()->assertJsonValidationErrors('module');

    $this->postJson('/api/v1/accounting/document-journals/sales/documents', [
        'document_type' => '07', 'series' => 'FC01', 'number' => '1',
        'entity_id' => $this->entityId,
        'issue_date' => '2026-11-12', 'entry_date' => '2026-11-12',
        'currency' => 'PEN', 'taxable_amount' => '100', 'tax_amount' => '0',
    ])->assertUnprocessable()->assertJsonValidationErrors('document_type');
});


it('books taxed purchase receipts without fiscal credit and sales receipts with IGV', function (): void {
    $settings = [
        'main_account_id' => $this->accounts[0]->id,
        'tax_account_id' => $this->accounts[2]->id,
        'settlement_account_id' => $this->accounts[1]->id,
        'default_tax_rate' => '18.0000',
    ];
    $this->putJson('/api/v1/accounting/document-journals/purchases/settings',
        array_merge($settings, ['subdiary_code' => 'COMPRAS']))->assertOk();
    $this->putJson('/api/v1/accounting/document-journals/sales/settings',
        array_merge($settings, ['subdiary_code' => 'VENTAS']))->assertOk();

    $payload = [
        'document_type' => '03', 'series' => 'B001', 'number' => '00009',
        'entity_id' => $this->entityId,
        'issue_date' => '2026-11-12', 'entry_date' => '2026-11-12',
        'currency' => 'PEN', 'taxable_amount' => '100', 'tax_amount' => '18',
    ];
    $this->postJson('/api/v1/accounting/document-journals/purchases/documents', $payload)
        ->assertCreated()->assertJsonPath('data.tax_register.allocations.0.category', 'P_TAXED_NO_CREDIT')
        ->assertJsonPath('data.tax_register.allocations.0.creditable_tax_amount', '0.0000');
    // The same tax amount from a sale is posted to sales IGV, not purchase credit.
    $this->postJson('/api/v1/accounting/document-journals/sales/documents', $payload)
        ->assertCreated()->assertJsonPath('data.module', 'SALE');
});


it('keeps tax register separate and groups sales by taxable, exports and period', function (): void {
    $settings = [
        'subdiary_code' => 'VENTAS',
        'main_account_id' => $this->accounts[0]->id,
        'tax_account_id' => $this->accounts[2]->id,
        'settlement_account_id' => $this->accounts[1]->id,
        'default_tax_rate' => '18.0000',
        'category_account_map' => ['S_EXPORT' => $this->accounts[3]->id],
    ];
    $this->putJson('/api/v1/accounting/document-journals/sales/settings', $settings)
        ->assertOk()->assertJsonPath('data.category_account_map.S_EXPORT', $this->accounts[3]->id);
    $payload = [
        'document_type' => '01', 'series' => 'F002', 'number' => '00004',
        'entity_id' => $this->entityId,
        'issue_date' => '2026-10-31', 'entry_date' => '2026-11-12',
        'tax_period' => '202610',
        'currency' => 'PEN',
        'tax_allocations' => [
            ['category' => 'S_TAXED', 'base_amount' => '100.00', 'tax_amount' => '18.00'],
            ['category' => 'S_EXPORT', 'base_amount' => '250.00', 'tax_amount' => '0.00'],
        ],
    ];
    $response = $this->postJson('/api/v1/accounting/document-journals/sales/documents', $payload)
        ->assertCreated()
        ->assertJsonPath('data.total_amount', '368.0000')
        ->assertJsonPath('data.tax_register.tax_period', '202610')
        ->assertJsonPath('data.tax_register.reconciliation_status', 'PENDING_REVIEW')
        ->assertJsonCount(2, 'data.tax_register.allocations');
    $journalId = (int) $response->json('data.journal_entry_id');
    $this->tenant->run($this->company->id, function () use ($journalId): void {
        $entry = JournalEntry::query()->findOrFail($journalId);
        expect($entry->lines)->toHaveCount(4);
        expect($entry->subdiary_code)->toBe('VENTAS');
        expect((int) $entry->lines[0]->accounting_account_id)->toBe((int) $this->accounts[0]->id);
        expect((int) $entry->lines[1]->accounting_account_id)->toBe((int) $this->accounts[3]->id);
    });
    $this->getJson('/api/v1/accounting/document-journals/sales/tax-register?period=202610')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.document.journal_entry_id', $journalId);
    $this->getJson('/api/v1/accounting/document-journals/sales/tax-register/summary?period=202610')
        ->assertOk()->assertJsonCount(2, 'data.breakdown');
    $this->getJson('/api/v1/accounting/document-journals/purchases/tax-register?period=202610')
        ->assertOk()->assertJsonCount(0, 'data');

    // Un extorno no elimina el registro histórico, pero deja de integrar el resumen tributario activo.
    $periodId = $this->tenant->run($this->company->id,
        fn (): int => (int) JournalEntry::query()->findOrFail($journalId)->fiscal_period_id);
    $this->postJson('/api/v1/accounting/journal-entries/'.$journalId.'/reverse', [
        'fiscal_period_id' => $periodId,
        'reversal_date' => '2026-11-12',
        'reason' => 'Rectificación en pruebas del registro',
    ])->assertCreated();
    $this->getJson('/api/v1/accounting/document-journals/sales/tax-register/summary?period=202610')
        ->assertOk()->assertJsonCount(0, 'data.breakdown');
    $this->getJson('/api/v1/accounting/document-journals/sales/tax-register?period=202610')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.document.journal.status', 'REVERSED');
});

it('distinguishes non-taxed purchases from mixed purchases and never infers prorrata credit', function (): void {
    $this->putJson('/api/v1/accounting/document-journals/purchases/settings', [
        'subdiary_code' => 'COMPRAS',
        'main_account_id' => $this->accounts[0]->id,
        'tax_account_id' => $this->accounts[2]->id,
        'settlement_account_id' => $this->accounts[1]->id,
        'default_tax_rate' => '18.0000',
    ])->assertOk();
    $payload = [
        'document_type' => '01', 'series' => 'F010', 'number' => '00001',
        'entity_id' => $this->entityId,
        'issue_date' => '2026-11-12', 'entry_date' => '2026-11-12',
        'currency' => 'PEN', 'tax_period' => '202611',
        'tax_allocations' => [
            ['category' => 'P_TAXED_MIXED', 'base_amount' => '100', 'tax_amount' => '18'],
            ['category' => 'P_NON_TAXED', 'base_amount' => '40', 'tax_amount' => '0'],
        ],
    ];
    $this->postJson('/api/v1/accounting/document-journals/purchases/documents', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('tax_allocations.0.creditable_tax_amount');

    $payload['tax_allocations'][0]['creditable_tax_amount'] = '10.0000';
    $response = $this->postJson('/api/v1/accounting/document-journals/purchases/documents', $payload)
        ->assertCreated()
        ->assertJsonPath('data.total_amount', '158.0000')
        ->assertJsonPath('data.tax_register.allocations.0.creditable_tax_amount', '10.0000');
    $journalId = (int) $response->json('data.journal_entry_id');
    $this->tenant->run($this->company->id, function () use ($journalId): void {
        $lines = JournalEntry::query()->findOrFail($journalId)->lines;
        expect($lines)->toHaveCount(4);
        $sumDebit = $lines->reduce(fn ($sum, $line) => bcadd($sum, (string)$line->debit_local, 4), '0.0000');
        $sumCredit = $lines->reduce(fn ($sum, $line) => bcadd($sum, (string)$line->credit_local, 4), '0.0000');
        expect($sumDebit)->toBe($sumCredit);
    });
    $this->getJson('/api/v1/accounting/document-journals/purchases/tax-register/summary?period=202611')
        ->assertOk()->assertJsonCount(2, 'data.breakdown');
});

it('rejects zero tax on taxable sales, tax on export and incorrect module category', function (): void {
    $this->putJson('/api/v1/accounting/document-journals/sales/settings', [
        'subdiary_code' => 'VENTAS',
        'main_account_id' => $this->accounts[0]->id,
        'tax_account_id' => $this->accounts[2]->id,
        'settlement_account_id' => $this->accounts[1]->id,
        'default_tax_rate' => '18.0000',
    ])->assertOk();
    $payload = [
        'document_type' => '01', 'series' => 'F300', 'number' => '1',
        'entity_id' => $this->entityId,
        'issue_date' => '2026-11-12', 'entry_date' => '2026-11-12', 'currency' => 'PEN',
        'tax_allocations' => [['category' => 'S_TAXED', 'base_amount' => '100', 'tax_amount' => '0']],
    ];
    $this->postJson('/api/v1/accounting/document-journals/sales/documents', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('tax_allocations.0.tax_amount');
    $payload['tax_allocations'][0] = ['category' => 'S_EXPORT', 'base_amount' => '100', 'tax_amount' => '18'];
    $this->postJson('/api/v1/accounting/document-journals/sales/documents', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('tax_allocations.0.tax_amount');
    $payload['tax_allocations'][0] = ['category' => 'P_NON_TAXED', 'base_amount' => '100', 'tax_amount' => '0'];
    $this->postJson('/api/v1/accounting/document-journals/sales/documents', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('tax_allocations.0.category');
});
