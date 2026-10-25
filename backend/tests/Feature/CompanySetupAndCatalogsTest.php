<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\AccountingAccount;
use App\Models\AccountingStandard;
use App\Models\CashFlowArea;
use App\Models\Company;
use App\Models\CompanyArea;
use App\Models\CompanySetting;
use App\Models\CompanyUser;
use App\Models\CostCenter;
use App\Models\Currency;
use App\Models\Entity;
use App\Models\FinancialStatementRubricModel;
use App\Models\Sunat\SunatIdentityDocumentType;
use App\Models\Sunat\SunatPaymentMethod;
use App\Models\TaxCalendarDeadline;
use App\Models\User;
use App\Support\Tenant\TenantContext;

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

        $this->user->forceFill(['current_company_id' => $this->company->id])->save();
    });
});

test('los catálogos oficiales de monedas contienen PEN, USD y EUR', function (): void {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/catalogs/currencies');

    $response->assertOk()
        ->assertJsonFragment(['code' => 'PEN', 'is_base_currency' => true])
        ->assertJsonFragment(['code' => 'USD', 'is_base_currency' => false])
        ->assertJsonFragment(['code' => 'EUR', 'is_base_currency' => false]);
});

test('las tablas oficiales de SUNAT pueden consultarse vía API REST', function (): void {
    // Tabla 01: Medios de Pago
    $res01 = $this->actingAs($this->user)->getJson('/api/v1/catalogs/sunat/01');
    $res01->assertOk()
        ->assertJsonFragment(['code' => '001', 'name' => 'DEPÓSITO EN CUENTA'])
        ->assertJsonFragment(['code' => '003', 'name' => 'TRANSFERENCIA DE FONDOS']);

    // Tabla 02: Tipo de Documento de Identidad
    $res02 = $this->actingAs($this->user)->getJson('/api/v1/catalogs/sunat/02');
    $res02->assertOk()
        ->assertJsonFragment(['code' => '1', 'name' => 'DOCUMENTO NACIONAL DE IDENTIDAD (DNI)'])
        ->assertJsonFragment(['code' => '6', 'name' => 'REGISTRO ÚNICO DE CONTRIBUYENTES']);
});

test('las normas contables vigentes en 2026 incluyen NIIF 18 y NIIF 19 con NIC 1 retirada', function (): void {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/catalogs/accounting-standards');

    $response->assertOk()
        ->assertJsonFragment(['code' => 'NIIF 18', 'status' => 'NUEVA_2026'])
        ->assertJsonFragment(['code' => 'NIIF 19', 'status' => 'NUEVA_2026'])
        ->assertJsonFragment(['code' => 'NIC 1', 'status' => 'RETIRADA'])
        ->assertJsonFragment(['code' => 'NIC 21', 'status' => 'VIGENTE']);
});

test('los rubros oficiales de estados financieros incluyen ESF, ERF, ERN y EFE', function (): void {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/catalogs/financial-statement-rubrics');

    $response->assertOk()
        ->assertJsonFragment(['code' => 'ESF_ACT_CORR_EFE', 'statement_type' => 'ESF'])
        ->assertJsonFragment(['code' => 'ERF_GASTOS_ADMIN_94', 'statement_type' => 'ERF'])
        ->assertJsonFragment(['code' => 'ERN_SERV_TERCEROS', 'statement_type' => 'ERN'])
        ->assertJsonFragment(['code' => 'EFE_OP_COB_VTAS', 'statement_type' => 'EFE']);
});

test('el cronograma de vencimientos SUNAT 2026 provee fechas para libros y declaraciones', function (): void {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/catalogs/tax-calendar-deadlines/2026?month=1&last_digit_ruc=0');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'fiscal_year',
                    'fiscal_month',
                    'last_digit_ruc',
                    'electronic_books_deadline',
                    'tax_returns_deadline',
                ],
            ],
        ]);
});

test('la configuración de empresa y centros de costos operan con aislamiento multi-tenant', function (): void {
    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/company/settings');

    $response->assertOk()
        ->assertJsonPath('data.tax_regime', 'GENERAL')
        ->assertJsonPath('data.default_vat_rate', '18.00');

    $areasRes = $this->actingAs($this->user)->getJson('/api/v1/company/areas');
    $areasRes->assertOk();

    $costCentersRes = $this->actingAs($this->user)->getJson('/api/v1/company/cost-centers');
    $costCentersRes->assertOk();

    $entitiesRes = $this->actingAs($this->user)->getJson('/api/v1/company/entities?is_supplier=1');
    $entitiesRes->assertOk();
});
