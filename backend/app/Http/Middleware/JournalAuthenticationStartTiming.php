<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks the beginning of Laravel's authenticated route segment.
 *
 * This middleware MUST precede auth:sanctum in the same route group.
 * It only records a monotonic timestamp; it never changes the guard,
 * reads bearer tokens, or alters authentication.
 */
final class JournalAuthenticationStartTiming
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/v1/accounting/journal-entries/editor-bootstrap')
            && $request->isMethod('GET')) {
            $request->attributes->set('journal_route_auth_start_ns', hrtime(true));
        }

        return $next($request);
    }
}
