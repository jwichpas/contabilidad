<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AccountingAccount;
use App\Models\CashFlowArea;
use App\Models\Company;
use App\Models\CompanyArea;
use App\Models\CompanySetting;
use App\Models\CostCenter;
use App\Models\Currency;
use App\Models\Entity;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Seeder para la configuración operativa inicial de una Empresa:
 * - Áreas Organizacionales (`company_areas`)
 * - Áreas de Flujo de Efectivo NIC 7 (`cash_flow_areas`)
 * - Centros de Costos con transferencia a Cuentas 94 / 95 vs 79 (`cost_centers`)
 * - Parámetros Contables y Tributarios (`company_settings`)
 * - Directorio Maestro de Terceros (`entities`: Clientes, Proveedores, Bancos, SUNAT, Personal)
 */
class CompanySetupSeeder extends Seeder
{
    public function runForCompany(int $companyId): void
    {
        /** @var TenantContext $tenantContext */
        $tenantContext = app(TenantContext::class);

        $tenantContext->run($companyId, function () use ($companyId): void {
            $this->seedCompanyAreas($companyId);
            $this->seedCashFlowAreas($companyId);
            $this->seedCostCenters($companyId);
            $this->seedCompanySettings($companyId);
            $this->seedEntities($companyId);
        });
    }

    private function seedCompanyAreas(int $companyId): void
    {
        $areas = [
            ['code' => 'DIR-01', 'name' => 'Dirección General y Gerencia', 'description' => 'Alta dirección estratégica'],
            ['code' => 'ADM-01', 'name' => 'Administración Central', 'description' => 'Gestión administrativa y recursos'],
            ['code' => 'FIN-01', 'name' => 'Finanzas y Tesorería', 'description' => 'Gestión de liquidez y bancos'],
            ['code' => 'CON-01', 'name' => 'Contabilidad y Tributación', 'description' => 'Contabilidad financiera y fiscalidad SUNAT'],
            ['code' => 'LOG-01', 'name' => 'Logística y Almacén', 'description' => 'Compras, inventarios y despachos'],
            ['code' => 'COM-01', 'name' => 'Comercial y Ventas', 'description' => 'Fuerza de ventas y canales de distribución'],
        ];

        foreach ($areas as $area) {
            CompanyArea::query()->updateOrCreate(
                ['company_id' => $companyId, 'code' => $area['code']],
                array_merge($area, ['is_active' => true])
            );
        }
    }

    private function seedCashFlowAreas(int $companyId): void
    {
        $cashFlowAreas = [
            [
                'code' => 'OPE-01',
                'name' => 'Cobranza por Venta de Mercaderías y Servicios',
                'activity_category' => 'OPERATING',
                'flow_direction' => 'INFLOW',
            ],
            [
                'code' => 'OPE-02',
                'name' => 'Pagos a Proveedores de Bienes y Servicios',
                'activity_category' => 'OPERATING',
                'flow_direction' => 'OUTFLOW',
            ],
            [
                'code' => 'OPE-03',
                'name' => 'Pago de Remuneraciones y Beneficios Sociales',
                'activity_category' => 'OPERATING',
                'flow_direction' => 'OUTFLOW',
            ],
            [
                'code' => 'OPE-04',
                'name' => 'Pago de Tributos y Contribuciones a SUNAT',
                'activity_category' => 'OPERATING',
                'flow_direction' => 'OUTFLOW',
            ],
            [
                'code' => 'INV-01',
                'name' => 'Adquisición de Propiedades, Planta y Equipo',
                'activity_category' => 'INVESTING',
                'flow_direction' => 'OUTFLOW',
            ],
            [
                'code' => 'INV-02',
                'name' => 'Venta de Activos Inmovilizados',
                'activity_category' => 'INVESTING',
                'flow_direction' => 'INFLOW',
            ],
            [
                'code' => 'FIN-01',
                'name' => 'Aportes de Capital Social de Accionistas',
                'activity_category' => 'FINANCING',
                'flow_direction' => 'INFLOW',
            ],
            [
                'code' => 'FIN-02',
                'name' => 'Préstamos Bancarios y Amortización Financiera',
                'activity_category' => 'FINANCING',
                'flow_direction' => 'BOTH',
            ],
            [
                'code' => 'EFE-01',
                'name' => 'Fondos Disponibles en Caja y Cuentas Corrientes',
                'activity_category' => 'CASH_EQUIVALENT',
                'flow_direction' => 'BOTH',
            ],
        ];

        foreach ($cashFlowAreas as $cfa) {
            CashFlowArea::query()->updateOrCreate(
                ['company_id' => $companyId, 'code' => $cfa['code']],
                array_merge($cfa, ['is_active' => true])
            );
        }
    }

    private function seedCostCenters(int $companyId): void
    {
        $acc9411 = AccountingAccount::query()->where('company_id', $companyId)->where('code', '9411')->first();
        $acc9511 = AccountingAccount::query()->where('company_id', $companyId)->where('code', '9511')->first();
        $acc7911 = AccountingAccount::query()->where('company_id', $companyId)->where('code', '7911')->first();

        if (! $acc9411 || ! $acc9511 || ! $acc7911) {
            return;
        }

        $costCenters = [
            [
                'code' => 'CC-ADM',
                'name' => 'Centro de Costos de Administración General',
                'destination_debit_account_id' => $acc9411->id,
                'destination_credit_account_id' => $acc7911->id,
            ],
            [
                'code' => 'CC-VTA',
                'name' => 'Centro de Costos Comercial y Ventas',
                'destination_debit_account_id' => $acc9511->id,
                'destination_credit_account_id' => $acc7911->id,
            ],
        ];

        foreach ($costCenters as $cc) {
            CostCenter::query()->updateOrCreate(
                ['company_id' => $companyId, 'code' => $cc['code']],
                array_merge($cc, ['is_active' => true])
            );
        }
    }

    private function seedCompanySettings(int $companyId): void
    {
        $pen = Currency::query()->where('code', 'PEN')->firstOrFail();
        $usd = Currency::query()->where('code', 'USD')->first();

        $acc4011 = AccountingAccount::query()->where('company_id', $companyId)->where('code', '4011')->first();
        $acc7761 = AccountingAccount::query()->where('company_id', $companyId)->where('code', '7761')->first();
        $acc6761 = AccountingAccount::query()->where('company_id', $companyId)->where('code', '6761')->first();
        $acc7911 = AccountingAccount::query()->where('company_id', $companyId)->where('code', '7911')->first();
        $acc5911 = AccountingAccount::query()->where('company_id', $companyId)->where('code', '5911')->first();

        CompanySetting::query()->updateOrCreate(
            ['company_id' => $companyId],
            [
                'base_currency_id' => $pen->id,
                'secondary_currency_id' => $usd?->id,
                'tax_regime' => 'GENERAL',
                'default_vat_rate' => '18.00',
                'is_retention_agent' => false,
                'is_perception_agent' => false,
                'is_good_taxpayer' => false,
                'is_sire_obliged' => true,
                'is_ple_obliged' => false,
                'legal_representative_name' => 'Carlos Mendoza Alarcón',
                'legal_rep_identity_doc_code' => '1',
                'legal_representative_id_number' => '40123456',
                'chief_accountant_name' => 'María Elena Ramos Morales',
                'chief_accountant_cpc_number' => 'CPC-45892',
                'sales_vat_account_id' => $acc4011?->id,
                'purchase_vat_account_id' => $acc4011?->id,
                'fx_gain_account_id' => $acc7761?->id,
                'fx_loss_account_id' => $acc6761?->id,
                'cost_absorption_account_id' => $acc7911?->id,
                'retained_earnings_account_id' => $acc5911?->id,
            ]
        );
    }

    private function seedEntities(int $companyId): void
    {
        $entities = [
            // 1. Banco BCP
            [
                'identity_document_type_code' => '6',
                'tax_id' => '20100047218',
                'legal_name' => 'BANCO DE CREDITO DEL PERU',
                'commercial_name' => 'BCP',
                'fiscal_address' => 'Calle Centenario 156, La Molina, Lima',
                'country_code' => 'PER',
                'is_client' => false,
                'is_supplier' => false,
                'is_employee' => false,
                'is_financial_institution' => true,
                'is_shareholder' => false,
                'is_tax_authority' => false,
                'bank_code' => '02',
                'bank_account_number' => '191-12345678-0-12',
                'bank_cci_number' => '00219100123456780128',
                'is_related_party' => false,
                'is_retention_agent' => true,
                'is_good_taxpayer' => true,
                'has_detraction_account' => false,
            ],
            // 2. SUNAT
            [
                'identity_document_type_code' => '6',
                'tax_id' => '20131312955',
                'legal_name' => 'SUPERINTENDENCIA NACIONAL DE ADUANAS Y DE ADMINISTRACION TRIBUTARIA',
                'commercial_name' => 'SUNAT',
                'fiscal_address' => 'Av. Garcilaso de la Vega 1472, Cercado de Lima',
                'country_code' => 'PER',
                'is_client' => false,
                'is_supplier' => false,
                'is_employee' => false,
                'is_financial_institution' => false,
                'is_shareholder' => false,
                'is_tax_authority' => true,
                'is_related_party' => false,
                'is_retention_agent' => false,
                'is_good_taxpayer' => false,
                'has_detraction_account' => false,
            ],
            // 3. Banco de la Nación (Detracciones)
            [
                'identity_document_type_code' => '6',
                'tax_id' => '20100030595',
                'legal_name' => 'BANCO DE LA NACION',
                'commercial_name' => 'Banco de la Nación',
                'fiscal_address' => 'Av. Javier Prado Este 2499, San Borja, Lima',
                'country_code' => 'PER',
                'is_client' => false,
                'is_supplier' => false,
                'is_employee' => false,
                'is_financial_institution' => true,
                'is_shareholder' => false,
                'is_tax_authority' => false,
                'bank_code' => '18',
                'is_related_party' => false,
                'is_retention_agent' => false,
                'is_good_taxpayer' => false,
                'has_detraction_account' => false,
            ],
            // 4. Cliente Comercial
            [
                'identity_document_type_code' => '6',
                'tax_id' => '20512345678',
                'legal_name' => 'INVERSIONES Y COMERCIO GLOBAL S.A.C.',
                'commercial_name' => 'GLOBAL CORP',
                'fiscal_address' => 'Av. El Derby 055, Surco, Lima',
                'country_code' => 'PER',
                'email' => 'facturacion@globalcorp.pe',
                'phone' => '01-440-1234',
                'is_client' => true,
                'is_supplier' => false,
                'is_employee' => false,
                'is_financial_institution' => false,
                'is_shareholder' => false,
                'is_tax_authority' => false,
                'is_related_party' => false,
                'is_retention_agent' => false,
                'is_good_taxpayer' => true,
                'has_detraction_account' => false,
            ],
            // 5. Proveedor de Servicios Básicos (Luz/Agua)
            [
                'identity_document_type_code' => '6',
                'tax_id' => '20100017491',
                'legal_name' => 'ENEL DISTRIBUCION PERU S.A.A.',
                'commercial_name' => 'ENEL',
                'fiscal_address' => 'Calle César Vallejo 100, San Miguel, Lima',
                'country_code' => 'PER',
                'email' => 'atencion@enel.com.pe',
                'phone' => '01-517-1717',
                'is_client' => false,
                'is_supplier' => true,
                'is_employee' => false,
                'is_financial_institution' => false,
                'is_shareholder' => false,
                'is_tax_authority' => false,
                'is_related_party' => false,
                'is_retention_agent' => true,
                'is_good_taxpayer' => true,
                'has_detraction_account' => true,
                'detraction_account_number' => '00-000-123456',
            ],
            // 6. Proveedor de Transporte y Logística con Detracción
            [
                'identity_document_type_code' => '6',
                'tax_id' => '20555667788',
                'legal_name' => 'LOGISTICA Y TRANSPORTES TRANSANDINO S.A.C.',
                'commercial_name' => 'TRANSANDINO',
                'fiscal_address' => 'Av. Elmer Faucett 2340, Callao',
                'country_code' => 'PER',
                'email' => 'operaciones@transandino.pe',
                'phone' => '01-574-8899',
                'is_client' => false,
                'is_supplier' => true,
                'is_employee' => false,
                'is_financial_institution' => false,
                'is_shareholder' => false,
                'is_tax_authority' => false,
                'bank_code' => '02',
                'bank_account_number' => '193-98765432-0-55',
                'bank_cci_number' => '00219300987654320552',
                'is_related_party' => false,
                'is_retention_agent' => false,
                'is_good_taxpayer' => false,
                'has_detraction_account' => true,
                'detraction_account_number' => '00-019-987654',
            ],
            // 7. Empleado / Administrador
            [
                'identity_document_type_code' => '1',
                'tax_id' => '44556677',
                'legal_name' => 'JUAN CARLOS PEREZ GOMEZ',
                'commercial_name' => null,
                'fiscal_address' => 'Av. República de Panamá 3560, San Isidro, Lima',
                'country_code' => 'PER',
                'email' => 'jperez@corporacionperu.pe',
                'phone' => '998-877-665',
                'is_client' => false,
                'is_supplier' => false,
                'is_employee' => true,
                'is_financial_institution' => false,
                'is_shareholder' => false,
                'is_tax_authority' => false,
                'is_related_party' => false,
                'is_retention_agent' => false,
                'is_good_taxpayer' => false,
                'has_detraction_account' => false,
            ],
        ];

        foreach ($entities as $entity) {
            Entity::query()->updateOrCreate(
                [
                    'company_id' => $companyId,
                    'identity_document_type_code' => $entity['identity_document_type_code'],
                    'tax_id' => $entity['tax_id'],
                ],
                array_merge($entity, ['is_active' => true])
            );
        }
    }
}
