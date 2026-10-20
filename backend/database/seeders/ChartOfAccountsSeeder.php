<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccountCurrencyMode;
use App\Enums\AccountType;
use App\Enums\BalanceType;
use App\Enums\CashFlowCategory;
use App\Enums\EntityRequirementType;
use App\Enums\FinancialStatementRubric;
use App\Models\AccountingAccount;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Seeder jerárquico de 4 niveles del Plan Contable General Empresarial (PCGE 2026)
 * con configuración completa de:
 * - Moneda (`currency_mode`: PEN, USD, MULTI)
 * - Entidad (`entity_requirement_type`: Banco, Cliente, Proveedor, SUNAT)
 * - Centro de Costos y Destino Automático a Elemento 9 (`9411`/`9511` vs `7911`)
 * - Área de Flujo de Efectivo para la Cuenta 10 (`CASH_EQUIVALENT`)
 * - Rubro de Estados Financieros (`1041` -> `ESF_ACT_CORR_EFE` = Efectivo y Equivalentes de Efectivo)
 */
final class ChartOfAccountsSeeder extends Seeder
{
    public function runForCompany(int $companyId): void
    {
        /** @var TenantContext $tenantContext */
        $tenantContext = app(TenantContext::class);

        $tenantContext->run($companyId, function (): void {
            $hierarchy = [
                // ELEMENTO 1: ACTIVO DISPONIBLE Y EXIGIBLE
                [
                    'code' => '1',
                    'name' => 'Activo Disponible y Exigible',
                    'account_type' => AccountType::Asset,
                    'balance_type' => BalanceType::Debit,
                    'children' => [
                        [
                            'code' => '10',
                            'name' => 'Efectivo y Equivalentes de Efectivo',
                            'children' => [
                                [
                                    'code' => '101',
                                    'name' => 'Caja',
                                    'children' => [
                                        [
                                            'code' => '1011',
                                            'name' => 'Caja Moneda Nacional',
                                            'currency_mode' => AccountCurrencyMode::Pen,
                                            'entity_type' => EntityRequirementType::None,
                                            'cost_center' => false,
                                            'auto_dest' => false,
                                            'dest_debit' => null,
                                            'dest_credit' => null,
                                            'cash_flow_area' => true,
                                            'cash_flow_cat' => CashFlowCategory::CashEquivalent,
                                            'fs_rubric' => FinancialStatementRubric::CashAndEquivalents,
                                        ],
                                    ],
                                ],
                                [
                                    'code' => '104',
                                    'name' => 'Cuentas Corrientes en Instituciones Financieras',
                                    'children' => [
                                        [
                                            'code' => '1041',
                                            'name' => 'Cuentas Corrientes Operativas MN',
                                            'currency_mode' => AccountCurrencyMode::Pen,
                                            'entity_type' => EntityRequirementType::FinancialInstitution,
                                            'cost_center' => false,
                                            'auto_dest' => false,
                                            'dest_debit' => null,
                                            'dest_credit' => null,
                                            'cash_flow_area' => true,
                                            'cash_flow_cat' => CashFlowCategory::CashEquivalent,
                                            'fs_rubric' => FinancialStatementRubric::CashAndEquivalents,
                                        ],
                                        [
                                            'code' => '1042',
                                            'name' => 'Cuentas Corrientes Operativas ME (USD)',
                                            'currency_mode' => AccountCurrencyMode::Usd,
                                            'entity_type' => EntityRequirementType::FinancialInstitution,
                                            'cost_center' => false,
                                            'auto_dest' => false,
                                            'dest_debit' => null,
                                            'dest_credit' => null,
                                            'cash_flow_area' => true,
                                            'cash_flow_cat' => CashFlowCategory::CashEquivalent,
                                            'fs_rubric' => FinancialStatementRubric::CashAndEquivalents,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'code' => '12',
                            'name' => 'Cuentas por Cobrar Comerciales - Terceros',
                            'children' => [
                                [
                                    'code' => '121',
                                    'name' => 'Facturas, Boletas y Otros Comprobantes por Cobrar',
                                    'children' => [
                                        [
                                            'code' => '1212',
                                            'name' => 'Emitidas en Cartera',
                                            'currency_mode' => AccountCurrencyMode::Multi,
                                            'entity_type' => EntityRequirementType::Customer,
                                            'cost_center' => false,
                                            'auto_dest' => false,
                                            'dest_debit' => null,
                                            'dest_credit' => null,
                                            'cash_flow_area' => false,
                                            'cash_flow_cat' => CashFlowCategory::Operating,
                                            'fs_rubric' => FinancialStatementRubric::TradeReceivables,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                // ELEMENTO 4: PASIVO
                [
                    'code' => '4',
                    'name' => 'Pasivo',
                    'account_type' => AccountType::Liability,
                    'balance_type' => BalanceType::Credit,
                    'children' => [
                        [
                            'code' => '40',
                            'name' => 'Tributos y Aportes al Sistema Público de Pensiones y de Salud por Pagar',
                            'children' => [
                                [
                                    'code' => '401',
                                    'name' => 'Gobierno Nacional',
                                    'children' => [
                                        [
                                            'code' => '4011',
                                            'name' => 'Impuesto General a las Ventas - IGV Cuenta Propia',
                                            'currency_mode' => AccountCurrencyMode::Pen,
                                            'entity_type' => EntityRequirementType::TaxAuthority,
                                            'cost_center' => false,
                                            'auto_dest' => false,
                                            'dest_debit' => null,
                                            'dest_credit' => null,
                                            'cash_flow_area' => false,
                                            'cash_flow_cat' => CashFlowCategory::Operating,
                                            'fs_rubric' => FinancialStatementRubric::TaxesAndContributionsPayable,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'code' => '42',
                            'name' => 'Cuentas por Pagar Comerciales - Terceros',
                            'children' => [
                                [
                                    'code' => '421',
                                    'name' => 'Facturas, Boletas y Otros Comprobantes por Pagar',
                                    'children' => [
                                        [
                                            'code' => '4212',
                                            'name' => 'Emitidas por Pagar',
                                            'currency_mode' => AccountCurrencyMode::Multi,
                                            'entity_type' => EntityRequirementType::Supplier,
                                            'cost_center' => false,
                                            'auto_dest' => false,
                                            'dest_debit' => null,
                                            'dest_credit' => null,
                                            'cash_flow_area' => false,
                                            'cash_flow_cat' => CashFlowCategory::Operating,
                                            'fs_rubric' => FinancialStatementRubric::TradePayables,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                // ELEMENTO 5: PATRIMONIO NETO
                [
                    'code' => '5',
                    'name' => 'Patrimonio Neto',
                    'account_type' => AccountType::Equity,
                    'balance_type' => BalanceType::Credit,
                    'children' => [
                        [
                            'code' => '50',
                            'name' => 'Capital',
                            'children' => [
                                [
                                    'code' => '501',
                                    'name' => 'Capital Social',
                                    'children' => [
                                        [
                                            'code' => '5011',
                                            'name' => 'Acciones Comunes Suscritas y Pagadas',
                                            'currency_mode' => AccountCurrencyMode::Pen,
                                            'entity_type' => EntityRequirementType::None,
                                            'cost_center' => false,
                                            'auto_dest' => false,
                                            'dest_debit' => null,
                                            'dest_credit' => null,
                                            'cash_flow_area' => true,
                                            'cash_flow_cat' => CashFlowCategory::Financing,
                                            'fs_rubric' => FinancialStatementRubric::IssuedCapital,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'code' => '59',
                            'name' => 'Resultados Acumulados',
                            'children' => [
                                [
                                    'code' => '591',
                                    'name' => 'Utilidades no Distribuidas',
                                    'children' => [
                                        [
                                            'code' => '5911',
                                            'name' => 'Utilidades Acumuladas de Ejercicios Anteriores',
                                            'currency_mode' => AccountCurrencyMode::Pen,
                                            'entity_type' => EntityRequirementType::None,
                                            'cost_center' => false,
                                            'auto_dest' => false,
                                            'dest_debit' => null,
                                            'dest_credit' => null,
                                            'cash_flow_area' => false,
                                            'cash_flow_cat' => CashFlowCategory::None,
                                            'fs_rubric' => FinancialStatementRubric::RetainedEarnings,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                // ELEMENTO 6: GASTOS POR NATURALEZA (Con Centro de Costos y Destino Automático a 94/95 vs 79)
                [
                    'code' => '6',
                    'name' => 'Gastos por Naturaleza',
                    'account_type' => AccountType::Expense,
                    'balance_type' => BalanceType::Debit,
                    'children' => [
                        [
                            'code' => '63',
                            'name' => 'Gastos de Servicios Prestados por Terceros',
                            'children' => [
                                [
                                    'code' => '636',
                                    'name' => 'Servicios Básicos',
                                    'children' => [
                                        [
                                            'code' => '6361',
                                            'name' => 'Energía Eléctrica y Agua',
                                            'currency_mode' => AccountCurrencyMode::Pen,
                                            'entity_type' => EntityRequirementType::Supplier,
                                            'cost_center' => true,
                                            'auto_dest' => true,
                                            'dest_debit' => '9411',
                                            'dest_credit' => '7911',
                                            'cash_flow_area' => false,
                                            'cash_flow_cat' => CashFlowCategory::None,
                                            'fs_rubric' => FinancialStatementRubric::ThirdPartyServices,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'code' => '67',
                            'name' => 'Gastos Financieros',
                            'children' => [
                                [
                                    'code' => '676',
                                    'name' => 'Diferencia de Cambio',
                                    'children' => [
                                        [
                                            'code' => '6761',
                                            'name' => 'Diferencia de Cambio - Pérdida (NIC 21)',
                                            'currency_mode' => AccountCurrencyMode::Pen,
                                            'entity_type' => EntityRequirementType::None,
                                            'cost_center' => false,
                                            'auto_dest' => false,
                                            'dest_debit' => null,
                                            'dest_credit' => null,
                                            'cash_flow_area' => false,
                                            'cash_flow_cat' => CashFlowCategory::None,
                                            'fs_rubric' => FinancialStatementRubric::OtherManagementExpenses,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                // ELEMENTO 7: INGRESOS Y CARGAS IMPUTABLES (79)
                [
                    'code' => '7',
                    'name' => 'Ingresos',
                    'account_type' => AccountType::Revenue,
                    'balance_type' => BalanceType::Credit,
                    'children' => [
                        [
                            'code' => '70',
                            'name' => 'Ventas',
                            'children' => [
                                [
                                    'code' => '701',
                                    'name' => 'Mercaderías',
                                    'children' => [
                                        [
                                            'code' => '7011',
                                            'name' => 'Venta de Mercaderías Manufacturadas - Terceros',
                                            'currency_mode' => AccountCurrencyMode::Multi,
                                            'entity_type' => EntityRequirementType::Customer,
                                            'cost_center' => false,
                                            'auto_dest' => false,
                                            'dest_debit' => null,
                                            'dest_credit' => null,
                                            'cash_flow_area' => false,
                                            'cash_flow_cat' => CashFlowCategory::None,
                                            'fs_rubric' => FinancialStatementRubric::NetSalesRevenue,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'code' => '77',
                            'name' => 'Ingresos Financieros',
                            'children' => [
                                [
                                    'code' => '776',
                                    'name' => 'Diferencia de Cambio',
                                    'children' => [
                                        [
                                            'code' => '7761',
                                            'name' => 'Diferencia de Cambio - Ganancia (NIC 21)',
                                            'currency_mode' => AccountCurrencyMode::Pen,
                                            'entity_type' => EntityRequirementType::None,
                                            'cost_center' => false,
                                            'auto_dest' => false,
                                            'dest_debit' => null,
                                            'dest_credit' => null,
                                            'cash_flow_area' => false,
                                            'cash_flow_cat' => CashFlowCategory::None,
                                            'fs_rubric' => FinancialStatementRubric::OtherOperatingIncome,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'code' => '79',
                            'name' => 'Cargas Imputables a Cuentas de Costos y Gastos',
                            'children' => [
                                [
                                    'code' => '791',
                                    'name' => 'Cargas Imputables a Cuentas de Costos y Gastos',
                                    'children' => [
                                        [
                                            'code' => '7911',
                                            'name' => 'Cargas Imputables a Cuentas de Costos y Gastos',
                                            'currency_mode' => AccountCurrencyMode::Pen,
                                            'entity_type' => EntityRequirementType::None,
                                            'cost_center' => false,
                                            'auto_dest' => false,
                                            'dest_debit' => null,
                                            'dest_credit' => null,
                                            'cash_flow_area' => false,
                                            'cash_flow_cat' => CashFlowCategory::None,
                                            'fs_rubric' => FinancialStatementRubric::ImputableChargesControl,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                // ELEMENTO 9: CONTABILIDAD ANALÍTICA DE EXPLOTACIÓN (Cuentas 94 y 95 por Función)
                [
                    'code' => '9',
                    'name' => 'Contabilidad Analítica de Explotación: Costos de Producción y Gastos por Función',
                    'account_type' => AccountType::Cost,
                    'balance_type' => BalanceType::Debit,
                    'children' => [
                        [
                            'code' => '94',
                            'name' => 'Gastos de Administración',
                            'children' => [
                                [
                                    'code' => '941',
                                    'name' => 'Gastos Generales de Administración',
                                    'children' => [
                                        [
                                            'code' => '9411',
                                            'name' => 'Gastos de Administración - Centro de Costos Administrativo',
                                            'currency_mode' => AccountCurrencyMode::Pen,
                                            'entity_type' => EntityRequirementType::None,
                                            'cost_center' => true,
                                            'auto_dest' => false,
                                            'dest_debit' => null,
                                            'dest_credit' => null,
                                            'cash_flow_area' => false,
                                            'cash_flow_cat' => CashFlowCategory::None,
                                            'fs_rubric' => FinancialStatementRubric::AdministrativeExpenses,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'code' => '95',
                            'name' => 'Gastos de Ventas',
                            'children' => [
                                [
                                    'code' => '951',
                                    'name' => 'Gastos Comerciales y de Distribución',
                                    'children' => [
                                        [
                                            'code' => '9511',
                                            'name' => 'Gastos de Ventas - Centro de Costos Comercial',
                                            'currency_mode' => AccountCurrencyMode::Pen,
                                            'entity_type' => EntityRequirementType::None,
                                            'cost_center' => true,
                                            'auto_dest' => false,
                                            'dest_debit' => null,
                                            'dest_credit' => null,
                                            'cash_flow_area' => false,
                                            'cash_flow_cat' => CashFlowCategory::None,
                                            'fs_rubric' => FinancialStatementRubric::SellingExpenses,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            foreach ($hierarchy as $lvl1Data) {
                $lvl1 = AccountingAccount::query()->updateOrCreate(
                    ['code' => $lvl1Data['code']],
                    [
                        'parent_id' => null,
                        'name' => $lvl1Data['name'],
                        'level' => 1,
                        'path' => $lvl1Data['code'],
                        'account_type' => $lvl1Data['account_type'],
                        'balance_type' => $lvl1Data['balance_type'],
                        'currency_mode' => AccountCurrencyMode::Pen,
                        'requires_currency' => false,
                        'requires_auxiliary' => false,
                        'entity_requirement_type' => EntityRequirementType::None,
                        'requires_cost_center' => false,
                        'has_automatic_destination' => false,
                        'requires_cash_flow_area' => false,
                        'cash_flow_category' => CashFlowCategory::None,
                        'financial_statement_rubric' => FinancialStatementRubric::None,
                        'is_operational' => false,
                        'is_active' => true,
                    ]
                );

                foreach ($lvl1Data['children'] as $lvl2Data) {
                    $lvl2Path = $lvl1->path . '.' . $lvl2Data['code'];
                    $lvl2 = AccountingAccount::query()->updateOrCreate(
                        ['code' => $lvl2Data['code']],
                        [
                            'parent_id' => $lvl1->id,
                            'name' => $lvl2Data['name'],
                            'level' => 2,
                            'path' => $lvl2Path,
                            'account_type' => $lvl1Data['account_type'],
                            'balance_type' => $lvl1Data['balance_type'],
                            'currency_mode' => AccountCurrencyMode::Pen,
                            'requires_currency' => false,
                            'requires_auxiliary' => false,
                            'entity_requirement_type' => EntityRequirementType::None,
                            'requires_cost_center' => false,
                            'has_automatic_destination' => false,
                            'requires_cash_flow_area' => false,
                            'cash_flow_category' => CashFlowCategory::None,
                            'financial_statement_rubric' => FinancialStatementRubric::None,
                            'is_operational' => false,
                            'is_active' => true,
                        ]
                    );

                    foreach ($lvl2Data['children'] as $lvl3Data) {
                        $lvl3Path = $lvl2->path . '.' . $lvl3Data['code'];
                        $lvl3 = AccountingAccount::query()->updateOrCreate(
                            ['code' => $lvl3Data['code']],
                            [
                                'parent_id' => $lvl2->id,
                                'name' => $lvl3Data['name'],
                                'level' => 3,
                                'path' => $lvl3Path,
                                'account_type' => $lvl1Data['account_type'],
                                'balance_type' => $lvl1Data['balance_type'],
                                'currency_mode' => AccountCurrencyMode::Pen,
                                'requires_currency' => false,
                                'requires_auxiliary' => false,
                                'entity_requirement_type' => EntityRequirementType::None,
                                'requires_cost_center' => false,
                                'has_automatic_destination' => false,
                                'requires_cash_flow_area' => false,
                                'cash_flow_category' => CashFlowCategory::None,
                                'financial_statement_rubric' => FinancialStatementRubric::None,
                                'is_operational' => false,
                                'is_active' => true,
                            ]
                        );

                        foreach ($lvl3Data['children'] as $lvl4Data) {
                            $lvl4Path = $lvl3->path . '.' . $lvl4Data['code'];
                            /** @var AccountCurrencyMode $currencyMode */
                            $currencyMode = $lvl4Data['currency_mode'];
                            /** @var EntityRequirementType $entityType */
                            $entityType = $lvl4Data['entity_type'];

                            AccountingAccount::query()->updateOrCreate(
                                ['code' => $lvl4Data['code']],
                                [
                                    'parent_id' => $lvl3->id,
                                    'name' => $lvl4Data['name'],
                                    'level' => 4,
                                    'path' => $lvl4Path,
                                    'account_type' => $lvl1Data['account_type'],
                                    'balance_type' => $lvl1Data['balance_type'],
                                    'currency_mode' => $currencyMode,
                                    'requires_currency' => $currencyMode->requiresForeignExchange(),
                                    'requires_auxiliary' => $entityType->requiresEntity(),
                                    'entity_requirement_type' => $entityType,
                                    'requires_cost_center' => $lvl4Data['cost_center'],
                                    'has_automatic_destination' => $lvl4Data['auto_dest'],
                                    'destination_debit_code' => $lvl4Data['dest_debit'],
                                    'destination_credit_code' => $lvl4Data['dest_credit'],
                                    'requires_cash_flow_area' => $lvl4Data['cash_flow_area'],
                                    'cash_flow_category' => $lvl4Data['cash_flow_cat'],
                                    'financial_statement_rubric' => $lvl4Data['fs_rubric'],
                                    'is_operational' => true,
                                    'is_active' => true,
                                ]
                            );
                        }
                    }
                }
            }
        });
    }
}
