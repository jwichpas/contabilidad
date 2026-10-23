<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Entity;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * F3.3: PostgreSQL Feature tests run under DatabaseTransactions (tests/Pest.php).
 * No committed fixture rows and no new Neon branches.
 */
beforeEach(function (): void {
    $nonce = bin2hex(random_bytes(4));
    $this->companyA = Company::query()->create([
        'tax_id' => '20'.(string) random_int(100000000, 999999999),
        'business_name' => 'Maestros Alfa '.$nonce,
        'legal_address' => 'Local de pruebas A',
        'is_active' => DB::raw('TRUE'),
    ]);
    $this->companyB = Company::query()->create([
        'tax_id' => '20'.(string) random_int(100000000, 999999999),
        'business_name' => 'Maestros Beta '.$nonce,
        'legal_address' => 'Local de pruebas B',
        'is_active' => DB::raw('TRUE'),
    ]);
    $this->admin = User::query()->create([
        'name' => 'Maestros Admin',
        'email' => 'f33admin_'.$nonce.'@example.test',
        'password' => 'No-Real-User-123!',
    ]);
    $this->auditor = User::query()->create([
        'name' => 'Maestros Auditor',
        'email' => 'f33auditor_'.$nonce.'@example.test',
        'password' => 'No-Real-User-123!',
    ]);
    $tenant = app(TenantContext::class);
    $tenant->run($this->companyA->id, function (): void {
        DB::table('company_user')->insert([
            ['company_id'=>$this->companyA->id,'user_id'=>$this->admin->id,
             'role'=>'ADMIN','is_active'=>DB::raw('TRUE'),'created_at'=>now(),'updated_at'=>now()],
            ['company_id'=>$this->companyA->id,'user_id'=>$this->auditor->id,
             'role'=>'AUDITOR','is_active'=>DB::raw('TRUE'),'created_at'=>now(),'updated_at'=>now()],
        ]);
    });
    $tenant->run($this->companyB->id, function (): void {
        DB::table('company_user')->insert([
            'company_id'=>$this->companyB->id,'user_id'=>$this->admin->id,
            'role'=>'AUDITOR','is_active'=>DB::raw('TRUE'),
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    });
    $this->admin->update(['current_company_id'=>$this->companyA->id]);
    $this->auditor->update(['current_company_id'=>$this->companyA->id]);
    $this->payload = [
        'identity_document_type_code' => '6',
        'tax_id' => '20601234567',
        'legal_name' => 'Empresa Maestra Ficticia SAC',
        'country_code' => 'PER',
        'is_client' => true,
        'is_supplier' => true,
    ];
});

it('creates one shared client-supplier entity with an obligatory audit and no banking disclosure', function (): void {
    $this->actingAs($this->admin)->withHeader('X-Company-Id', (string)$this->companyA->id);
    $created = $this->postJson('/api/v1/company/entities', $this->payload);
    $created->assertCreated()->assertJsonPath('data.is_client',true)
        ->assertJsonPath('data.is_supplier',true);
    $id = (int)$created->json('data.id');
    expect($id)->toBeGreaterThan(0);
    $created->assertJsonMissingPath('data.bank_account_number');
    $created->assertJsonMissingPath('data.bank_cci_number');

    $this->getJson('/api/v1/company/entities?is_client=1&is_supplier=1')
        ->assertOk()->assertJsonCount(1,'data')
        ->assertJsonPath('data.0.id',$id)
        ->assertJsonMissingPath('data.0.bank_account_number');

    $this->getJson('/api/v1/company/entities/'.$id)
        ->assertOk()->assertJsonPath('data.id',$id)
        ->assertJsonMissingPath('data.bank_cci_number');

    app(TenantContext::class)->run($this->companyA->id, function () use ($id): void {
        $row = DB::table('audit_logs')
            ->where('company_id',$this->companyA->id)
            ->where('auditable_id',(string)$id)
            ->where('event_type','ENTITY_CREATED')->first();
        expect($row)->not->toBeNull();
        $snapshot = json_decode((string)$row->new_values,true);
        expect($snapshot['tax_id'])->not->toBe($this->payload['tax_id']);
    });
});

it('prevents duplicate identities and rejects client-controlled company or bank fields', function (): void {
    $this->actingAs($this->admin)->withHeader('X-Company-Id',(string)$this->companyA->id);
    $this->postJson('/api/v1/company/entities',$this->payload)->assertCreated();
    $this->postJson('/api/v1/company/entities',$this->payload)
        ->assertUnprocessable()->assertJsonValidationErrors('tax_id');
    $this->postJson('/api/v1/company/entities',array_merge($this->payload,[
        'company_id'=>$this->companyB->id,
    ]))->assertUnprocessable()->assertJsonValidationErrors('company_id');
    $this->postJson('/api/v1/company/entities',array_merge($this->payload,[
        'bank_cci_number'=>'00112233445566778899',
    ]))->assertUnprocessable()->assertJsonValidationErrors('bank_cci_number');
    $this->postJson('/api/v1/company/entities',array_merge($this->payload,[
        'tax_id'=>'12345',
    ]))->assertUnprocessable()->assertJsonValidationErrors('tax_id');
});

it('edits, audits and deactivates a tenant entity without physical deletion', function (): void {
    $this->actingAs($this->admin)->withHeader('X-Company-Id',(string)$this->companyA->id);
    $response = $this->postJson('/api/v1/company/entities',$this->payload)->assertCreated();
    $id=(int)$response->json('data.id');
    $this->putJson('/api/v1/company/entities/'.$id,[
        'legal_name'=>'Empresa Actualizada Ficticia SAC',
        'is_supplier'=>false,
    ])->assertOk()->assertJsonPath('data.is_supplier',false);
    $this->patchJson('/api/v1/company/entities/'.$id.'/deactivate')
        ->assertOk()->assertJsonPath('data.is_active',false);
    $this->patchJson('/api/v1/company/entities/'.$id.'/deactivate')->assertOk();
    $this->getJson('/api/v1/company/entities')->assertOk()->assertJsonCount(0,'data');
    $this->putJson('/api/v1/company/entities/'.$id,['legal_name'=>'No procede'])
        ->assertUnprocessable();
    app(TenantContext::class)->run($this->companyA->id, function () use ($id): void {
        expect(Entity::query()->find($id))->not->toBeNull();
        $events=DB::table('audit_logs')->where('company_id',$this->companyA->id)
            ->where('auditable_id',(string)$id)->pluck('event_type')->all();
        expect($events)->toContain('ENTITY_CREATED','ENTITY_UPDATED','ENTITY_DEACTIVATED');
        expect(count(array_filter($events, fn($event)=>$event==='ENTITY_DEACTIVATED')))->toBe(1);
    });
});

it('protects writes by roles and prevents reading entities across two valid memberships', function (): void {
    $this->actingAs($this->admin)->withHeader('X-Company-Id',(string)$this->companyA->id);
    $created = $this->postJson('/api/v1/company/entities',$this->payload)->assertCreated();
    $id = (int)$created->json('data.id');

    // ADMIN in A is only AUDITOR in B: can read B, cannot create/update there.
    $this->withHeader('X-Company-Id',(string)$this->companyB->id)
        ->getJson('/api/v1/company/entities')->assertOk()->assertJsonCount(0,'data');
    $this->getJson('/api/v1/company/entities/'.$id)->assertNotFound();
    $this->putJson('/api/v1/company/entities/'.$id,['legal_name'=>'Cross tenant'])
        ->assertForbidden();
    $this->postJson('/api/v1/company/entities',$this->payload)->assertForbidden();

    $this->actingAs($this->auditor)->withHeader('X-Company-Id',(string)$this->companyA->id);
    $this->getJson('/api/v1/company/entities/'.$id)->assertOk();
    $this->patchJson('/api/v1/company/entities/'.$id.'/deactivate')->assertForbidden();

    $this->withHeader('X-Company-Id',(string)$this->companyB->id)
        ->getJson('/api/v1/company/entities')->assertForbidden();
});
