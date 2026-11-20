<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
    $this->putJson($path.'/workers/70000001', $worker)->assertOk()->assertJsonPath('data.revision', 1)
        ->assertJsonPath('data.data.code', '70000001');
    $this->putJson($path.'/workers/-70000001', $worker)->assertNotFound();
    $this->putJson($path.'/workers/00000002', array_replace($worker, [
        'dni' => '01234569', 'pension' => 'AFP', 'afp' => 'IN', 'cuspp' => '123456',
    ]))->assertUnprocessable()->assertJsonValidationErrors('cuspp');
    $this->putJson($path.'/workers/TR-002', array_replace($worker, ['dni' => '01234568', 'salary' => '2000',
        'given_names' => 'Pedro', 'pension' => 'AFP', 'afp' => 'IN', 'cuspp' => '123456ABCD12']))->assertOk();
    $this->postJson($path.'/workers/70000001/vacations', ['from' => '2026-07-06', 'to' => '2026-07-07',
        'reference' => 'Acuerdo de vacaciones'])->assertOk()->assertJsonPath('data.data.days', 2);
    $this->postJson($path.'/workers/70000001/vacations', ['from' => '2026-07-07', 'to' => '2026-07-09',
        'reference' => 'Traslape'])->assertUnprocessable();

    $this->getJson($period)->assertOk()->assertJsonCount(2, 'data.attendance_pending')
        ->assertJsonPath('data.attendance_pending.0.worker', '70000001')
        ->assertJsonPath('data.attendance_pending.0.message', 'Falta confirmar la asistencia.');

    $calculation = ['expected_revision' => 0, 'health_minimum' => '1130', 'source' => 'Cierre de prueba'];
    $this->putJson($period.'/calculation', $calculation)->assertUnprocessable()->assertJsonValidationErrors('attendance');
    $attendance = ['expected_revision' => 0, 'paid_days' => 30, 'vacation_paid_days' => 2, 'ordinary_minutes' => 9600,
        'overtime_25' => 0, 'overtime_35' => 0, 'taxable_extra' => '0', 'nontaxable_extra' => '0',
        'income_tax' => '0', 'other_deductions' => '0', 'reference' => 'Asistencia de julio'];
    $dayPath = $period.'/attendance/70000001/days/2026-07-01';
    $day = ['expected_revision' => 0, 'date' => '2026-07-01', 'status' => 'WORKED', 'ordinary' => 480, 'extra' => 30,
        'reference' => 'Parte diario'];
    $this->putJson($dayPath, $day)->assertOk();
    $this->putJson($period.'/attendance/70000001', $attendance)->assertOk();
    $this->putJson($dayPath, array_replace($day, ['expected_revision' => 1, 'extra' => 0]))->assertOk();
    $this->getJson($period)->assertJsonPath('data.attendance_pending.0.message', 'Cambió la asistencia diaria. Actualice el cierre de asistencia.');
    $this->putJson($period.'/calculation', $calculation)->assertUnprocessable()->assertJsonValidationErrors('attendance');
    $this->putJson($period.'/attendance/70000001', array_replace($attendance, ['expected_revision' => 1]))->assertOk();
    $this->putJson($period.'/attendance/TR-002', array_replace($attendance, ['vacation_paid_days' => 0,
        'afp_rates' => ['insurance' => '1.37', 'commission' => '1.50', 'insurance_ceiling' => '12000', 'source' => 'SBS de prueba']]))->assertOk();

    $this->getJson($period)->assertJsonCount(0, 'data.attendance_pending');
    $payroll = $this->putJson($period.'/calculation', $calculation)->assertOk()
        ->assertJsonPath('data.data.totals.gross', '5000.00')->assertJsonPath('data.data.totals.net', '4352.60')
        ->assertJsonPath('data.data.totals.vacation_pay', '200.00')->json('data.data');
    $lines = collect($payroll['journal']['lines']);
    expect($lines->sum('debit'))->toBe($lines->sum('credit'));
    $this->get($period.'/exports/jor')->assertStatus(409);
    $this->postJson($period.'/approval', ['expected_revision' => 1])->assertOk()->assertJsonPath('data.data.approved', true);
    $this->putJson($period.'/attendance/70000001', $attendance)->assertStatus(409);

    $plame = $this->get($period.'/exports/jor')->assertOk()->getContent();
    expect($plame)->toContain("01|01234567|160|0|0|0|\r\n");
    $afp = rtrim($this->get($period.'/exports/afp')->assertOk()->getContent(), "\r\n");
    expect(strlen($afp))->toBe(141)->and(substr($afp, 102, 9))->toBe('000200000')->and(substr($afp, 139))->toBe('IN');
    $benefit = ['expected_revision' => 0, 'base' => '3000', 'months' => 6, 'days' => 0, 'health' => 'ESSALUD',
        'posting_date' => '2026-07-15', 'withholding' => '0', 'reference' => 'Semestre validado'];
    $this->putJson($period.'/benefits/70000001/GRATIFICATION', $benefit)->assertOk()
        ->assertJsonPath('data.data.result.amount', '3000.00')->assertJsonPath('data.data.result.bonus', '270.00');

    $this->putJson($path.'/periods/2026-11/benefits/70000001/CTS', array_replace($benefit, [
        'months' => 3, 'days' => 15, 'previous_gratification' => '3000', 'posting_date' => '2026-11-15',
    ]))->assertOk()->assertJsonPath('data.data.result.basis', '3500.00')
        ->assertJsonPath('data.data.result.amount', '1020.83');

    app(TenantContext::class)->run($this->company->id, fn () => DB::table('company_user')
        ->where('user_id', $this->user->id)->update(['role' => 'AUDITOR']));
    $this->getJson($period)->assertForbidden();
    $this->get($period.'/exports/afp')->assertForbidden();
});

it('returns a migration message when the payroll table is missing', function (): void {
    Schema::partialMock()->shouldReceive('hasTable')->with('company_payroll_revisions')->once()->andReturnFalse();

    $this->getJson('/api/v1/payroll/periods/2026-07')
        ->assertServiceUnavailable()
        ->assertJsonPath('message', 'Planillas no está disponible. Aplique la migración de planillas y vuelva a intentar.');
});

it('uses the October 2026 increase and itemizes the family allowance and AFP balance deductions', function (): void {
    $old = '/api/v1/payroll/periods/2026-09';
    $period = '/api/v1/payroll/periods/2026-11';
    $this->getJson($old)->assertOk()->assertJsonPath('data.parameters.minimum.amount', '1130.00')
        ->assertJsonPath('data.parameters.family_allowance', '113.00')->assertJsonPath('data.parameters.afp', null);
    $this->getJson($period)->assertOk()->assertJsonPath('data.parameters.minimum.amount', '1230.00')
        ->assertJsonPath('data.parameters.family_allowance', '123.00')->assertJsonPath('data.parameters.night_minimum', '1660.50')
        ->assertJsonPath('data.parameters.uit', '5500.00')->assertJsonPath('data.parameters.tax_deduction', '38500.00')
        ->assertJsonPath('data.parameters.afp.administrators.IN.flow', '1.55');
    $this->getJson('/api/v1/payroll/periods/2027-01')->assertOk()->assertJsonPath('data.parameters.minimum', null)
        ->assertJsonPath('data.parameters.uit', null);

    $worker = [
        'expected_revision' => 0, 'dni' => '01234560', 'surname' => 'Ruiz', 'given_names' => 'Elena',
        'position' => 'Control', 'effective_month' => '2026-11', 'hired_on' => '2026-11-01',
        'salary' => '1640', 'daily_hours' => 8, 'pension' => 'AFP', 'afp' => 'IN', 'cuspp' => '123456ABCD12',
        'family_allowance' => true, 'night_shift' => true, 'afp_commission' => 'BALANCE',
        'vacation_granted' => 0, 'reference' => 'Contrato de prueba',
    ];
    $attendance = [
        'expected_revision' => 0, 'paid_days' => 30, 'vacation_paid_days' => 0, 'ordinary_minutes' => 9600,
        'overtime_25' => 0, 'overtime_35' => 0, 'taxable_extra' => '0', 'nontaxable_extra' => '0',
        'income_tax' => '0', 'other_deductions' => '0', 'reference' => 'Cierre de asistencia',
        'afp_rates' => ['insurance' => '1.37', 'commission' => '1.55', 'insurance_ceiling' => '12732.70', 'source' => 'SBS 2026-11'],
        'provisions' => [
            'gratification_base' => '1700', 'gratification_month' => true, 'cts_base' => '1700', 'prior_gratification' => '1200',
            'cts_days' => 30, 'vacation_base' => '1700', 'vacation_days' => 30, 'coverage' => 'EPS', 'reference' => 'Bases de prueba',
        ],
    ];
    $workerPath = '/api/v1/payroll/workers/TR-NOCHE';
    $attendancePath = $period.'/attendance/TR-NOCHE';
    $calculation = ['expected_revision' => 0, 'health_minimum' => '1230', 'source' => 'Cierre de prueba'];
    $this->putJson($workerPath, $worker)->assertOk();
    $this->putJson($attendancePath, $attendance)->assertOk();
    $this->putJson($period.'/calculation', array_replace($calculation, ['health_minimum' => '1130']))
        ->assertUnprocessable()->assertJsonValidationErrors('health_minimum');
    $this->putJson($period.'/calculation', $calculation)->assertUnprocessable()->assertJsonValidationErrors('night_shift');

    $this->putJson($workerPath, array_replace($worker, ['expected_revision' => 1, 'salary' => '1700']))->assertOk();
    $this->putJson($attendancePath, array_replace($attendance, ['expected_revision' => 1]))->assertOk();
    $this->putJson($period.'/calculation', $calculation)->assertOk()
        ->assertJsonPath('data.data.parameters.minimum.amount', '1230.00')
        ->assertJsonPath('data.data.rows.0.amounts.family_allowance', '123.00')
        ->assertJsonPath('data.data.rows.0.amounts.gross', '1823.00')
        ->assertJsonPath('data.data.rows.0.amounts.afp_contribution', '182.30')
        ->assertJsonPath('data.data.rows.0.amounts.afp_insurance', '24.98')
        ->assertJsonPath('data.data.rows.0.amounts.afp_commission', '0.00')
        ->assertJsonPath('data.data.rows.0.amounts.provision_gratification', '283.33')
        ->assertJsonPath('data.data.rows.0.amounts.provision_gratification_bonus', '19.13')
        ->assertJsonPath('data.data.rows.0.amounts.provision_cts', '158.33')
        ->assertJsonPath('data.data.rows.0.amounts.provision_vacation', '141.67')
        ->assertJsonPath('data.data.totals.provision_total', '602.46')
        ->assertJsonPath('data.data.rows.0.amounts.employer_cost', '1987.07');
    $ratePath = '/api/v1/payroll/parameters/afp/2026-11';
    $decree = ['source' => 'Publicación SBS de prueba'];
    $rates = $decree + [
        'expected_revision' => 0, 'contribution' => '11.00', 'insurance' => '1.47', 'insurance_ceiling' => '12000.00',
        'administrators' => [
            'HA' => ['flow' => '1.47', 'balance' => '1.25'], 'IN' => ['flow' => '1.55', 'balance' => '0.78'],
            'PR' => ['flow' => '1.69', 'balance' => '0.68'], 'RI' => ['flow' => '1.60', 'balance' => '1.25'],
        ],
    ];
    $this->putJson($ratePath, $rates)->assertOk()->assertJsonPath('data.revision', 1);
    $this->putJson($ratePath, $rates)->assertConflict();
    $this->getJson($period)->assertJsonPath('data.parameters_changed', true)
        ->assertJsonPath('data.parameters.afp_contribution', '11.00');
    $this->postJson($period.'/approval', ['expected_revision' => 1])->assertConflict();
    $this->putJson($period.'/calculation', array_replace($calculation, ['expected_revision' => 1]))->assertOk()
        ->assertJsonPath('data.data.parameters.revisions.afp', 'afp:2026-11#1')
        ->assertJsonPath('data.data.rows.0.amounts.afp_contribution', '200.53')
        ->assertJsonPath('data.data.rows.0.attendance.afp_rates.insurance', '1.47')
        ->assertJsonPath('data.data.rows.0.amounts.afp_commission', '0.00');
    $this->postJson($period.'/approval', ['expected_revision' => 2])->assertOk()->assertJsonPath('data.data.approved', true);
    $this->putJson($ratePath, array_replace($rates, ['expected_revision' => 1, 'contribution' => '12.00']))
        ->assertOk()->assertJsonPath('data.revision', 2);
    $this->getJson($period)->assertJsonPath('data.parameters.afp_contribution', '12.00')
        ->assertJsonPath('data.parameters_changed', false)
        ->assertJsonPath('data.payroll.data.parameters.afp_contribution', '11.00');
    $this->getJson('/api/v1/payroll/parameters')->assertOk()
        ->assertJsonPath('data.0.revision', 2)
        ->assertJsonPath('data.0.created_by', $this->user->id)
        ->assertJsonStructure(['data' => [['created_at']]])
        ->assertJsonPath('data.1.revision', 1);
});



it('resolves minimum wages by effective month and tax units by year inside each company', function (): void {
    $path = '/api/v1/payroll';
    $data = ['expected_revision' => 0, 'source' => 'Norma de prueba', 'url' => 'https://www.gob.pe'];
    $this->putJson($path.'/parameters/rmv/2027', $data + ['amount' => '1300.00'])
        ->assertUnprocessable()->assertJsonValidationErrors('period');
    $this->putJson($path.'/parameters/uit/2027-01', $data + ['amount' => '5600.00'])
        ->assertUnprocessable()->assertJsonValidationErrors('period');
    $rmv = ['expected_revision' => 0, 'source' => 'Norma de prueba', 'amount' => '1300.00'];
    $this->putJson($path.'/parameters/rmv/2027-03', $rmv + ['url' => 'http://www.gob.pe'])
        ->assertUnprocessable()->assertJsonValidationErrors('url');
    $this->putJson($path.'/parameters/rmv/2027-03', $rmv + ['url' => ''])->assertOk()
        ->assertJsonPath('data.data.url', null);
    $this->putJson($path.'/parameters/uit/2027', $data + ['amount' => '5600.00'])->assertOk();
    $this->getJson($path.'/periods/2027-02')->assertJsonPath('data.parameters.minimum', null)
        ->assertJsonPath('data.parameters.uit', '5600.00');
    $this->getJson($path.'/periods/2027-12')->assertJsonPath('data.parameters.minimum.amount', '1300.00')
        ->assertJsonPath('data.parameters.minimum.url', null)
        ->assertJsonPath('data.parameters.family_allowance', '130.00')
        ->assertJsonPath('data.parameters.tax_deduction', '39200.00')
        ->assertJsonPath('data.parameters.afp', null);
    $this->getJson($path.'/periods/2028-01')->assertJsonPath('data.parameters.minimum.amount', '1300.00')
        ->assertJsonPath('data.parameters.uit', null);

    $other = Company::query()->create(['tax_id' => '20'.random_int(100000000, 999999999),
        'business_name' => 'Empresa de prueba sin parámetros', 'legal_address' => 'Prueba', 'is_active' => true]);
    app(TenantContext::class)->run($other->id, fn () => DB::table('company_user')->insert([
        'company_id' => $other->id, 'user_id' => $this->user->id, 'role' => 'AUDITOR',
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]));
    $this->withHeader('X-Company-Id', (string) $other->id);
    $this->getJson($path.'/parameters')->assertForbidden();
    $this->putJson($path.'/parameters/uit/2027', $data + ['amount' => '5800.00'])->assertForbidden();
    app(TenantContext::class)->run($other->id, fn () => DB::table('company_user')
        ->where('user_id', $this->user->id)->update(['role' => 'CHIEF_ACCOUNTANT']));
    $this->getJson($path.'/parameters')->assertJsonCount(5, 'data');
    $this->getJson($path.'/periods/2027-12')->assertJsonPath('data.parameters.minimum', null)
        ->assertJsonPath('data.parameters.uit', null);
});


it('calculates independent payroll without attendance and leaves the calendar available after approval', function (): void {
    $path = '/api/v1/payroll';
    $period = $path.'/periods/2026-11';
    $this->putJson($path.'/workers/TR-MANUAL', [
        'expected_revision' => 0, 'dni' => '01234560', 'surname' => 'Ruiz', 'given_names' => 'Elena',
        'position' => 'Control', 'effective_month' => '2026-11', 'hired_on' => '2026-11-01',
        'salary' => '3000', 'daily_hours' => 8, 'pension' => 'ONP', 'vacation_granted' => 0,
        'reference' => 'Contrato',
    ])->assertOk();
    $this->putJson($period.'/calculation', [
        'input_mode' => 'independent', 'expected_revision' => 0, 'health_minimum' => '1230', 'source' => 'Cierre',
    ])->assertOk()->assertJsonPath('data.data.rows.0.attendance.paid_days', 30)
        ->assertJsonPath('data.data.rows.0.amounts.gross', '3000.00');
    $this->postJson($period.'/approval', ['expected_revision' => 1])->assertOk();
    $this->get($period.'/exports/jor')->assertConflict();
    $this->putJson($period.'/attendance/TR-MANUAL/days/2026-11-03', [
        'expected_revision' => 0, 'date' => '2026-11-03', 'status' => 'ABSENT', 'ordinary' => 0, 'extra' => 0,
    ])->assertOk()->assertJsonPath('data.data.status', 'ABSENT');
    $this->getJson($period)->assertJsonPath('data.payroll.data.rows.0.attendance.paid_days', 30);
});

it('imports only the closed work inputs and keeps the copy independent of subsequent absence changes', function (): void {
    $path = '/api/v1/payroll';
    $period = $path.'/periods/2026-11';
    $worker = 'TR-COPIA';
    $this->putJson($path.'/workers/'.$worker, [
        'expected_revision' => 0, 'dni' => '01234560', 'surname' => 'Ruiz', 'given_names' => 'Elena',
        'position' => 'Control', 'effective_month' => '2026-11', 'hired_on' => '2026-11-01',
        'salary' => '3000', 'daily_hours' => 8, 'pension' => 'ONP', 'vacation_granted' => 0,
        'reference' => 'Contrato',
    ])->assertOk();
    $work = [
        'paid_days' => 29, 'vacation_paid_days' => 0, 'ordinary_minutes' => 480, 'overtime_25' => 0, 'overtime_35' => 0,
    ];
    $this->putJson($period.'/attendance/'.$worker, $work + ['expected_revision' => 0])->assertOk()
        ->assertJsonMissingPath('data.data.afp_rates');
    $input = $work + [
        'expected_revision' => 0, 'input_source' => 'attendance', 'jornada_confirmed' => false, 'import_attendance' => true,
        'taxable_extra' => '0', 'nontaxable_extra' => '0', 'income_tax' => '0', 'other_deductions' => '50',
    ];
    $this->putJson($period.'/inputs/'.$worker, array_replace($input, ['paid_days' => 1]))
        ->assertOk()->assertJsonPath('data.data.paid_days', 29)
        ->assertJsonPath('data.data.imported_attendance_revision', 1);
    $this->putJson($period.'/attendance/'.$worker.'/days/2026-11-03', [
        'expected_revision' => 0, 'date' => '2026-11-03', 'status' => 'ABSENT', 'ordinary' => 0, 'extra' => 0,
    ])->assertOk();
    $this->putJson($period.'/inputs/'.$worker, array_replace($input, ['expected_revision' => 1, 'import_attendance' => false, 'other_deductions' => '55']))
        ->assertOk()->assertJsonPath('data.data.paid_days', 29);
    $this->putJson($period.'/calculation', [
        'input_mode' => 'independent', 'expected_revision' => 0, 'health_minimum' => '1230', 'source' => 'Cierre',
    ])->assertOk()->assertJsonPath('data.data.rows.0.amounts.gross', '2900.00')
        ->assertJsonPath('data.data.rows.0.amounts.other_deductions', '55');
    $this->postJson($period.'/approval', ['expected_revision' => 1])->assertOk();
    $this->get($period.'/exports/jor')->assertOk();
});
