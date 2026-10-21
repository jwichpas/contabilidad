<?php

declare(strict_types=1);

use App\Http\Middleware\RequireCompanyRole;
use App\Models\User;
use App\Services\Tenant\ListUserCompaniesService;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * F1.3 - Pruebas de autorización en empresas YA EXISTENTES, solo SELECT.
 *
 * Se invoca el middleware con un controlador simulado (closure que devuelve
 * Response); no se realizan solicitudes POST/PUT/PATCH ni se modifican datos.
 * Los tests Feature están protegidos por DatabaseTransactions en tests/Pest.php.
 */
it('enforces company-specific ADMIN and CHIEF_ACCOUNTANT roles without executing writes', function (): void {
    expect(DB::selectOne('SELECT current_user AS role_name')->role_name)->toBe('app_backend');

    $user = null;
    $adminCompany = null;
    $chiefCompany = null;
    foreach (User::query()->whereNotNull('current_company_id')->orderBy('id')->get() as $candidate) {
        $memberships = app(ListUserCompaniesService::class)->forUser($candidate);
        $byRole = collect($memberships)->keyBy('role');
        if ($byRole->has('ADMIN') && $byRole->has('CHIEF_ACCOUNTANT')) {
            $user = $candidate;
            $adminCompany = $byRole->get('ADMIN');
            $chiefCompany = $byRole->get('CHIEF_ACCOUNTANT');
            break;
        }
    }

    expect($user)->not->toBeNull();
    expect((int) $adminCompany['id'])->not->toBe((int) $chiefCompany['id']);

    $request = Request::create('/__f1_readonly_role_probe', 'GET');
    $request->setUserResolver(static fn (): User => $user);
    $guard = app(RequireCompanyRole::class);
    $tenant = app(TenantContext::class);

    $nextCount = 0;
    $next = static function (Request $unused) use (&$nextCount): Response {
        $nextCount++;
        return new Response('', Response::HTTP_NO_CONTENT);
    };

    foreach ([
        [$adminCompany, 'ADMIN'],
        [$chiefCompany, 'CHIEF_ACCOUNTANT'],
    ] as [$company, $allowedRole]) {
        $response = $tenant->run((int) $company['id'], static fn (): Response =>
            $guard->handle($request, $next, $allowedRole)
        );
        expect($response->getStatusCode())->toBe(204);
    }
    expect($nextCount)->toBe(2);

    // Ser ADMIN en B no concede ADMIN en A ni CHIEF_ACCOUNTANT en B.
    foreach ([
        [$adminCompany, 'CHIEF_ACCOUNTANT'],
        [$chiefCompany, 'ADMIN'],
    ] as [$company, $wrongRole]) {
        expect(static fn () => $tenant->run((int) $company['id'], static fn (): Response =>
            $guard->handle($request, $next, $wrongRole)
        ))->toThrow(AccessDeniedHttpException::class);
    }

    // Ninguna operación protegida alcanzó el controlador simulado en los rechazos.
    expect($nextCount)->toBe(2);

    // Membresía inexistente, pero el identificador suministrado es sintácticamente válido.
    expect(static fn () => $tenant->run(999999999, static fn (): Response =>
        $guard->handle($request, $next, 'ADMIN')
    ))->toThrow(AccessDeniedHttpException::class);
    expect($nextCount)->toBe(2);
});

it('fails closed for missing or invalid route roles and unauthenticated users', function (): void {
    expect(DB::selectOne('SELECT current_user AS role_name')->role_name)->toBe('app_backend');

    $user = User::query()->whereNotNull('current_company_id')->orderBy('id')->firstOrFail();
    $companyId = (int) $user->current_company_id;
    $request = Request::create('/__f1_readonly_role_probe', 'GET');
    $request->setUserResolver(static fn (): User => $user);

    $guard = app(RequireCompanyRole::class);
    $tenant = app(TenantContext::class);
    $next = static fn (Request $unused): Response => new Response('', 204);

    expect(static fn () => $tenant->run($companyId, static fn (): Response =>
        $guard->handle($request, $next)
    ))->toThrow(AccessDeniedHttpException::class);
    expect(static fn () => $tenant->run($companyId, static fn (): Response =>
        $guard->handle($request, $next, 'SUPER_ADMIN')
    ))->toThrow(AccessDeniedHttpException::class);

    $anonymous = Request::create('/__f1_readonly_role_probe', 'GET');
    $anonymous->setUserResolver(static fn () => null);
    expect(static fn () => $tenant->run($companyId, static fn (): Response =>
        $guard->handle($anonymous, $next, 'ADMIN')
    ))->toThrow(AccessDeniedHttpException::class);

    // Ningún contexto empresarial persiste al acabar los intentos.
    expect($tenant->getCompanyId())->toBeNull();
    $state = DB::selectOne(<<<'SQL'
SELECT nullif(current_setting('app.current_company_id', true), '') AS tenant_id,
       nullif(current_setting('app.current_user_id', true), '') AS user_id
SQL);
    expect($state->tenant_id)->toBeNull();
    expect($state->user_id)->toBeNull();
});
