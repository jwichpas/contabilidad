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
            // A request-scoped query listener aggregates timings only for this
            // endpoint. Never store SQL text, bindings, tokens or user details.
            $request->attributes->set('journal_auth_sql_active', true);
            $request->attributes->set('journal_auth_sql_count', 0);
            $request->attributes->set('journal_auth_sql_total_ms', 0.0);
            $request->attributes->set('journal_auth_sql_first_ms', null);
            $request->attributes->set('journal_auth_sql_max_ms', 0.0);
            $request->attributes->set('journal_auth_sql_token_ms', 0.0);
            $request->attributes->set('journal_auth_sql_user_ms', 0.0);
            $request->attributes->set('journal_route_auth_start_ns', hrtime(true));
        }

        return $next($request);
    }
}
