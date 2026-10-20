<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Catálogos Globales y Normativos (No dependen de tenant)
        $this->call([
            CurrenciesSeeder::class,
            SunatCatalogSeeder::class,
            AccountingStandardsAndRubricsSeeder::class,
            TaxCalendarDeadlinesSeeder::class,
        ]);

        // 2. Empresa de demostración y Usuario Administrador
        $company = Company::query()->firstOrCreate(
            ['tax_id' => '20601234567'],
            [
                'business_name' => 'Corporación Peruana de Distribución S.A.C.',
                'legal_address' => 'Av. Javier Prado Este 2450, San Borja, Lima',
                'is_active' => true,
            ]
        );

        $user = User::query()->firstOrCreate(
            ['email' => 'contador@corporacionperu.pe'],
            [
                'name' => 'Contador General CPC',
                'password' => 'Contabilidad2026!',
            ]
        );

        /** @var TenantContext $tenantContext */
        $tenantContext = app(TenantContext::class);

        $tenantContext->run($company->id, function () use ($company, $user): void {
            CompanyUser::query()->firstOrCreate(
                [
                    'company_id' => $company->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => CompanyRole::ChiefAccountant,
                    'is_active' => true,
                ]
            );

            $user->forceFill(['current_company_id' => $company->id])->save();
        });

        // 3. Catálogo Matriz PCGE (2019 y 2026) e inicialización del Plan Contable PCGE 2019 de la empresa
        app(MasterChartOfAccountsSeeder::class)->runForCompany($company->id, 'PCGE_2019', true);

        // 4. Parámetros de Empresa, Centros de Costos, Áreas de Efectivo y Directorio de Terceros
        app(CompanySetupSeeder::class)->runForCompany($company->id);
    }
}
