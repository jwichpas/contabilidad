<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

const MONTHLY_WORK = '/api/v1/accounting/monthly-work/2026-10';
const MONTHLY_CALENDAR = '/api/v1/company/sunat-calendar/2026';

beforeEach(function (): void {
    $this->company = Company::query()->create([
        'tax_id' => '20'.random_int(10000000, 99999999).'4',
        'business_name' => 'Agenda contable', 'legal_address' => 'DEV', 'is_active' => true,
    ]);
    $this->user = User::query()->create([
        'name' => 'Contador', 'email' => 'agenda_'.bin2hex(random_bytes(7)).'@example.test',
        'password' => 'testing-only-2026!',
    ]);
    app(TenantContext::class)->run((int) $this->company->id, function (): void {
        DB::table('company_user')->insert([
            'company_id' => $this->company->id, 'user_id' => $this->user->id,
            'role' => 'ADMIN', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('company_settings')->insert([
            'company_id' => $this->company->id,
            'base_currency_id' => DB::table('currencies')->where('code', 'PEN')->value('id'),
            'tax_regime' => 'GENERAL', 'default_vat_rate' => '18.00',
        ]);
    });
    Sanctum::actingAs($this->user, ['*']);
    $this->withHeader('X-Company-Id', (string) $this->company->id);
    $this->confirmation = [
        'expected_revision' => 0, 'prico' => true, 'retention' => true, 'perception' => true,
        'assets' => true, 'workers' => true, 'afp' => true, 'cts' => true, 'gratification' => true, 'internal_due' => '2026-11-09',
        'evidence' => 'Verificación de RUC y padrones de prueba',
    ];
});

function configureMonthlyCalendar($test): void
{
    $profile = $test->getJson(MONTHLY_CALENDAR)->assertOk()->json('data.profile');
    $profile['income_band'] = 'UP_TO_300';
    $profile['overrides'] = [
        ['code' => 'MONTHLY', 'label' => 'FV 621', 'type' => 'tax', 'period' => '2026-10',
            'date' => '2026-11-30', 'reference' => 'Prórroga particular de prueba'],
    ];
    $test->putJson(MONTHLY_CALENDAR, ['profile' => $profile, 'expected_revision' => 0])->assertOk();
}

it('blocks unconfirmed periods and keeps independent obligations visible', function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-15 10:00:00', 'America/Lima'));
    $entry = '/api/v1/accounting/journal-entries';
    $purchase = '/api/v1/accounting/document-journals/purchases/documents';
    $this->postJson($entry, ['entry_date' => '2026-10-20'])->assertStatus(428);
    $this->postJson($purchase, ['entry_date' => '2026-10-20'])->assertStatus(428);
    $this->putJson(MONTHLY_WORK.'/confirmation', $this->confirmation)->assertUnprocessable()->assertJsonValidationErrors('calendar');

    configureMonthlyCalendar($this);
    $board = $this->putJson(MONTHLY_WORK.'/confirmation', $this->confirmation)->assertOk()
        ->assertJsonPath('data.revision', 1)->assertJsonPath('data.profile.prico', true)
        ->assertJsonPath('data.kpi.overdue', 7)->json('data');
    $this->getJson('/api/v1/accounting/monthly-work/alerts')->assertOk()
        ->assertJsonPath('data.needsConfirmation', true)->assertJsonPath('data.target', '2026-10')
        ->assertJsonPath('data.count', 12);
    $tasks = collect($board['tasks'])->keyBy('code');
    expect($tasks['sunat-afp']['due'])->toBe('2026-11-06');
    expect($tasks['sunat-8']['due'])->toBe('2026-11-18');
    expect($tasks['sunat-monthly']['due'])->toBe('2026-11-30');
    foreach (['sunat-626', 'sunat-633', 'sunat-plame'] as $form) {
        expect($tasks[$form]['due'])->toBe('2026-11-19');
    }
    $this->postJson($entry, ['entry_date' => '2026-10-20'])->assertUnprocessable();
    $this->postJson($purchase, ['entry_date' => '2026-11-01'])->assertStatus(428);
    $this->putJson(MONTHLY_WORK.'/confirmation', $this->confirmation)->assertConflict();
    $this->putJson(MONTHLY_WORK.'/tasks/bank', ['done' => true, 'evidence' => 'Conciliación', 'expected_revision' => 0])
        ->assertOk()->assertJsonPath('data.kpi.done', 1);
    $this->putJson(MONTHLY_WORK.'/tasks/bank', ['done' => false, 'evidence' => 'Corregir mayor', 'expected_revision' => 1])
        ->assertOk()->assertJsonPath('data.kpi.done', 0);
    $this->travelBack();
});

it('generates depreciation and restricts payroll detail', function (): void {
    configureMonthlyCalendar($this);
    $this->putJson(MONTHLY_WORK.'/confirmation', $this->confirmation)->assertOk();
    $close = ['done' => true, 'evidence' => 'Revisado', 'expected_revision' => 0];
    $this->putJson(MONTHLY_WORK.'/tasks/depreciation', $close)->assertConflict();

    $this->postJson('/api/v1/accounting/financial-auxiliaries/asset/records', [
        'reference' => 'AF-001', 'label' => 'Equipo', 'source_reference' => 'Factura 001', 'expected_revision' => 0,
        'tax_year' => 2026, 'book_cost' => '12000', 'residual' => '0', 'useful_months' => 24,
        'available_from' => '2026-01-01', 'tax_cost' => '12000', 'tax_category' => 'DATA_EQUIPMENT',
        'tax_use_from' => '2026-01-01', 'last_eligible_month' => 12, 'prior_tax_depreciation' => '0', 'recorded_depreciation' => '3000',
    ])->assertCreated();
    $this->postJson(MONTHLY_WORK.'/depreciation', ['expected_revision' => 0])->assertOk()
        ->assertJsonPath('revision', 1)->assertJsonPath('data.rows.0.tax', '250.00');
    $this->getJson(MONTHLY_WORK.'/depreciation')->assertOk()->assertJsonPath('data.rows.0.source_revision', 1);
    $this->putJson(MONTHLY_WORK.'/tasks/depreciation', $close)->assertOk();
    $this->postJson(MONTHLY_WORK.'/depreciation', ['expected_revision' => 1])->assertConflict();

    $this->putJson(MONTHLY_WORK.'/payroll', [
        'expected_revision' => 0, 'source' => 'Asistencia y aportes de octubre',
        'rows' => [['worker' => 'TR-01', 'regular' => '1500.10', 'other' => '150.02',
            'pension' => '214.51', 'income_tax' => '0', 'other_deductions' => '5', 'employer_contributions' => '148.51']],
    ])->assertOk()->assertJsonPath('data.totals.net', '1430.61')
        ->assertJsonPath('data.totals.employer_cost', '1798.63');
    $this->getJson(MONTHLY_WORK)->assertOk()->assertJsonMissing(['worker' => 'TR-01'])
        ->assertJsonPath('data.workpapers.payroll.workers', 1);
    app(TenantContext::class)->run((int) $this->company->id, function (): void {
        DB::table('company_user')->where('company_id', $this->company->id)->where('user_id', $this->user->id)
            ->update(['role' => 'AUDITOR']);
    });
    $this->getJson(MONTHLY_WORK.'/payroll')->assertForbidden();
    $this->putJson(MONTHLY_WORK.'/confirmation', $this->confirmation)->assertForbidden();
});
