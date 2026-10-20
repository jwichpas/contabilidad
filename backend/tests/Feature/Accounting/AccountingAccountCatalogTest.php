<?php

declare(strict_types=1);

use App\Enums\AccountCurrencyMode;
use App\Enums\AccountType;
use App\Enums\BalanceType;
use App\Enums\CashFlowCategory;
use App\Enums\CompanyRole;
use App\Enums\EntityRequirementType;
use App\Enums\FinancialStatementRubric;
use App\Models\AccountingAccount;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Math\Decimal;
use App\Support\Tenant\TenantContext;
use App\Support\Types\AccountId;
use App\Support\Types\AccountLevelShort;
use App\Support\Types\CompanyId;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->tenantContext = app(TenantContext::class);

    $this->company = Company::query()->create([
        'tax_id' => '20555555551',
        'business_name' => 'Industrias Contables del Pacífico S.A.C.',
        'legal_address' => 'Av. Arequipa 4010, Miraflores',
        'is_active' => true,
    ]);

    $this->user = User::query()->create([
        'name' => 'María Paredes',
        'email' => 'mparedes@pacifico.pe',
        'password' => 'Secret123!',
    ]);

    $this->tenantContext->run($this->company->id, function (): void {
        CompanyUser::query()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'role' => CompanyRole::ChiefAccountant,
            'is_active' => true,
        ]);
    });

    Sanctum::actingAs($this->user, ['*']);
});

it('verifica el tipado fuerte estilo C# para Int16, Int64 y System.Decimal', function (): void {
    $companyId = CompanyId::fromInt($this->company->id);
    $levelShort = AccountLevelShort::fromInt(4);
    $amountA = Decimal::of('1450.2500', 4);
    $amountB = Decimal::of('549.7500', 4);

    expect($companyId->value())->toBe($this->company->id)
        ->and($levelShort->value())->toBe(4)
        ->and($levelShort->isLeafRegisterLevel())->toBeTrue()
        ->and($amountA->add($amountB)->value())->toBe('2000.0000')
        ->and($amountA->add($amountB)->sub(Decimal::of('2000.0000', 4))->isZero())->toBeTrue();
});

it('crea una jerarquía completa de 4 niveles con rubro 1041 Efectivo y Equivalentes, área de flujo de efectivo NIC 7 y destino 94/95 vs 79', function (): void {
    $headers = ['X-Company-Id' => (string) $this->company->id];

    // Nivel 1 (Elemento 10)
    $resLvl1 = $this->postJson('/api/v1/accounting/accounts', [
        'parent_id' => null,
        'code' => '10',
        'name' => 'Efectivo y Equivalentes de Efectivo',
        'level' => 1,
        'account_type' => AccountType::Asset->value,
        'balance_type' => BalanceType::Debit->value,
        'currency_mode' => AccountCurrencyMode::Multi->value,
        'requires_currency' => false,
        'requires_auxiliary' => false,
        'entity_requirement_type' => EntityRequirementType::None->value,
        'requires_cost_center' => false,
        'has_automatic_destination' => false,
        'destination_debit_code' => null,
        'destination_credit_code' => null,
        'requires_cash_flow_area' => false,
        'cash_flow_category' => CashFlowCategory::CashEquivalent->value,
        'financial_statement_rubric' => FinancialStatementRubric::CashAndEquivalents->value,
        'is_operational' => false,
    ], $headers)->assertCreated();

    $lvl1Id = AccountId::fromNumericString((string) $resLvl1->json('data.id'))->value();

    // Nivel 2 (Cuenta 104)
    $resLvl2 = $this->postJson('/api/v1/accounting/accounts', [
        'parent_id' => $lvl1Id,
        'code' => '104',
        'name' => 'Cuentas Corrientes en Instituciones Financieras',
        'level' => 2,
        'account_type' => AccountType::Asset->value,
        'balance_type' => BalanceType::Debit->value,
        'currency_mode' => AccountCurrencyMode::Multi->value,
        'requires_currency' => false,
        'requires_auxiliary' => false,
        'entity_requirement_type' => EntityRequirementType::None->value,
        'requires_cost_center' => false,
        'has_automatic_destination' => false,
        'destination_debit_code' => null,
        'destination_credit_code' => null,
        'requires_cash_flow_area' => false,
        'cash_flow_category' => CashFlowCategory::CashEquivalent->value,
        'financial_statement_rubric' => FinancialStatementRubric::CashAndEquivalents->value,
        'is_operational' => false,
    ], $headers)->assertCreated();

    $lvl2Id = AccountId::fromNumericString((string) $resLvl2->json('data.id'))->value();

    // Nivel 3 (Subcuenta 1041 -> Efectivo y Equivalentes de Efectivo)
    $resLvl3 = $this->postJson('/api/v1/accounting/accounts', [
        'parent_id' => $lvl2Id,
        'code' => '1041',
        'name' => 'Cuentas Corrientes Operativas',
        'level' => 3,
        'account_type' => AccountType::Asset->value,
        'balance_type' => BalanceType::Debit->value,
        'currency_mode' => AccountCurrencyMode::Multi->value,
        'requires_currency' => false,
        'requires_auxiliary' => false,
        'entity_requirement_type' => EntityRequirementType::None->value,
        'requires_cost_center' => false,
        'has_automatic_destination' => false,
        'destination_debit_code' => null,
        'destination_credit_code' => null,
        'requires_cash_flow_area' => false,
        'cash_flow_category' => CashFlowCategory::CashEquivalent->value,
        'financial_statement_rubric' => FinancialStatementRubric::CashAndEquivalents->value,
        'is_operational' => false,
    ], $headers)->assertCreated();

    $lvl3Id = AccountId::fromNumericString((string) $resLvl3->json('data.id'))->value();

    // Nivel 4 (Registro Operativo 104102 -> USD + Entidad Financiera + Área de Flujo de Efectivo + Rubro EEFF)
    $resLvl4 = $this->postJson('/api/v1/accounting/accounts', [
        'parent_id' => $lvl3Id,
        'code' => '104102',
        'name' => 'BCP Cuenta Corriente Operativa M.E. (USD)',
        'level' => 4,
        'account_type' => AccountType::Asset->value,
        'balance_type' => BalanceType::Debit->value,
        'currency_mode' => AccountCurrencyMode::Usd->value,
        'requires_currency' => true,
        'requires_auxiliary' => true,
        'entity_requirement_type' => EntityRequirementType::FinancialInstitution->value,
        'requires_cost_center' => false,
        'has_automatic_destination' => false,
        'destination_debit_code' => null,
        'destination_credit_code' => null,
        'requires_cash_flow_area' => true,
        'cash_flow_category' => CashFlowCategory::Operating->value,
        'financial_statement_rubric' => FinancialStatementRubric::CashAndEquivalents->value,
        'is_operational' => true,
    ], $headers)->assertCreated();

    expect($resLvl4->json('data.path'))->toBe('10.104.1041.104102')
        ->and($resLvl4->json('data.is_operational'))->toBeTrue()
        ->and($resLvl4->json('data.currency_mode'))->toBe('USD')
        ->and($resLvl4->json('data.entity_requirement_type'))->toBe('FINANCIAL_INSTITUTION')
        ->and($resLvl4->json('data.requires_cash_flow_area'))->toBeTrue()
        ->and($resLvl4->json('data.cash_flow_category'))->toBe('OPERATING')
        ->and($resLvl4->json('data.financial_statement_rubric'))->toBe('ESF_ACT_CORR_EFE');

    // Consulta del árbol completo
    $treeResponse = $this->getJson('/api/v1/accounting/accounts/tree', $headers)
        ->assertOk();

    expect($treeResponse->json('data.0.code'))->toBe('10')
        ->and($treeResponse->json('data.0.children.0.code'))->toBe('104')
        ->and($treeResponse->json('data.0.children.0.children.0.code'))->toBe('1041')
        ->and($treeResponse->json('data.0.children.0.children.0.children.0.code'))->toBe('104102');
});

it('impide desactivar una cuenta padre mientras tenga subcuentas hijas activas', function (): void {
    $headers = ['X-Company-Id' => (string) $this->company->id];

    $lvl1Id = null;
    $lvl2Id = null;

    $this->tenantContext->run($this->company->id, function () use (&$lvl1Id, &$lvl2Id): void {
        $lvl1 = AccountingAccount::query()->create([
            'code' => '10',
            'name' => 'Efectivo',
            'level' => 1,
            'path' => '10',
            'account_type' => AccountType::Asset,
            'balance_type' => BalanceType::Debit,
            'currency_mode' => AccountCurrencyMode::Multi,
            'entity_requirement_type' => EntityRequirementType::None,
            'cash_flow_category' => CashFlowCategory::CashEquivalent,
            'financial_statement_rubric' => FinancialStatementRubric::CashAndEquivalents,
            'is_operational' => false,
            'is_active' => true,
        ]);

        $lvl2 = AccountingAccount::query()->create([
            'parent_id' => $lvl1->id,
            'code' => '104',
            'name' => 'Cuentas Corrientes',
            'level' => 2,
            'path' => '10.104',
            'account_type' => AccountType::Asset,
            'balance_type' => BalanceType::Debit,
            'currency_mode' => AccountCurrencyMode::Multi,
            'entity_requirement_type' => EntityRequirementType::None,
            'cash_flow_category' => CashFlowCategory::CashEquivalent,
            'financial_statement_rubric' => FinancialStatementRubric::CashAndEquivalents,
            'is_operational' => false,
            'is_active' => true,
        ]);

        $lvl1Id = $lvl1->id;
        $lvl2Id = $lvl2->id;
    });

    // Intentar desactivar lvl1 mientras lvl2 está activa debe devolver 409 Conflict
    $this->patchJson("/api/v1/accounting/accounts/{$lvl1Id}/deactivate", [], $headers)
        ->assertStatus(409);

    // Desactivar primero la hija lvl2 y luego la padre lvl1 funciona correctamente
    $this->patchJson("/api/v1/accounting/accounts/{$lvl2Id}/deactivate", [], $headers)
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $this->patchJson("/api/v1/accounting/accounts/{$lvl1Id}/deactivate", [], $headers)
        ->assertOk()
        ->assertJsonPath('data.is_active', false);
});
