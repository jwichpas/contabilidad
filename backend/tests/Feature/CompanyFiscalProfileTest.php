<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CompanyFiscalProfile;
use App\Models\Currency;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $nonce = bin2hex(random_bytes(5));
    $this->companyA = Company::query()->create([
        'tax_id'=>'20'.(string) random_int(100000000,999999999),
        'business_name'=>'Historial fiscal A '.$nonce,
        'legal_address'=>'Ficticia 100',
        'is_active'=>DB::raw('TRUE'),
    ]);
    $this->companyB = Company::query()->create([
        'tax_id'=>'20'.(string) random_int(100000000,999999999),
        'business_name'=>'Historial fiscal B '.$nonce,
        'legal_address'=>'Ficticia 200',
        'is_active'=>DB::raw('TRUE'),
    ]);
    $this->admin = User::query()->create([
        'name'=>'Fiscal Admin', 'email'=>'fiscal_admin_'.$nonce.'@example.test',
        'password'=>'Ficticio-solo-pruebas-123!',
    ]);
    $this->auditor = User::query()->create([
        'name'=>'Fiscal Auditor','email'=>'fiscal_auditor_'.$nonce.'@example.test',
        'password'=>'Ficticio-solo-pruebas-123!',
    ]);
    $tenant=app(TenantContext::class);
    $penId=(int) Currency::query()->where('code','PEN')->value('id');
    $tenant->run($this->companyA->id, function () use ($penId): void {
        DB::table('company_user')->insert([
            ['company_id'=>$this->companyA->id, 'user_id'=>$this->admin->id,
                'role'=>'ADMIN','is_active'=>DB::raw('TRUE'),'created_at'=>now(),'updated_at'=>now()],
            ['company_id'=>$this->companyA->id, 'user_id'=>$this->auditor->id,
                'role'=>'AUDITOR','is_active'=>DB::raw('TRUE'),'created_at'=>now(),'updated_at'=>now()],
        ]);
        DB::table('company_settings')->insert([
            'company_id'=>$this->companyA->id, 'base_currency_id'=>$penId,
            'tax_regime'=>'GENERAL', 'default_vat_rate'=>'18.00',
        ]);
    });
    $tenant->run($this->companyB->id, function () use ($penId): void {
        DB::table('company_user')->insert([
            'company_id'=>$this->companyB->id, 'user_id'=>$this->admin->id,
            'role'=>'AUDITOR','is_active'=>DB::raw('TRUE'),'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('company_settings')->insert([
            'company_id'=>$this->companyB->id, 'base_currency_id'=>$penId,
            'tax_regime'=>'GENERAL', 'default_vat_rate'=>'18.00',
        ]);
    });
    $this->admin->update(['current_company_id'=>$this->companyA->id]);
    $this->auditor->update(['current_company_id'=>$this->companyA->id]);
    $this->payload = [
        'fiscal_year'=>2026,
        'effective_date'=>'2026-01-01',
        'tax_regime'=>'GENERAL',
        'legal_form'=>'SAC',
        'income_reference_year'=>2025,
        'annual_income'=>'120000.00',
        'default_vat_rate'=>'18.00',
        'is_retention_agent'=>false,
        'is_perception_agent'=>false,
        'is_good_taxpayer'=>false,
        'is_sire_obliged'=>true,
        'is_ple_obliged'=>false,
        'change_reason'=>'Alta fiscal inicial validada para empresa ficticia.',
    ];
});

it('adds an audited version without changing company settings and reads the fiscal timeline', function (): void {
    $this->actingAs($this->admin)->withHeader('X-Company-Id',(string)$this->companyA->id);
    $first=$this->postJson('/api/v1/company/fiscal-profiles',$this->payload);
    $first->assertCreated()
        ->assertJsonPath('data.fiscal_year',2026)
        ->assertJsonPath('data.revision',1)
        ->assertJsonPath('data.tax_regime','GENERAL');
    $id=(int)$first->json('data.id');

    $next=$this->payload;
    $next['effective_date']='2026-07-01';
    $next['tax_regime']='MYPE_TRIBUTARIO';
    $next['change_reason']='Nueva revisión fiscal autorizada en julio de 2026.';
    $this->postJson('/api/v1/company/fiscal-profiles',$next)
        ->assertCreated()
        ->assertJsonPath('data.revision',2);
    $this->getJson('/api/v1/company/fiscal-profiles?year=2026')
        ->assertOk()->assertJsonCount(2,'data')
        ->assertJsonPath('data.0.revision',2)
        ->assertJsonPath('data.1.revision',1);
    app(TenantContext::class)->run($this->companyA->id, function () use ($id): void {
        expect(CompanyFiscalProfile::query()->find($id))->not->toBeNull();
        $setting=DB::table('company_settings')->where('company_id',$this->companyA->id)->first();
        expect($setting->tax_regime)->toBe('GENERAL');
        expect(DB::table('audit_logs')->where('company_id',$this->companyA->id)
            ->where('event_type','COMPANY_FISCAL_PROFILE_REVISED')->count())->toBe(2);
    });
});

it('rejects invalid dates, revisions out of order and tenant injection', function (): void {
    $this->actingAs($this->admin)->withHeader('X-Company-Id',(string)$this->companyA->id);
    $this->postJson('/api/v1/company/fiscal-profiles',array_merge($this->payload,[
        'company_id'=>$this->companyB->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('company_id');
    $this->postJson('/api/v1/company/fiscal-profiles',array_merge($this->payload,[
        'effective_date'=>'2025-12-31',
    ]))->assertUnprocessable()->assertJsonValidationErrors('effective_date');
    $this->postJson('/api/v1/company/fiscal-profiles',$this->payload)->assertCreated();
    $this->postJson('/api/v1/company/fiscal-profiles',$this->payload)
        ->assertUnprocessable()->assertJsonValidationErrors('effective_date');
    $this->postJson('/api/v1/company/fiscal-profiles',array_merge($this->payload,[
        'tax_regime'=>'NRUS',
    ]))->assertUnprocessable();
});

it('enforces read isolation and write roles between two valid memberships', function (): void {
    $this->actingAs($this->admin)->withHeader('X-Company-Id',(string)$this->companyA->id);
    $this->postJson('/api/v1/company/fiscal-profiles',$this->payload)->assertCreated();
    // ADMIN in A is only AUDITOR in B, and must not see A's history while in B.
    $this->withHeader('X-Company-Id',(string)$this->companyB->id)
        ->getJson('/api/v1/company/fiscal-profiles?year=2026')
        ->assertOk()->assertJsonCount(0,'data');
    $this->postJson('/api/v1/company/fiscal-profiles',$this->payload)->assertForbidden();

    $this->actingAs($this->auditor)->withHeader('X-Company-Id',(string)$this->companyA->id);
    $this->getJson('/api/v1/company/fiscal-profiles?year=2026')
        ->assertOk()->assertJsonCount(1,'data');
    $this->postJson('/api/v1/company/fiscal-profiles',$this->payload)->assertForbidden();
});

it('refuses retroactive revision over a closed period and prohibits updates', function (): void {
    $this->actingAs($this->admin)->withHeader('X-Company-Id',(string)$this->companyA->id);
    $created=$this->postJson('/api/v1/company/fiscal-profiles',$this->payload)->assertCreated();
    $id=(int)$created->json('data.id');
    app(TenantContext::class)->run($this->companyA->id, function (): void {
        DB::table('fiscal_periods')->insert([
            'company_id'=>$this->companyA->id,
            'year'=>2026, 'month'=>6, 'start_date'=>'2026-06-01',
            'end_date'=>'2026-06-30', 'status'=>'CLOSED',
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    });
    $this->postJson('/api/v1/company/fiscal-profiles',array_merge($this->payload,[
        'effective_date'=>'2026-06-01',
        'change_reason'=>'Revisión ficticia retroactiva prohibida.',
    ]))->assertUnprocessable()->assertJsonValidationErrors('effective_date');

    app(TenantContext::class)->run($this->companyA->id, function () use ($id): void {
        // No UPDATE privilege for app_backend and database trigger also enforces immutability.
        expect(fn () => DB::transaction(fn () =>
            DB::table('company_fiscal_profiles')->where('id',$id)
                ->update(['tax_regime'=>'RER'])
        ))->toThrow(QueryException::class);
    });
});
