<?php

declare(strict_types=1);

namespace App\Services\Company;

use App\Models\CashFlowArea;
use App\Models\CompanyArea;
use App\Support\Tenant\TenantContext;

/**
 * Parámetros operativos iniciales, EXCLUSIVAMENTE de la nueva empresa.
 * No inserta terceros, representantes, bancos, credenciales ni tasas fiscales.
 * Re-ejecutable: firstOrCreate conserva cambios que hizo el usuario.
 *
 * Los centros de costo se crean solo cuando se configuran cuentas operativas
 * reales de destino (clase 9 y 79) del mismo tenant; no se inventan 94/95.
 */
final readonly class CompanyOperationalDefaultsService
{
    public function __construct(private TenantContext $tenantContext) {}

    /** @return list<array{code:string,name:string,description:string}> */
    public static function organizationAreas(): array
    {
        return [
            ['code'=>'DIR-01','name'=>'Dirección General y Gerencia','description'=>'Alta dirección estratégica'],
            ['code'=>'ADM-01','name'=>'Administración Central','description'=>'Gestión administrativa y recursos'],
            ['code'=>'FIN-01','name'=>'Finanzas y Tesorería','description'=>'Gestión de liquidez y bancos'],
            ['code'=>'CON-01','name'=>'Contabilidad y Tributación','description'=>'Contabilidad financiera y fiscalidad'],
            ['code'=>'LOG-01','name'=>'Logística y Almacén','description'=>'Compras, inventarios y despachos'],
            ['code'=>'COM-01','name'=>'Comercial y Ventas','description'=>'Fuerza de ventas y canales de distribución'],
        ];
    }

    /** @return list<array{code:string,name:string,activity_category:string,flow_direction:string}> */
    public static function cashFlowAreas(): array
    {
        return [
            ['code'=>'OPE-01','name'=>'Cobranza por Venta de Mercaderías y Servicios','activity_category'=>'OPERATING','flow_direction'=>'INFLOW'],
            ['code'=>'OPE-02','name'=>'Pagos a Proveedores de Bienes y Servicios','activity_category'=>'OPERATING','flow_direction'=>'OUTFLOW'],
            ['code'=>'OPE-03','name'=>'Pago de Remuneraciones y Beneficios Sociales','activity_category'=>'OPERATING','flow_direction'=>'OUTFLOW'],
            ['code'=>'OPE-04','name'=>'Pago de Tributos y Contribuciones','activity_category'=>'OPERATING','flow_direction'=>'OUTFLOW'],
            ['code'=>'INV-01','name'=>'Adquisición de Propiedades, Planta y Equipo','activity_category'=>'INVESTING','flow_direction'=>'OUTFLOW'],
            ['code'=>'INV-02','name'=>'Venta de Activos Inmovilizados','activity_category'=>'INVESTING','flow_direction'=>'INFLOW'],
            ['code'=>'FIN-01','name'=>'Aportes de Capital Social','activity_category'=>'FINANCING','flow_direction'=>'INFLOW'],
            ['code'=>'FIN-02','name'=>'Préstamos Bancarios y Amortización Financiera','activity_category'=>'FINANCING','flow_direction'=>'BOTH'],
            ['code'=>'EFE-01','name'=>'Fondos Disponibles en Caja y Cuentas Corrientes','activity_category'=>'CASH_EQUIVALENT','flow_direction'=>'BOTH'],
        ];
    }

    public function installForCompany(int $companyId): void
    {
        $this->tenantContext->run($companyId, static function (): void {
            foreach (self::organizationAreas() as $area) {
                CompanyArea::query()->firstOrCreate(
                    ['code' => $area['code']],
                    ['name' => $area['name'], 'description' => $area['description'], 'is_active' => true],
                );
            }

            foreach (self::cashFlowAreas() as $area) {
                CashFlowArea::query()->firstOrCreate(
                    ['code' => $area['code']],
                    [
                        'name' => $area['name'],
                        'activity_category' => $area['activity_category'],
                        'flow_direction' => $area['flow_direction'],
                        'is_active' => true,
                    ],
                );
            }
        });
    }
}
