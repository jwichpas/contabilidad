<?php

declare(strict_types=1);

use App\Enums\AccountType;
use App\Enums\BalanceType;
use App\Enums\CompanyRole;
use App\Exceptions\MissingTenantContextException;
use App\Exceptions\TenantIntegrityException;
use App\Models\AccountingAccount;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->tenantContext = app(TenantContext::class);
    $randA = (string) mt_rand(10000000, 99999999);
    $randB = (string) mt_rand(10000000, 99999999);

    $this->companyA = Company::query()->create([
        'tax_id' => '20' . substr($randA, 0, 9),
        'business_name' => 'Corporación Industrial Alfa S.A.C.',
        'legal_address' => 'Av. Javier Prado Este 1001, Lima',
        'is_active' => true,
    ]);

    $this->companyB = Company::query()->create([
        'tax_id' => '20' . substr($randB, 0, 9),
        'business_name' => 'Inversiones Comerciales Beta S.A.C.',
        'legal_address' => 'Av. República de Panamá 3050, San Isidro',
        'is_active' => true,
    ]);

    $this->userA = User::query()->create([
        'name' => 'Contador Alfa',
        'email' => "contador_alfa_{$randA}@alfa.pe",
        'password' => 'Secret123!',
    ]);

    $this->userB = User::query()->create([
        'name' => 'Contador Beta',
        'email' => "contador_beta_{$randB}@beta.pe",
        'password' => 'Secret123!',
    ]);

    $this->tenantContext->run($this->companyA->id, function (): void {
        CompanyUser::query()->create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->userA->id,
            'role' => CompanyRole::ChiefAccountant,
            'is_active' => true,
        ]);
    });

    $this->tenantContext->run($this->companyB->id, function (): void {
        CompanyUser::query()->create([
            'company_id' => $this->companyB->id,
            'user_id' => $this->userB->id,
            'role' => CompanyRole::ChiefAccountant,
            'is_active' => true,
        ]);
    });
});

it('falla cerrado (Fail-Closed) cuando se consulta o crea sin TenantContext en Eloquent y en SQL crudo por RLS', function (): void {
    $this->tenantContext->clear();

    // 1. Eloquent debe lanzar MissingTenantContextException
    expect(fn () => AccountingAccount::query()->get())
        ->toThrow(MissingTenantContextException::class);

    // 2. SQL crudo bajo rol app_backend sin `app.current_company_id` fijado debe devolver 0 filas por la política RLS
    DB::statement('SET ROLE app_backend');
    try {
        $rawRows = DB::select('SELECT * FROM public.accounting_accounts');
        expect($rawRows)->toBeEmpty();
    } finally {
        DB::statement('RESET ROLE');
    }
});

it('inyecta automáticamente company_id al crear e impide sobrescribirlo o mutarlo', function (): void {
    $account = $this->tenantContext->run($this->companyA->id, function (): AccountingAccount {
        return AccountingAccount::query()->create([
            'code' => '10',
            'name' => 'Efectivo y Equivalentes de Efectivo',
            'level' => 1,
            'path' => '10',
            'account_type' => AccountType::Asset,
            'balance_type' => BalanceType::Debit,
            'requires_currency' => false,
            'requires_auxiliary' => false,
            'is_operational' => false,
            'is_active' => true,
        ]);
    });

    expect($account->company_id)->toBe($this->companyA->id);

    // Intentar forzar el company_id de la Empresa B estando en el contexto de la Empresa A debe fallar
    expect(fn () => $this->tenantContext->run($this->companyA->id, function (): void {
        $forged = new AccountingAccount([
            'code' => '20',
            'name' => 'Mercaderías',
            'level' => 1,
            'path' => '20',
            'account_type' => AccountType::Asset,
            'balance_type' => BalanceType::Debit,
        ]);
        $forged->setAttribute('company_id', $this->companyB->id);
        $forged->save();
    }))->toThrow(TenantIntegrityException::class);

    // Intentar mutar el company_id en updating debe fallar
    expect(fn () => $this->tenantContext->run($this->companyA->id, function () use ($account): void {
        $account->setAttribute('company_id', $this->companyB->id);
        $account->save();
    }))->toThrow(TenantIntegrityException::class);
});

it('impide a la Empresa A leer, modificar o eliminar cuentas de la Empresa B por Eloquent, Query Builder y SQL crudo (RLS)', function (): void {
    $accountB = $this->tenantContext->run($this->companyB->id, function (): AccountingAccount {
        return AccountingAccount::query()->create([
            'code' => '10',
            'name' => 'Efectivo Empresa B',
            'level' => 1,
            'path' => '10',
            'account_type' => AccountType::Asset,
            'balance_type' => BalanceType::Debit,
            'is_operational' => false,
            'is_active' => true,
        ]);
    });

    $this->tenantContext->run($this->companyA->id, function () use ($accountB): void {
        // 1. Por Eloquent (filtrado por TenantScope)
        expect(AccountingAccount::query()->find($accountB->id))->toBeNull();

        // 2. Por Query Builder y SQL Crudo bajo el rol app_backend con RLS activo en PostgreSQL
        DB::statement('SET ROLE app_backend');
        try {
            $qbRow = DB::table('accounting_accounts')->where('id', $accountB->id)->first();
            expect($qbRow)->toBeNull();

            // 3. Por SQL Crudo (bloqueado directamente por PostgreSQL RLS bajo el rol `app_backend`)
            $rawSelect = DB::select('SELECT id FROM public.accounting_accounts WHERE id = ?::bigint', [$accountB->id]);
            expect($rawSelect)->toBeEmpty();

            // 4. Intento de UPDATE por SQL Crudo sobre la fila de Empresa B (RLS filtra y afecta 0 filas)
            $affected = DB::update(
                "UPDATE public.accounting_accounts SET name = 'Hackeado desde A' WHERE id = ?::bigint",
                [$accountB->id]
            );
            expect($affected)->toBe(0);

            // 5. Intento de INSERT por SQL Crudo con el company_id de Empresa B (falla por RLS WITH CHECK)
            expect(function (): void {
                DB::transaction(function (): void {
                    DB::insert(
                        <<<'SQL'
                        INSERT INTO public.accounting_accounts
                            (company_id, code, name, level, path, account_type, balance_type, created_at, updated_at)
                        VALUES
                            (?::bigint, '50', 'Capital Inyectado', 1, '50', 'EQUITY', 'CREDIT', now(), now())
                        SQL,
                        [$this->companyB->id]
                    );
                });
            })->toThrow(QueryException::class);
        } finally {
            DB::statement('RESET ROLE');
        }
    });
});

it('hace cumplir UNIQUE (company_id, code) dentro de la misma empresa y permite el mismo código en empresas distintas', function (): void {
    $this->tenantContext->run($this->companyA->id, function (): void {
        AccountingAccount::query()->create([
            'code' => '10',
            'name' => 'Efectivo Alfa',
            'level' => 1,
            'path' => '10',
            'account_type' => AccountType::Asset,
            'balance_type' => BalanceType::Debit,
        ]);

        // Duplicar código en la misma Empresa A debe lanzar QueryException (UNIQUE violation 23505)
        expect(function (): void {
            DB::transaction(function (): void {
                AccountingAccount::query()->create([
                    'code' => '10',
                    'name' => 'Efectivo Alfa Duplicado',
                    'level' => 1,
                    'path' => '10',
                    'account_type' => AccountType::Asset,
                    'balance_type' => BalanceType::Debit,
                ]);
            });
        })->toThrow(QueryException::class);
    });

    // En la Empresa B el mismo código '10' es perfectamente válido
    $accountInB = $this->tenantContext->run($this->companyB->id, function (): AccountingAccount {
        return AccountingAccount::query()->create([
            'code' => '10',
            'name' => 'Efectivo Beta',
            'level' => 1,
            'path' => '10',
            'account_type' => AccountType::Asset,
            'balance_type' => BalanceType::Debit,
        ]);
    });

    expect($accountInB->code)->toBe('10')
        ->and($accountInB->company_id)->toBe($this->companyB->id);
});

it('bloquea a nivel de motor de BD referenciar un parent_id de otra empresa (FK compuesta) y crear hijas bajo cuentas operativas', function (): void {
    $parentInB = $this->tenantContext->run($this->companyB->id, function (): AccountingAccount {
        return AccountingAccount::query()->create([
            'code' => '10',
            'name' => 'Efectivo Beta',
            'level' => 1,
            'path' => '10',
            'account_type' => AccountType::Asset,
            'balance_type' => BalanceType::Debit,
        ]);
    });

    // 1. Intentar en Empresa A crear una subcuenta apuntando al parent_id de Empresa B falla en PostgreSQL
    expect(function () use ($parentInB): void {
        DB::transaction(function () use ($parentInB): void {
            $this->tenantContext->run($this->companyA->id, function () use ($parentInB): void {
                AccountingAccount::query()->create([
                    'parent_id' => $parentInB->id,
                    'code' => '104',
                    'name' => 'Cuentas Corrientes',
                    'level' => 2,
                    'path' => '10.104',
                    'account_type' => AccountType::Asset,
                    'balance_type' => BalanceType::Debit,
                ]);
            });
        });
    })->toThrow(QueryException::class);

    // 2. Una cuenta con `is_operational = true` no puede tener hijas ni una cuenta con hijas puede volverse operativa
    $this->tenantContext->run($this->companyA->id, function (): void {
        $lvl1 = AccountingAccount::query()->create([
            'code' => '10',
            'name' => 'Efectivo',
            'level' => 1,
            'path' => '10',
            'account_type' => AccountType::Asset,
            'balance_type' => BalanceType::Debit,
            'is_operational' => false,
        ]);

        $lvl2 = AccountingAccount::query()->create([
            'parent_id' => $lvl1->id,
            'code' => '104',
            'name' => 'Cuentas Corrientes en Instituciones Financieras',
            'level' => 2,
            'path' => '10.104',
            'account_type' => AccountType::Asset,
            'balance_type' => BalanceType::Debit,
            'is_operational' => false,
        ]);

        // Intentar marcar como operativa una cuenta de nivel < 4 o con hijas dispara el CHECK / Trigger en PostgreSQL
        // PostgreSQL aborts a transaction after a constraint error. Isolate
        // this expected failure in a SAVEPOINT so TenantContext can restore
        // its SET LOCAL state without querying an aborted transaction.
        expect(fn () => DB::transaction(
            fn () => $lvl1->update(['is_operational' => true])
        ))->toThrow(QueryException::class);
    });
});
