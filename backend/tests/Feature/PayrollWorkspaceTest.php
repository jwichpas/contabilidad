<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->company = Company::query()->create(['tax_id' => '20'.random_int(100000000, 999999999),
        'business_name' => 'Planillas de prueba', 'legal_address' => 'Prueba', 'is_active' => true]);
    $this->user = User::query()->create(['name' => 'Contador', 'email' => 'payroll_'.bin2hex(random_bytes(6)).'@example.test',
        'password' => 'testing-only']);
    app(TenantContext::class)->run($this->company->id, fn () => DB::table('company_user')->insert([
        'company_id' => $this->company->id, 'user_id' => $this->user->id, 'role' => 'ADMIN',
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]));
    Sanctum::actingAs($this->user);
    $this->withHeader('X-Company-Id', (string) $this->company->id);
});

it('freezes monthly payroll, balances its draft and exports PLAME and AFPnet', function (): void {
    $path = '/api/v1/payroll';
    $period = $path.'/periods/2026-07';
    $worker = [
        'expected_revision' => 0, 'dni' => '01234567', 'surname' => 'Ruiz', 'given_names' => 'Elena',
        'position' => 'Control', 'effective_month' => '2026-07', 'hired_on' => '2024-01-01',
        'salary' => '3000', 'daily_hours' => 8, 'pension' => 'ONP', 'vacation_granted' => 30,
        'reference' => 'Contrato de prueba',
    ];
    $this->putJson($path.'/workers/TR-001', $worker)->assertOk()->assertJsonPath('data.revision', 1);
    $this->putJson($path.'/workers/TR-002', array_replace($worker, ['dni' => '01234568', 'salary' => '2000',
        'given_names' => 'Pedro', 'pension' => 'AFP', 'afp' => 'IN', 'cuspp' => '123456ABCD12']))->assertOk();
    $this->postJson($path.'/workers/TR-001/vacations', ['from' => '2026-07-06', 'to' => '2026-07-07',
        'reference' => 'Acuerdo de vacaciones'])->assertOk()->assertJsonPath('data.data.days', 2);
    $this->postJson($path.'/workers/TR-001/vacations', ['from' => '2026-07-07', 'to' => '2026-07-09',
        'reference' => 'Traslape'])->assertUnprocessable();

    $calculation = ['expected_revision' => 0, 'health_minimum' => '1130', 'source' => 'Cierre de prueba'];
    $this->putJson($period.'/calculation', $calculation)->assertUnprocessable()->assertJsonValidationErrors('attendance');
    $attendance = ['expected_revision' => 0, 'paid_days' => 30, 'vacation_paid_days' => 2, 'ordinary_minutes' => 9600,
        'overtime_25' => 0, 'overtime_35' => 0, 'taxable_extra' => '0', 'nontaxable_extra' => '0',
        'income_tax' => '0', 'other_deductions' => '0', 'reference' => 'Asistencia de julio'];
    $dayPath = $period.'/attendance/TR-001/days/2026-07-01';
    $day = ['expected_revision' => 0, 'date' => '2026-07-01', 'status' => 'WORKED', 'ordinary' => 480, 'extra' => 30,
        'reference' => 'Parte diario'];
    $this->putJson($dayPath, $day)->assertOk();
    $this->putJson($period.'/attendance/TR-001', $attendance)->assertOk();
    $this->putJson($dayPath, array_replace($day, ['expected_revision' => 1, 'extra' => 0]))->assertOk();
    $this->putJson($period.'/calculation', $calculation)->assertUnprocessable()->assertJsonValidationErrors('attendance');
    $this->putJson($period.'/attendance/TR-001', array_replace($attendance, ['expected_revision' => 1]))->assertOk();
    $this->putJson($period.'/attendance/TR-002', array_replace($attendance, ['vacation_paid_days' => 0,
        'afp_rates' => ['insurance' => '1.37', 'commission' => '1.50', 'insurance_ceiling' => '12000', 'source' => 'SBS de prueba']]))->assertOk();

    $payroll = $this->putJson($period.'/calculation', $calculation)->assertOk()
        ->assertJsonPath('data.data.totals.gross', '5000.00')->assertJsonPath('data.data.totals.net', '4352.60')
        ->assertJsonPath('data.data.totals.vacation_pay', '200.00')->json('data.data');
    $lines = collect($payroll['journal']['lines']);
    expect($lines->sum('debit'))->toBe($lines->sum('credit'));
    $this->get($period.'/exports/jor')->assertStatus(409);
    $this->postJson($period.'/approval', ['expected_revision' => 1])->assertOk()->assertJsonPath('data.data.approved', true);
    $this->putJson($period.'/attendance/TR-001', $attendance)->assertStatus(409);

    $plame = $this->get($period.'/exports/jor')->assertOk()->getContent();
    expect($plame)->toContain("01|01234567|160|0|0|0|\r\n");
    $afp = rtrim($this->get($period.'/exports/afp')->assertOk()->getContent(), "\r\n");
    expect(strlen($afp))->toBe(141)->and(substr($afp, 102, 9))->toBe('000200000')->and(substr($afp, 139))->toBe('IN');
    $benefit = ['expected_revision' => 0, 'base' => '3000', 'months' => 6, 'days' => 0, 'health' => 'ESSALUD',
        'posting_date' => '2026-07-15', 'withholding' => '0', 'reference' => 'Semestre validado'];
    $this->putJson($period.'/benefits/TR-001/GRATIFICATION', $benefit)->assertOk()
        ->assertJsonPath('data.data.result.amount', '3000.00')->assertJsonPath('data.data.result.bonus', '270.00');

    $this->putJson($path.'/periods/2026-11/benefits/TR-001/CTS', array_replace($benefit, [
        'months' => 3, 'days' => 15, 'previous_gratification' => '3000', 'posting_date' => '2026-11-15',
    ]))->assertOk()->assertJsonPath('data.data.result.basis', '3500.00')
        ->assertJsonPath('data.data.result.amount', '1020.83');

    app(TenantContext::class)->run($this->company->id, fn () => DB::table('company_user')
        ->where('user_id', $this->user->id)->update(['role' => 'AUDITOR']));
    $this->getJson($period)->assertForbidden();
    $this->get($period.'/exports/afp')->assertForbidden();
});
