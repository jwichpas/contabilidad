<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CompanySubdiary;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

// tests/Pest.php wraps these fictitious fixtures in DatabaseTransactions.
beforeEach(function (): void {
    $this->tenant = app(TenantContext::class);
    $this->user = User::query()->create([
        'name' => 'Subdiary fixture', 'email' => 'sub_'.bin2hex(random_bytes(6)).'@example.test',
        'password' => 'Fixture-Only-Not-Real!']);
    foreach (['A', 'B'] as $suffix) {
        $company = Company::query()->create([
            'tax_id' => '20'.random_int(100000000, 999999999),
            'business_name' => 'Ficticia '.$suffix, 'legal_address' => 'Ficticia', 'is_active' => true,
        ]);
        $this->{'company'.$suffix} = $company;
        $this->tenant->run($company->id, function () use ($company): void {
            DB::table('company_user')->insert([
                'company_id' => $company->id, 'user_id' => $this->user->id, 'role' => 'ADMIN',
                'is_active' => DB::raw('TRUE'), 'created_at' => now(), 'updated_at' => now(),
            ]);
            CompanySubdiary::query()->create(['code' => 'GENERAL', 'name' => 'Diario general']);
        });
    }
    $this->user->update(['current_company_id' => $this->companyA->id]);
    $this->actingAs($this->user)->withHeader('X-Company-Id', (string)$this->companyA->id);
});

it('can create, rename and deactivate a company subdiary while retaining its identity', function (): void {
    $record = $this->postJson('/api/v1/company/subdiaries', [
        'code' => 'HONORARIOS', 'name' => 'Honorarios profesionales',
    ])->assertCreated()->assertJsonPath('data.code', 'HONORARIOS');
    $id = (int)$record->json('data.id');
    $this->getJson('/api/v1/company/subdiaries')->assertOk()->assertJsonCount(2, 'data');
    $this->putJson('/api/v1/company/subdiaries/'.$id, ['name' => 'Honorarios y asesorías'])
        ->assertOk()->assertJsonPath('data.name', 'Honorarios y asesorías');
    $this->patchJson('/api/v1/company/subdiaries/'.$id.'/status', ['is_active' => false])
        ->assertOk()->assertJsonPath('data.is_active', false);
    $this->getJson('/api/v1/company/subdiaries?active_only=1')->assertOk()
        ->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/company/subdiaries')->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.code', 'HONORARIOS');
    $this->patchJson('/api/v1/company/subdiaries/'.$id.'/status', ['is_active' => true])
        ->assertOk()->assertJsonPath('data.is_active', true);
});

it('rejects duplicate or injected tenant codes without touching other companies', function (): void {
    $this->postJson('/api/v1/company/subdiaries', ['code' => 'GENERAL', 'name' => 'Duplicado'])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->postJson('/api/v1/company/subdiaries', [
        'code' => 'PERSONAL', 'name' => 'Personal', 'company_id' => $this->companyB->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('company_id');
    $this->postJson('/api/v1/company/subdiaries', ['code' => 'bad-code', 'name' => 'Invalido'])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->getJson('/api/v1/company/subdiaries')->assertOk()->assertJsonCount(1, 'data');

    $this->withHeader('X-Company-Id', (string)$this->companyB->id);
    $this->postJson('/api/v1/company/subdiaries', ['code' => 'PERSONAL', 'name' => 'Personal'])
        ->assertCreated();
    $this->withHeader('X-Company-Id', (string)$this->companyA->id);
    $this->getJson('/api/v1/company/subdiaries')->assertOk()->assertJsonCount(1, 'data');
});

it('prevents changing subdiary codes or deleting another company record', function (): void {
    $id = $this->tenant->run($this->companyB->id, function (): int {
        return CompanySubdiary::query()->where('code', 'GENERAL')->firstOrFail()->id;
    });
    $this->putJson('/api/v1/company/subdiaries/'.$id, ['name' => 'No permitido'])
        ->assertNotFound();
    $this->patchJson('/api/v1/company/subdiaries/'.$id.'/status', ['is_active' => false])
        ->assertNotFound();
    $this->putJson('/api/v1/company/subdiaries/'.$id, [
        'code' => 'NUEVO', 'name' => 'Nombre',
    ])->assertUnprocessable()->assertJsonValidationErrors('code');
    $this->deleteJson('/api/v1/company/subdiaries/'.$id)->assertNotFound();
});
