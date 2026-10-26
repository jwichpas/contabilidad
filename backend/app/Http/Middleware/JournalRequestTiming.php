<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lightweight request-level timing for journal bootstrap, posting and company switching.
 * This starts before route authentication and finishes after the controller.
 * It does not expose database queries, credentials or tenant identifiers.
 */
final class JournalRequestTiming
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
        $durationMs = number_format((hrtime(true) - $started) / 1000000, 1, '.', '');
        $previous = (string) $response->headers->get('Server-Timing', '');
        $response->headers->set('Server-Timing',
            ($previous !== '' ? $previous.', ' : '').'request_total;dur='.$durationMs);

        return $response;
    }
}
