<?php

declare(strict_types=1);

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Currency;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->tenant = app(TenantContext::class);
    $this->company = Company::query()->create([
        'tax_id' => '20'.random_int(100000000,999999999),
        'business_name' => 'Prueba EEFF NIIF segregada',
        'legal_address' => 'DEV', 'is_active' => true,
    ]);
    $this->user = User::query()->create([
        'name' => 'Probador EEFF', 'email' => 'continuation_'.bin2hex(random_bytes(7)).'@example.test',
        'password' => 'Testing-only-2026!',
    ]);
    $this->tenant->run((int) $this->company->id, function (): void {
        CompanyUser::query()->create([
            'company_id' => $this->company->id, 'user_id' => $this->user->id,
            'role' => CompanyRole::ChiefAccountant, 'is_active' => true,
        ]);
        $pen = Currency::query()->where('code','PEN')->firstOrFail();
        DB::table('company_settings')->insert([
            'company_id'=>$this->company->id,'base_currency_id'=>$pen->id,
            'tax_regime'=>'GENERAL','default_vat_rate'=>'18.00',
        ]);
    });
    $this->user->update(['current_company_id'=>$this->company->id]);
    Sanctum::actingAs($this->user,['*']);
    $this->withHeader('X-Company-Id',(string)$this->company->id);
});

it('reports empty tenant safely without inventing equity or indirect cash movements', function (): void {
    $this->getJson('/api/v1/accounting/reports/trial-balance?from=2026-01-01&to=2026-01-31')
        ->assertOk()->assertJsonPath('data.balanced',true)
        ->assertJsonPath('data.differences.closing','0.0000')->assertJsonCount(0,'data.rows');
    $this->getJson('/api/v1/accounting/reports/equity-changes?as_of=2026-01-31')
        ->assertOk()->assertJsonPath('data.closing_equity_including_result','0.0000')
        ->assertJsonPath('data.oci_assessed',false);
    $this->getJson('/api/v1/accounting/reports/cash-flow/indirect?from=2026-01-01&to=2026-01-31')
        ->assertOk()->assertJsonPath('data.operating_indirect','0.0000')
        ->assertJsonPath('data.closing_book_local','0.0000');
});

it('stores auditable note drafts with optimistic revisions and denies other tenants', function (): void {
    $route = '/api/v1/company/niif-notes';
    $this->getJson($route.'?year=2026')->assertOk()
        ->assertJsonPath('data.notes.0.revision',0)->assertJsonPath('data.is_approved',false)
        ->assertJsonCount(16,'data.notes');
    $payload = ['year'=>2026,'expected_revision'=>0,'narrative'=>'Base del borrador: pendiente de respaldo.'];
    $this->putJson($route.'/N04',$payload)->assertOk()
        ->assertJsonPath('data.revision',1)->assertJsonPath('data.status','DRAFT');
    $this->putJson($route.'/N04',$payload)->assertStatus(409);
    $this->getJson($route.'?year=2026')->assertOk()
        ->assertJsonPath('data.notes.3.revision',1);
    $other = Company::query()->create([
        'tax_id'=>'20'.random_int(100000000,999999999),
        'business_name'=>'Tenant ajeno', 'legal_address'=>'DEV', 'is_active'=>true,
    ]);
    $this->getJson($route.'?year=2026',['X-Company-Id'=>(string)$other->id])
        ->assertForbidden();
    $this->putJson($route.'/N04',$payload,['X-Company-Id'=>(string)$other->id])
        ->assertForbidden();
});

it('rejects accountant writes and unsupported note approval fields', function (): void {
    $route='/api/v1/company/niif-notes/N01';
    $payload=['year'=>2026,'expected_revision'=>0,'narrative'=>'Texto para revisar.'];
    $this->putJson($route,$payload+['status'=>'APPROVED'])->assertUnprocessable();
    $this->tenant->run((int)$this->company->id,function (): void {
        CompanyUser::query()->where('company_id',$this->company->id)
            ->where('user_id',$this->user->id)
            ->update(['role'=>CompanyRole::Accountant->value]);
    });
    $this->putJson($route,$payload)->assertForbidden();
    $this->getJson('/api/v1/company/niif-notes?year=2026')->assertOk();
});
