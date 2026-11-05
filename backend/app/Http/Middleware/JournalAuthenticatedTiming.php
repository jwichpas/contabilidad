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
        $origin = $request->attributes->get('journal_request_timing_start_ns');
        $response = $next($request);
        $duration = number_format((hrtime(true) - $started) / 1000000, 1, '.', '');
        $earlier = (string) $response->headers->get('Server-Timing', '');
        // This spans route setup and authentication, not only Sanctum SQL.
        // Do not expose token contents, IDs or connection details.
        $preAuth = is_int($origin) && $origin <= $started
            ? 'pre_auth_pipeline;dur='.number_format(($started - $origin) / 1000000, 1, '.', '').', '
            : '';
        $response->headers->set(
            'Server-Timing',
            ($earlier !== '' ? $earlier.', ' : '').$preAuth.'post_auth_total;dur='.$duration
        );

        return $response;
    }
}
