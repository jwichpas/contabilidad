<?php

declare(strict_types=1);

use App\Http\Middleware\JournalRequestTiming;
use App\Scheduling\AccountingTaskSchedule;
use Illuminate\Console\Scheduling\Schedule;
use App\Http\Middleware\JournalAuthenticationStartTiming;
use App\Http\Middleware\SecurityHeadersMiddleware;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        health: '/up',
    )
    ->withSchedule(static function (Schedule $schedule): void {
        app(AccountingTaskSchedule::class)->register($schedule);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(JournalRequestTiming::class);
        // Laravel sorts route middleware by priority, not always declaration order.
        // Keep this timing-only marker before Sanctum so auth_route_segment
        // measures authentication instead of accidentally reporting 0.0 ms.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, JournalAuthenticationStartTiming::class);
        $middleware->append(SecurityHeadersMiddleware::class);
        $middleware->alias([
            'tenant' => SetTenantContext::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(static fn (): bool => true);
    })
    ->create();
