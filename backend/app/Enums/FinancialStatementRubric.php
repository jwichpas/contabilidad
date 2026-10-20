<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rubro oficial de presentación en los Estados Financieros al que se mapea cada cuenta operativa.
 * Ejemplo: 1041 -> ESF_ACT_CORR_EFE ("Efectivo y Equivalentes de Efectivo").
 */
enum FinancialStatementRubric: string
{
    case None = 'NONE';

    // Estado de Situación Financiera (Balance General) - Activo
    case CashAndEquivalents = 'ESF_ACT_CORR_EFE';
    case TradeReceivables = 'ESF_ACT_CORR_CXC_COM';
    case OtherReceivables = 'ESF_ACT_CORR_OTR_CXC';
    case Inventories = 'ESF_ACT_CORR_INV';
    case PropertyPlantEquipment = 'ESF_ACT_NCORR_IME';
    case IntangibleAssets = 'ESF_ACT_NCORR_INT';

    // Estado de Situación Financiera - Pasivo y Patrimonio
    case TaxesAndContributionsPayable = 'ESF_PAS_CORR_TRIB';
    case PayrollPayable = 'ESF_PAS_CORR_REM';
    case TradePayables = 'ESF_PAS_CORR_CXP_COM';
    case FinancialObligations = 'ESF_PAS_OBLIG_FIN';
    case IssuedCapital = 'ESF_PAT_CAP';
    case RetainedEarnings = 'ESF_PAT_RES_ACUM';

    // Estado de Resultados por Naturaleza (Elemento 6 y 7)
    case NetSalesRevenue = 'ER_ING_VENTAS_NETAS';
    case OtherOperatingIncome = 'ER_ING_OTROS_OPER';
    case MerchandisePurchases = 'ERN_COMPRAS_MERC';
    case PersonnelExpenses = 'ERN_GASTOS_PERSONAL';
    case ThirdPartyServices = 'ERN_SERV_TERCEROS';
    case TaxExpenses = 'ERN_GASTOS_TRIBUTOS';
    case OtherManagementExpenses = 'ERN_OTROS_GASTOS_GESTION';

    // Estado de Resultados por Función (Elemento 9: 94 / 95 y Costo de Ventas 69)
    case CostOfSales = 'ERF_COSTO_VENTAS';
    case AdministrativeExpenses = 'ERF_GASTOS_ADMIN_94';
    case SellingExpenses = 'ERF_GASTOS_VENTAS_95';
    case ImputableChargesControl = 'CONTROL_CARGAS_IMPUTABLES_79';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Sin rubro de Estado Financiero (Cuenta de Agrupación)',
            self::CashAndEquivalents => 'Efectivo y Equivalentes de Efectivo',
            self::TradeReceivables => 'Cuentas por Cobrar Comerciales (Neto)',
            self::OtherReceivables => 'Otras Cuentas por Cobrar',
            self::Inventories => 'Inventarios / Existencias',
            self::PropertyPlantEquipment => 'Propiedades, Planta y Equipo (Neto)',
            self::IntangibleAssets => 'Activos Intangibles',
            self::TaxesAndContributionsPayable => 'Tributos y Aportes por Pagar',
            self::PayrollPayable => 'Remuneraciones y Participaciones por Pagar',
            self::TradePayables => 'Cuentas por Pagar Comerciales',
            self::FinancialObligations => 'Obligaciones Financieras',
            self::IssuedCapital => 'Capital Social Emitido',
            self::RetainedEarnings => 'Resultados Acumulados',
            self::NetSalesRevenue => 'Ventas Netas de Bienes y Servicios',
            self::OtherOperatingIncome => 'Otros Ingresos de Gestión',
            self::MerchandisePurchases => 'Compras de Mercaderías (Naturaleza)',
            self::PersonnelExpenses => 'Gastos de Personal y Directores (Naturaleza)',
            self::ThirdPartyServices => 'Servicios Prestados por Terceros (Naturaleza)',
            self::TaxExpenses => 'Tributos a Cargo de la Empresa (Naturaleza)',
            self::OtherManagementExpenses => 'Otros Gastos de Gestión (Naturaleza)',
            self::CostOfSales => 'Costo de Ventas (Función)',
            self::AdministrativeExpenses => 'Gastos de Administración - Cuenta 94 (Función)',
            self::SellingExpenses => 'Gastos de Ventas y Distribución - Cuenta 95 (Función)',
            self::ImputableChargesControl => 'Cargas Imputables a Cuentas de Costos y Gastos (79)',
        };
    }

    public function statementGroup(): string
    {
        return match ($this) {
            self::None => 'NONE',
            self::CashAndEquivalents,
            self::TradeReceivables,
            self::OtherReceivables,
            self::Inventories,
            self::PropertyPlantEquipment,
            self::IntangibleAssets,
            self::TaxesAndContributionsPayable,
            self::PayrollPayable,
            self::TradePayables,
            self::FinancialObligations,
            self::IssuedCapital,
            self::RetainedEarnings => 'STATEMENT_OF_FINANCIAL_POSITION',

            self::NetSalesRevenue,
            self::OtherOperatingIncome,
            self::MerchandisePurchases,
            self::PersonnelExpenses,
            self::ThirdPartyServices,
            self::TaxExpenses,
            self::OtherManagementExpenses => 'INCOME_STATEMENT_BY_NATURE',

            self::CostOfSales,
            self::AdministrativeExpenses,
            self::SellingExpenses => 'INCOME_STATEMENT_BY_FUNCTION',

            self::ImputableChargesControl => 'ANALYTICAL_BRIDGE_79',
        };
    }
}
