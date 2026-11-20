<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

final class RequirePayrollSchema
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            Schema::hasTable('company_payroll_revisions'),
            Response::HTTP_SERVICE_UNAVAILABLE,
            'Planillas no está disponible. Aplique la migración de planillas y vuelva a intentar.',
        );

        return $next($request);
    }
}
