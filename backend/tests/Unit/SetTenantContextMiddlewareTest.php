<?php

declare(strict_types=1);

use App\Http\Middleware\SetTenantContext;
use App\Models\User;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

// No DatabaseTransactions: these are unit-level middleware tests and do not
// connect to PostgreSQL. DB::transaction/select/selectOne are mocked.
uses(TestCase::class);

function userRequestForTenant(int $userId, int $companyId): Request
{
    $user = new User;
    $user->id = $userId;
    $user->current_company_id = $companyId;

    $request = Request::create('/api/v1/company/settings', 'GET');
    $request->headers->set('X-Company-Id', (string) $companyId);
    $request->setUserResolver(static fn (): User => $user);

    return $request;
}

it('maintains the company only while processing the request and uses transaction-local SQL context', function (): void {
    $context = app(TenantContext::class);
    $request = userRequestForTenant(101, 41);
    $controllerCalled = false;

    DB::shouldReceive('transaction')->once()
        ->andReturnUsing(static fn (callable $callback): mixed => $callback());

    DB::shouldReceive('select')->once()
        ->withArgs(static fn (string $sql, array $bindings): bool =>
            str_contains($sql, "set_config('app.current_company_id', ?, true)")
            && $bindings === ['41'])
        ->andReturn([(object) ['set_config' => '41']]);

    DB::shouldReceive('selectOne')->once()
        ->withArgs(static fn (string $sql, array $bindings): bool =>
            str_contains($sql, 'FROM public.company_user')
            && $bindings === [41, 101])
        ->andReturn((object) ['?column?' => 1]);

    $response = app(SetTenantContext::class)->handle(
        $request,
        static function (Request $_) use ($context, &$controllerCalled): Response {
            expect($context->getCompanyId())->toBe(41);
            $controllerCalled = true;
            return new Response('OK');
        }
    );

    expect($response->getContent())->toBe('OK')
        ->and($controllerCalled)->toBeTrue()
        ->and($context->getCompanyId())->toBeNull();
});

it('blocks a revoked company membership without invoking the controller or retaining tenant context', function (): void {
    $context = app(TenantContext::class);
    $request = userRequestForTenant(101, 42);
    $controllerCalled = false;

    DB::shouldReceive('transaction')->once()
        ->andReturnUsing(static fn (callable $callback): mixed => $callback());

    DB::shouldReceive('select')->once()
        ->withArgs(static fn (string $sql, array $bindings): bool =>
            str_contains($sql, "set_config('app.current_company_id', ?, true)")
            && $bindings === ['42'])
        ->andReturn([(object) ['set_config' => '42']]);

    DB::shouldReceive('selectOne')->once()
        ->withArgs(static fn (string $sql, array $bindings): bool =>
            str_contains($sql, 'FROM public.company_user')
            && $bindings === [42, 101])
        ->andReturn(null);

    expect(fn (): Response => app(SetTenantContext::class)->handle(
        $request,
        static function (Request $_) use (&$controllerCalled): Response {
            $controllerCalled = true;
            return new Response('MUST_NOT_RUN');
        }
    ))->toThrow(AccessDeniedHttpException::class);

    expect($controllerCalled)->toBeFalse()
        ->and($context->getCompanyId())->toBeNull();
});
