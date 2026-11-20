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
    $this->seedMonthlyConfirmation((int) $this->company->id, (int) $this->user->id, '2026-11');
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
        ->assertJsonPath('data.total_amount', '158.0000');
    $allocations = collect($response->json('data.tax_register.allocations'))->keyBy('category');
    expect($allocations['P_TAXED_MIXED']['creditable_tax_amount'])->toBe('10.0000');
    expect($allocations['P_NON_TAXED']['creditable_tax_amount'])->toBe('0.0000');
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

it('reviews DAOT by third party and direction and uses the March deadline', function (): void {
    $this->company->update(['tax_id' => substr($this->company->tax_id, 0, -1).'4']);
    $url = '/api/v1/accounting/daot/2026';
    $calendar = '/api/v1/company/sunat-calendar/2026';
    $profile = $this->getJson($calendar)->assertOk()->json('data.profile');
    $profile['daot_status'] = 'REQUIRED';
    $profile['daot_reference'] = 'Condición verificada en prueba';
    $this->putJson($calendar, ['expected_revision' => 0, 'profile' => $profile])->assertOk();
    $events = collect($this->getJson($calendar)->assertOk()->json('data.events'))->firstWhere('code', 'DAOT');
    expect($events['date'])->toBe('2027-03-04');
    $this->getJson('/api/v1/accounting/daot/2025')->assertOk()->assertJsonPath('data.due', '2026-03-05');
    $this->getJson($url)->assertOk()->assertJsonPath('data.threshold', 11000)->assertJsonPath('data.status', 'REQUIRED');

    $settings = ['main_account_id' => $this->accounts[0]->id, 'settlement_account_id' => $this->accounts[1]->id,
        'tax_account_id' => $this->accounts[2]->id, 'default_tax_rate' => '18'];
    $payload = ['document_type' => '01', 'series' => '001', 'number' => '2', 'entity_id' => $this->entityId,
        'issue_date' => '2026-11-12', 'entry_date' => '2026-11-12', 'currency' => 'PEN', 'tax_period' => '202611'];
    foreach ([
        ['purchases', 'COMPRAS', 'P_TAXED_EXCLUSIVE', '11000', '1980'],
        ['sales', 'VENTAS', 'S_TAXED', '15000', '2700'],
    ] as [$module, $subdiary, $category, $base, $tax]) {
        $path = '/api/v1/accounting/document-journals/'.$module;
        $this->putJson($path.'/settings', $settings + ['subdiary_code' => $subdiary])->assertOk();
        $doc = $this->postJson($path.'/documents', $payload + ['tax_allocations' => [
            ['category' => $category, 'base_amount' => $base, 'tax_amount' => $tax,
                'creditable_tax_amount' => $module === 'purchases' ? $tax : '0'],
        ]])->assertCreated()->json('data.id');
        $decision = ['expected_revision' => 0, 'included' => true, 'reference' => 'Revisión del artículo 6'];
        $this->putJson("$url/documents/$doc", $decision)->assertOk()->assertJsonPath('revision', 1);
        $this->putJson("$url/documents/$doc", $decision)->assertConflict();
    }
    $this->getJson($url)->assertOk()->assertJsonCount(2, 'data.rows')
        ->assertJsonPath('data.counts.included', 2)
        ->assertJsonPath('data.rows.0.over_threshold', false)->assertJsonPath('data.rows.1.over_threshold', true);
    $csv = $this->get("$url/detalle")->assertOk()->streamedContent();
    expect($csv)->toContain('15000.0000')->toContain('11000.0000');
    $purchase = $this->getJson($url)->json('data.documents.data.0.id');
    $pdb = $this->postJson('/api/v1/accounting/pdb-exportadores/2026-11', ['include_files' => true,
        'documents' => [['id' => $purchase, 'payments' => [['method' => '003', 'bank' => '02', 'number' => '010',
            'date' => '2026-11-12', 'amount' => '12980']]]],
    ])->assertOk()->assertJsonCount(0, 'data.problems')->assertJsonCount(3, 'data.files')->json('data.files');
    $files = collect($pdb)->keyBy(fn ($row) => $row['name'][0]);
    $purchaseLine = explode('|', trim(base64_decode($files['C']['base64'])));
    $paymentLine = explode('|', trim(base64_decode($files['F']['base64'])));
    expect($purchaseLine)->toHaveCount(31)->and($purchaseLine[16])->toBe('11000.00')
        ->and($paymentLine)->toHaveCount(13)->and($paymentLine[11])->toBe('12980.00');
});

it('generates PDB with 30-field records and independent shipment imports', function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-19 10:00', 'America/Lima'));
    $this->putJson('/api/v1/accounting/document-journals/sales/settings', [
        'subdiary_code' => 'VENTAS', 'main_account_id' => $this->accounts[0]->id, 'tax_account_id' => $this->accounts[2]->id,
        'settlement_account_id' => $this->accounts[1]->id, 'default_tax_rate' => '18',
    ])->assertOk();
    $id = $this->postJson('/api/v1/accounting/document-journals/sales/documents', [
        'document_type' => '01', 'series' => '0001', 'number' => '123', 'entity_id' => $this->entityId,
        'issue_date' => '2026-11-12', 'entry_date' => '2026-11-12', 'currency' => 'PEN', 'tax_period' => '202611',
        'tax_allocations' => [['category' => 'S_EXPORT', 'base_amount' => '2300.45', 'tax_amount' => '0']],
    ])->assertCreated()->json('data.id');
    $url = '/api/v1/accounting/pdb-exportadores/2026-11';
    $this->postJson($url, [])->assertOk()->assertJsonPath('data.problems.0.file', 'vínculo')
        ->assertJsonPath('data.files.0.base64', null);
    $report = $this->postJson($url, ['include_files' => true, 'documents' => [['id' => $id, 'shipment' => [
        'office' => '118', 'year' => '2026', 'number' => '20', 'shipped' => '2026-11-13', 'regularized' => '2026-11-15',
        'dam_fob' => '500.50', 'invoice_fob' => '500.50', 'type' => '01',
    ]]]])->assertOk()->assertJsonCount(0, 'data.problems')->assertJsonCount(3, 'data.files')->json('data');
    $files = collect($report['files'])->keyBy(fn ($row) => pathinfo($row['name'], PATHINFO_EXTENSION));
    $sale = explode('|', trim(base64_decode($files['txt']['base64'])));
    expect(count($sale))->toBe(31)->and($sale[0])->toBe('02')->and($sale[16])->toBe('2300.45')->and($sale[20])->toBe('');
    expect(base64_decode($files['dua']['base64']))->toBe("118|2026|20|13/11/2026|15/11/2026|500.50|\r\n");
    expect(base64_decode($files['DU1']['base64']))->toContain('1182026000020');
    $this->postJson($url, ['documents' => [['id' => 999999999]]])->assertUnprocessable()->assertJsonValidationErrors('documents');
    $this->travelBack();
});
