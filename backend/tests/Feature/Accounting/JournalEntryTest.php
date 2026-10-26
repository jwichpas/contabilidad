<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\AccountingAccount;
use App\Models\AccountingAccountTemplate;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\InitializeCompanyChartOfAccountsService;
use App\Support\Tenant\TenantContext;
use Database\Seeders\MasterChartOfAccountsSeeder;

beforeEach(function (): void {
    $this->tenantContext = app(TenantContext::class);

    $this->company = Company::query()->firstOrCreate(
        ['tax_id' => '20601234567'],
        [
            'business_name' => 'Corporación Peruana de Distribución S.A.C.',
            'legal_address' => 'Av. Javier Prado Este 2450, San Borja, Lima',
            'is_active' => true,
        ]
    );

    $this->user = User::query()->firstOrCreate(
        ['email' => 'contador@corporacionperu.pe'],
        [
            'name' => 'Contador General CPC',
            'password' => 'Contabilidad2026!',
        ]
    );

    $this->tenantContext->run($this->company->id, function (): void {
        \App\Models\CompanySubdiary::query()->firstOrCreate(
            ['code' => 'GENERAL'], ['name' => 'Diario general']
        );
        CompanyUser::query()->firstOrCreate(
            [
                'company_id' => $this->company->id,
                'user_id' => $this->user->id,
            ],
            [
                'role' => CompanyRole::ChiefAccountant,
                'is_active' => true,
            ]
        );
    });

    // El FK compuesto exige la membresía antes de establecer la empresa actual.
    $this->user->update(['current_company_id' => $this->company->id]);

    // Instalar el PCGE versionado por el mismo proceso de alta que usa la aplicación.
    // Los datos pertenecen a la transacción reversible de esta prueba.
    if (! AccountingAccountTemplate::query()->where('plan_version', 'PCGE_2019')->exists()) {
        app(MasterChartOfAccountsSeeder::class)->seedTemplates();
    }
    $hasAccounts = $this->tenantContext->run($this->company->id,
        fn (): bool => AccountingAccount::query()->exists());
    if (! $hasAccounts) {
        app(InitializeCompanyChartOfAccountsService::class)
            ->initialize((int) $this->company->id, 'PCGE_2019');
    }

    // El asiento de estas pruebas no exige dimensiones ajenas al objetivo probado.
    $this->postingAccounts = $this->tenantContext->run($this->company->id, function (): array {
        return AccountingAccount::query()
            ->where('is_operational', true)
            ->where('is_active', true)
            ->where('entity_requirement_type', 'NONE')
            ->where('requires_auxiliary', false)
            ->where('requires_cost_center', false)
            ->where('has_automatic_destination', false)
            ->where('requires_company_area', false)
            ->where('requires_cash_flow_area', false)
            ->orderBy('code')
            ->limit(2)
            ->get()
            ->all();
    });
    expect($this->postingAccounts)->toHaveCount(2);

    $this->token = $this->user->createToken('test-suite')->plainTextToken;
    $this->headers = [
        'Authorization' => "Bearer {$this->token}",
        'X-Company-Id' => (string) $this->company->id,
        'Accept' => 'application/json',
    ];
});

describe('Asientos Contables / Libro Diario Bimoneda (Partida Doble)', function (): void {
    test('lista las cuentas contables operativas para digitación rápida', function (): void {
        $response = $this->getJson('/api/v1/accounting/accounts/operational', $this->headers);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'code',
                    'name',
                    'level',
                    'account_type',
                    'balance_type',
                    'currency_mode',
                ],
            ],
        ]);

        $accounts = $response->json('data');
        expect(count($accounts))->toBeGreaterThan(10);
    });

    test('precalcula el siguiente correlativo contable para el periodo fiscal', function (): void {
        $response = $this->getJson('/api/v1/accounting/journal-entries/next-number?date=2026-10-19', $this->headers);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'next_number',
                'period_year',
                'period_month',
                'is_period_open',
            ],
        ]);

        expect($response->json('data.next_number'))->toMatch('/^10-\d{5}$/');
    });

    test('registra un asiento contable cuadrado con partida doble exacta en bimoneda', function (): void {
        // Obtenemos 2 cuentas operativas reales de la empresa
        $operationalAccounts = $this->postingAccounts;

        expect(count($operationalAccounts))->toBe(2);

        $accountDebit = $operationalAccounts[0];
        $accountCredit = $operationalAccounts[1];

        $payload = [
            'entry_date' => '2026-10-19',
            'entry_type' => 'STANDARD',
            'currency' => 'PEN',
            'exchange_rate' => 3.750000,
            'exchange_rate_type' => 'SELL',
            'description' => 'Por el cobro de factura comercial mediante depósito bancario',
            'lines' => [
                [
                    'accounting_account_id' => $accountDebit->id,
                    'description' => 'Ingreso a cuentas corrientes',
                    'debit_local' => 1500.0000,
                    'credit_local' => 0.0000,
                    'debit_foreign' => 400.0000,
                    'credit_foreign' => 0.0000,
                ],
                [
                    'accounting_account_id' => $accountCredit->id,
                    'description' => 'Cancelación de cuenta por cobrar',
                    'debit_local' => 0.0000,
                    'credit_local' => 1500.0000,
                    'debit_foreign' => 0.0000,
                    'credit_foreign' => 400.0000,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/accounting/journal-entries', $payload, $this->headers);

        $response->assertStatus(201);
        $response->assertJsonPath('data.status', 'POSTED');
        $response->assertJsonPath('data.currency', 'PEN');
        expect(count($response->json('data.lines')))->toBe(2);

        // Verifica que se haya registrado en audit_logs (ISO/IEC 27001)
        $auditLog = AuditLog::query()
            ->where('event_type', 'JOURNAL_ENTRY_CREATED')
            ->latest('id')
            ->first();

        expect($auditLog)->not->toBeNull();
        expect($auditLog->severity)->toBe('INFO');
    });

    test('correlativo automático usa el mayor sufijo y preview no reserva número', function (): void {
        $operational = $this->postingAccounts;
        expect(count($operational))->toBe(2);
        $payload = [
            'entry_date' => '2026-10-19',
            'entry_type' => 'STANDARD',
            'currency' => 'PEN',
            'exchange_rate' => '3.750000',
            'exchange_rate_type' => 'SELL',
            'description' => 'Prueba de correlativo en transaccion',
            'lines' => [
                [
                    'accounting_account_id' => $operational[0]->id,
                    'debit_local' => '120.0000',
                    'credit_local' => '0.0000',
                    'debit_foreign' => '32.0000',
                    'credit_foreign' => '0.0000',
                ],
                [
                    'accounting_account_id' => $operational[1]->id,
                    'debit_local' => '0.0000',
                    'credit_local' => '120.0000',
                    'debit_foreign' => '0.0000',
                    'credit_foreign' => '32.0000',
                ],
            ],
        ];

        // Crear un hueco intencional: COUNT(*)+1 sugeriría 10-00002,
        // pero el MAX real obliga a reservar 10-98001.
        $manual = $this->postJson('/api/v1/accounting/journal-entries',
            array_merge($payload, ['entry_number' => '10-98000']),
            $this->headers);
        $manual->assertCreated();

        $preview = $this->getJson(
            '/api/v1/accounting/journal-entries/next-number?date=2026-10-19',
            $this->headers
        );
        $preview->assertOk();
        $preview->assertJsonPath('data.is_reserved', false);
        $preview->assertJsonPath('data.next_number', '10-98001');

        $first = $this->postJson('/api/v1/accounting/journal-entries',
            $payload, $this->headers);
        $second = $this->postJson('/api/v1/accounting/journal-entries',
            $payload, $this->headers);

        $first->assertCreated()->assertJsonPath('data.entry_number','10-98001');
        $second->assertCreated()->assertJsonPath('data.entry_number','10-98002');
        expect($first->json('data.entry_number'))
            ->not->toBe($second->json('data.entry_number'));
    });

    test('rechaza asientos descuadrados que violan la partida doble (Debe != Haber)', function (): void {
        $operationalAccounts = $this->postingAccounts;

        $accountDebit = $operationalAccounts[0];
        $accountCredit = $operationalAccounts[1];

        // Montos descuadrados intencionalmente: 1500 vs 1400
        $unbalancedPayload = [
            'entry_date' => '2026-10-19',
            'entry_type' => 'STANDARD',
            'currency' => 'PEN',
            'exchange_rate' => 3.750000,
            'description' => 'Asiento con descuadre intencional',
            'lines' => [
                [
                    'accounting_account_id' => $accountDebit->id,
                    'debit_local' => 1500.0000,
                    'credit_local' => 0.0000,
                    'debit_foreign' => 400.0000,
                    'credit_foreign' => 0.0000,
                ],
                [
                    'accounting_account_id' => $accountCredit->id,
                    'debit_local' => 0.0000,
                    'credit_local' => 1400.0000,
                    'debit_foreign' => 0.0000,
                    'credit_foreign' => 373.3333,
                ],
            ],
        ];

        $response = $this->postJson('/api/v1/accounting/journal-entries', $unbalancedPayload, $this->headers);

        $response->assertStatus(422);
        expect($response->json('message'))->toContain('Partida Doble');
    });

    test('rechaza imputaciones en cuentas no operativas o de nivel mayor', function (): void {
        // Obtenemos una cuenta de nivel 1 (no operativa)
        $parentAccount = $this->tenantContext->run($this->company->id, function (): ?AccountingAccount {
            return AccountingAccount::query()
                ->where('is_operational', false)
                ->first();
        });

        $operationalAccount = $this->postingAccounts[0];

        if ($parentAccount !== null) {
            $invalidPayload = [
                'entry_date' => '2026-10-19',
                'entry_type' => 'STANDARD',
                'currency' => 'PEN',
                'exchange_rate' => 3.750000,
                'description' => 'Asiento en cuenta de mayor',
                'lines' => [
                    [
                        'accounting_account_id' => $parentAccount->id,
                        'debit_local' => 500.0000,
                        'credit_local' => 0.0000,
                        'debit_foreign' => 133.3333,
                        'credit_foreign' => 0.0000,
                    ],
                    [
                        'accounting_account_id' => $operationalAccount->id,
                        'debit_local' => 0.0000,
                        'credit_local' => 500.0000,
                        'debit_foreign' => 0.0000,
                        'credit_foreign' => 133.3333,
                    ],
                ],
            ];

            $response = $this->postJson('/api/v1/accounting/journal-entries', $invalidPayload, $this->headers);
            // Debe fallar por trigger de base de datos o validación
            expect($response->status())->toBeGreaterThanOrEqual(400);
        }
    });
});
