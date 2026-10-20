<?php

declare(strict_types=1);

use App\Support\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * F1.2: these tests run only on disposable CI PostgreSQL.
 * Pest's DatabaseTransactions opens an enclosing transaction.
 */
it('restores SQL and PHP tenant contexts after nested successful transactions', function (): void {
    $context = app(TenantContext::class);
    $before = (string) (DB::selectOne("SELECT current_setting('app.current_company_id', true) AS tenant")?->tenant ?? '');

    $context->run(101, function () use ($context): void {
        expect($context->getCompanyId())->toBe(101);
        expect((string) DB::selectOne('SELECT public.app_current_company_id() AS id')->id)->toBe('101');

        $context->run(202, function () use ($context): void {
            expect($context->getCompanyId())->toBe(202);
            expect((string) DB::selectOne('SELECT public.app_current_company_id() AS id')->id)->toBe('202');
        });

        expect($context->getCompanyId())->toBe(101);
        expect((string) DB::selectOne('SELECT public.app_current_company_id() AS id')->id)->toBe('101');
    });

    expect($context->getCompanyId())->toBeNull();
    expect((string) (DB::selectOne("SELECT current_setting('app.current_company_id', true) AS tenant")?->tenant ?? ''))->toBe($before);
});

it('restores outer tenant on failure inside nested savepoint', function (): void {
    $context = app(TenantContext::class);

    $context->run(301, function () use ($context): void {
        try {
            $context->run(302, function (): void {
                throw new RuntimeException('Intentional nested rollback');
            });
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('Intentional nested rollback');
        }

        expect($context->getCompanyId())->toBe(301);
        expect((string) DB::selectOne('SELECT public.app_current_company_id() AS id')->id)->toBe('301');
    });

    expect($context->getCompanyId())->toBeNull();
    expect(DB::selectOne('SELECT public.app_current_company_id() AS id')->id)->toBeNull();
});
