<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Accounting\CreateAccountingAccountController;
use App\Http\Controllers\Api\V1\Accounting\ChangeFiscalPeriodStatusController;
use App\Http\Controllers\Api\V1\Accounting\ListFiscalPeriodsController;
use App\Http\Controllers\Api\V1\Accounting\OpenFiscalPeriodController;
use App\Http\Controllers\Api\V1\Accounting\CreateJournalEntryController;
use App\Http\Controllers\Api\V1\Accounting\DeactivateAccountingAccountController;
use App\Http\Controllers\Api\V1\Accounting\GetFinancialSnapshotController;
use App\Http\Controllers\Api\V1\Accounting\GetCashFlowStatementController;
use App\Http\Controllers\Api\V1\Accounting\GetGeneralLedgerController;
use App\Http\Controllers\Api\V1\Accounting\GetNextJournalEntryNumberController;
use App\Http\Controllers\Api\V1\Accounting\GetJournalEditorBootstrapController;
use App\Http\Controllers\Api\V1\Accounting\ListAccountingAccountTreeController;
use App\Http\Controllers\Api\V1\Accounting\ListJournalEntriesController;
use App\Http\Controllers\Api\V1\Accounting\ReverseJournalEntryController;
use App\Http\Controllers\Api\V1\Accounting\ListOperationalAccountsController;
use App\Http\Controllers\Api\V1\Accounting\UpdateAccountingAccountController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Catalogs\ListAccountingStandardsController;
use App\Http\Controllers\Api\V1\Catalogs\ListChartOfAccountsTemplatesController;
use App\Http\Controllers\Api\V1\Catalogs\ListCurrenciesController;
use App\Http\Controllers\Api\V1\Catalogs\ListFinancialStatementRubricsController;
use App\Http\Controllers\Api\V1\Catalogs\ListSunatCatalogController;
use App\Http\Controllers\Api\V1\Catalogs\ListSeeCatalogController;
use App\Http\Controllers\Api\V1\Catalogs\ListSeeCatalogsController;
use App\Http\Controllers\Api\V1\Catalogs\ListSeeUbigeosController;
use App\Http\Controllers\Api\V1\Catalogs\ListSunatRucReferenceController;
use App\Http\Controllers\Api\V1\Catalogs\ListTaxCalendarDeadlinesController;
use App\Http\Controllers\Api\V1\Company\GetCompanyExchangeRatesController;
use App\Http\Controllers\Api\V1\Company\GetCompanySettingsController;
use App\Http\Controllers\Api\V1\Company\CompanySeeVisibilityController;
use App\Http\Controllers\Api\V1\Company\InitializeCompanyChartOfAccountsController;
use App\Http\Controllers\Api\V1\Company\ListCashFlowAreasController;
use App\Http\Controllers\Api\V1\Company\ListCompanyAreasController;
use App\Http\Controllers\Api\V1\Company\ListCostCentersController;
use App\Http\Controllers\Api\V1\Company\CostCenterMasterController;
use App\Http\Controllers\Api\V1\Company\CompanySubdiaryMasterController;
use App\Http\Controllers\Api\V1\Company\ListEntitiesController;
use App\Http\Controllers\Api\V1\Company\EntityMasterController;
use App\Http\Controllers\Api\V1\Company\CompanyFiscalProfileController;
use App\Http\Controllers\Api\V1\Company\SaveCompanyExchangeRatesController;
use App\Http\Controllers\Api\V1\Company\UpdateCompanySettingsController;
use App\Http\Controllers\Api\V1\Tenant\CompanyOnboardingCapabilityController;
use App\Http\Controllers\Api\V1\Tenant\CreateCompanyFromWizardController;
use App\Http\Controllers\Api\V1\Tenant\SwitchActiveCompanyController;
use App\Http\Middleware\JournalAuthenticatedTiming;
use App\Http\Middleware\JournalAuthenticationStartTiming;
use App\Http\Middleware\SetTenantContext;
use App\Http\Middleware\RequireCompanyRole;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Autenticación pública stateless (Sanctum) protegida contra fuerza bruta (ISO/IEC 27001 Control 8.5)
    Route::post('/auth/login', LoginController::class)
        ->middleware('throttle:10,1')
        ->name('login');

    Route::middleware([JournalAuthenticationStartTiming::class, 'auth:sanctum'])->group(function (): void {
        Route::post('/auth/logout', LogoutController::class)
            ->name('api.v1.auth.logout');

        // Empresas y cambio de empresa predeterminada del usuario autenticado
        Route::get('/tenant/companies', \App\Http\Controllers\Api\V1\Tenant\ListUserCompaniesController::class)
            ->name('api.v1.tenant.companies');
        Route::get('/tenant/company-onboarding/capabilities', CompanyOnboardingCapabilityController::class)
            ->name('api.v1.tenant.company-onboarding.capabilities');
        Route::post('/tenant/companies', CreateCompanyFromWizardController::class)
            ->middleware('throttle:5,1')
            ->name('api.v1.tenant.companies.store');
        Route::post('/tenant/switch-company', SwitchActiveCompanyController::class)
            ->middleware(JournalAuthenticatedTiming::class)
            ->name('api.v1.tenant.switch-company');

        // Catálogos Globales y Normativos Oficiales
        Route::prefix('catalogs')->group(function (): void {
            Route::get('/currencies', ListCurrenciesController::class)
                ->name('api.v1.catalogs.currencies');

            Route::get('/sunat/{tableNumber}', ListSunatCatalogController::class)
                ->name('api.v1.catalogs.sunat');

            Route::get('/sunat-see', ListSeeCatalogsController::class)
                ->name('api.v1.catalogs.sunat-see.index');

            Route::get('/sunat-see/ubigeos', ListSeeUbigeosController::class)
                ->name('api.v1.catalogs.sunat-see.ubigeos');
            Route::get('/sunat-see/{number}', ListSeeCatalogController::class)
                ->where('number', '[0-9]{2}')
                ->name('api.v1.catalogs.sunat-see');

            // Anexos SUNAT del RUC e INEI CIIU Rev.4: no son códigos PLE.
            Route::get('/sunat-ruc/{dataset}', ListSunatRucReferenceController::class)
                ->whereIn('dataset',['contributor-types','ciiu','tax-codes'])
                ->name('api.v1.catalogs.sunat-ruc');

            Route::get('/accounting-standards', ListAccountingStandardsController::class)
                ->name('api.v1.catalogs.accounting-standards');

            Route::get('/financial-statement-rubrics', ListFinancialStatementRubricsController::class)
                ->name('api.v1.catalogs.financial-statement-rubrics');

            Route::get('/tax-calendar-deadlines/{year?}', ListTaxCalendarDeadlinesController::class)
                ->whereNumber('year')
                ->name('api.v1.catalogs.tax-calendar-deadlines');

            Route::get('/chart-of-accounts-templates', ListChartOfAccountsTemplatesController::class)
                ->name('api.v1.catalogs.chart-of-accounts-templates');
        });

        // Rutas protegidas por contexto Multi-Tenant (SetTenantContext + PostgreSQL RLS)
        Route::middleware([JournalAuthenticatedTiming::class, SetTenantContext::class])->group(function (): void {
            // Plan de Cuentas Contables (PCGE 2026)
            Route::prefix('accounting/accounts')->group(function (): void {
                Route::get('/tree', ListAccountingAccountTreeController::class)
                    ->name('api.v1.accounting.accounts.tree');

                Route::get('/operational', ListOperationalAccountsController::class)
                    ->name('api.v1.accounting.accounts.operational');

                Route::post('/', CreateAccountingAccountController::class)
                    ->middleware(RequireCompanyRole::class.':ADMIN,CHIEF_ACCOUNTANT')
                    ->name('api.v1.accounting.accounts.store');

                Route::put('/{accountId}', UpdateAccountingAccountController::class)
                    ->middleware(RequireCompanyRole::class.':ADMIN,CHIEF_ACCOUNTANT')
                    ->whereNumber('accountId')
                    ->name('api.v1.accounting.accounts.update');

                Route::patch('/{accountId}/deactivate', DeactivateAccountingAccountController::class)
                    ->middleware(RequireCompanyRole::class.':ADMIN,CHIEF_ACCOUNTANT')
                    ->whereNumber('accountId')
                    ->name('api.v1.accounting.accounts.deactivate');
            });

            // Estado real de períodos (sin generar meses ficticios).
            Route::get('/accounting/fiscal-periods', ListFiscalPeriodsController::class)
                ->name('api.v1.accounting.fiscal-periods.index');

            // Explicit opening is never performed by GET or a bulk seeder.
            Route::post('/accounting/fiscal-periods', OpenFiscalPeriodController::class)
                ->middleware(RequireCompanyRole::class.':ADMIN,CHIEF_ACCOUNTANT')
                ->name('api.v1.accounting.fiscal-periods.open');

            // Cierre / bloqueo / reapertura de períodos contables F2.3.
            // Solo ADMIN o CHIEF_ACCOUNTANT de la empresa activa. RLS applies.
            Route::post('/accounting/fiscal-periods/{periodId}/{action}', ChangeFiscalPeriodStatusController::class)
                ->middleware(RequireCompanyRole::class.':ADMIN,CHIEF_ACCOUNTANT')
                ->whereNumber('periodId')
                ->whereIn('action',['close','lock','reopen'])
                ->name('api.v1.accounting.fiscal-periods.transition');

            Route::get('/accounting/reports/financial-snapshot', GetFinancialSnapshotController::class)
                ->name('api.v1.accounting.reports.financial-snapshot');
            Route::get('/accounting/reports/cash-flow', GetCashFlowStatementController::class)
                ->name('api.v1.accounting.reports.cash-flow');
            Route::get('/accounting/reports/general-ledger', GetGeneralLedgerController::class)
                ->name('api.v1.accounting.reports.general-ledger');

            // Asientos Contables / Libro Diario Bimoneda (Voucher)
            Route::prefix('accounting/journal-entries')->group(function (): void {
                Route::get('/editor-bootstrap', GetJournalEditorBootstrapController::class)
                    ->name('api.v1.accounting.journal-entries.editor-bootstrap');

                Route::get('/', ListJournalEntriesController::class)
                    ->name('api.v1.accounting.journal-entries.index');

                Route::get('/next-number', GetNextJournalEntryNumberController::class)
                    ->name('api.v1.accounting.journal-entries.next-number');

                Route::post('/', CreateJournalEntryController::class)
                    ->middleware(RequireCompanyRole::class.':ADMIN,CHIEF_ACCOUNTANT,ACCOUNTANT')
                    ->name('api.v1.accounting.journal-entries.store');

                Route::post('/{entryId}/reverse', ReverseJournalEntryController::class)
                    ->middleware(RequireCompanyRole::class.':ADMIN,CHIEF_ACCOUNTANT,ACCOUNTANT')
                    ->whereNumber('entryId')
                    ->name('api.v1.accounting.journal-entries.reverse');
            });

            Route::get('/company/subdiaries', [CompanySubdiaryMasterController::class, 'index'])
                ->name('api.v1.company.subdiaries.index');
            Route::post('/company/subdiaries', [CompanySubdiaryMasterController::class, 'store'])
                ->middleware(RequireCompanyRole::class.':ADMIN,CHIEF_ACCOUNTANT')
                ->name('api.v1.company.subdiaries.store');
            Route::put('/company/subdiaries/{subdiaryId}', [CompanySubdiaryMasterController::class, 'update'])
                ->middleware(RequireCompanyRole::class.':ADMIN,CHIEF_ACCOUNTANT')
                ->whereNumber('subdiaryId');
            Route::patch('/company/subdiaries/{subdiaryId}/status', [CompanySubdiaryMasterController::class, 'setStatus'])
                ->middleware(RequireCompanyRole::class.':ADMIN,CHIEF_ACCOUNTANT')
                ->whereNumber('subdiaryId');

            // Configuración, Áreas, Centros de Costos y Terceros de la Empresa
            Route::prefix('company')->group(function (): void {
                Route::get('/see-visibility', [CompanySeeVisibilityController::class, 'index'])
                    ->name('api.v1.company.see-visibility.index');
                Route::put('/see-visibility/{number}', [CompanySeeVisibilityController::class, 'update'])
                    ->whereIn('number', ['03', '04'])
                    ->middleware(RequireCompanyRole::class.':ADMIN,CHIEF_ACCOUNTANT')
                    ->name('api.v1.company.see-visibility.update');
                Route::get('/settings', GetCompanySettingsController::class)
                    ->name('api.v1.company.settings.show');

                Route::put('/settings', UpdateCompanySettingsController::class)
                    ->middleware(RequireCompanyRole::class.':ADMIN,CHIEF_ACCOUNTANT')
                    ->name('api.v1.company.settings.update');

                Route::get('/exchange-rates', GetCompanyExchangeRatesController::class)
                    ->name('api.v1.company.exchange-rates.show');

                Route::post('/exchange-rates', SaveCompanyExchangeRatesController::class)
                    ->middleware(RequireCompanyRole::class.':ADMIN,CHIEF_ACCOUNTANT,ACCOUNTANT')
                    ->name('api.v1.company.exchange-rates.store');

                Route::get('/areas', ListCompanyAreasController::class)
                    ->name('api.v1.company.areas.index');

                Route::get('/cost-centers', ListCostCentersController::class)
                    ->name('api.v1.company.cost-centers.index');

                Route::get('/cost-centers/manage', [CostCenterMasterController::class, 'index'])
                    ->name('api.v1.company.cost-centers.manage');
                Route::get('/cost-centers/{costCenterId}', [CostCenterMasterController::class, 'show'])
                    ->whereNumber('costCenterId')
                    ->name('api.v1.company.cost-centers.show');
                Route::post('/cost-centers', [CostCenterMasterController::class, 'store'])
                    ->middleware(RequireCompanyRole::class.':ADMIN,CHIEF_ACCOUNTANT')
                    ->name('api.v1.company.cost-centers.store');
                Route::put('/cost-centers/{costCenterId}', [CostCenterMasterController::class, 'update'])
                    ->middleware(RequireCompanyRole::class.':ADMIN,CHIEF_ACCOUNTANT')
                    ->whereNumber('costCenterId')
                    ->name('api.v1.company.cost-centers.update');
                Route::patch('/cost-centers/{costCenterId}/deactivate', [CostCenterMasterController::class, 'deactivate'])
                    ->middleware(RequireCompanyRole::class.':ADMIN,CHIEF_ACCOUNTANT')
                    ->whereNumber('costCenterId')
                    ->name('api.v1.company.cost-centers.deactivate');

                Route::get('/cash-flow-areas', ListCashFlowAreasController::class)
                    ->name('api.v1.company.cash-flow-areas.index');

                // Historial tributario por ejercicio: sólo revisiones inmutables.
                Route::get('/fiscal-profiles', [CompanyFiscalProfileController::class, 'index'])
                    ->name('api.v1.company.fiscal-profiles.index');

                Route::post('/fiscal-profiles', [CompanyFiscalProfileController::class, 'store'])
                    ->middleware(RequireCompanyRole::class.':ADMIN,CHIEF_ACCOUNTANT')
                    ->name('api.v1.company.fiscal-profiles.store');

                Route::get('/entities', ListEntitiesController::class)
                    ->name('api.v1.company.entities.index');
                Route::get('/entities/{entityId}', [EntityMasterController::class, 'show'])
                    ->whereNumber('entityId')
                    ->name('api.v1.company.entities.show');

                Route::post('/entities', [EntityMasterController::class, 'store'])
                    ->middleware(RequireCompanyRole::class.':ADMIN,CHIEF_ACCOUNTANT,ACCOUNTANT')
                    ->name('api.v1.company.entities.store');

                Route::put('/entities/{entityId}', [EntityMasterController::class, 'update'])
                    ->middleware(RequireCompanyRole::class.':ADMIN,CHIEF_ACCOUNTANT,ACCOUNTANT')
                    ->whereNumber('entityId')
                    ->name('api.v1.company.entities.update');

                Route::patch('/entities/{entityId}/deactivate', [EntityMasterController::class, 'deactivate'])
                    ->middleware(RequireCompanyRole::class.':ADMIN,CHIEF_ACCOUNTANT')
                    ->whereNumber('entityId')
                    ->name('api.v1.company.entities.deactivate');

                Route::post('/chart-of-accounts/initialize', InitializeCompanyChartOfAccountsController::class)
                    ->middleware(RequireCompanyRole::class.':ADMIN,CHIEF_ACCOUNTANT')
                    ->name('api.v1.company.chart-of-accounts.initialize');
            });
        });
    });
});


