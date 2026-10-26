<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sits AFTER Sanctum's auth middleware. It isolates post-authentication time
 * for journal bootstrap, posting and company switching.
 * No credentials, query strings, token values or user identities are logged.
 */
final class JournalAuthenticatedTiming
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! (($request->is('api/v1/accounting/journal-entries/editor-bootstrap') && $request->isMethod('GET'))
            || ($request->is('api/v1/accounting/journal-entries') && $request->isMethod('POST'))
            || ($request->is('api/v1/tenant/switch-company') && $request->isMethod('POST')))) {
            return $next($request);
        }

        $started = hrtime(true);
        $response = $next($request);
        $duration = number_format((hrtime(true) - $started) / 1000000, 1, '.', '');
        $earlier = (string) $response->headers->get('Server-Timing', '');
        $response->headers->set(
            'Server-Timing',
            ($earlier !== '' ? $earlier.', ' : '').'post_auth_total;dur='.$duration
        );

        return $response;
    }
}
