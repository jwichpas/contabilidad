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
        // Stop collecting before tenant membership and controller SQL begin.
        $request->attributes->set('journal_auth_sql_active', false);
        $origin = $request->attributes->get('journal_request_timing_start_ns');
        $routeAuthStarted = $request->attributes->get('journal_route_auth_start_ns');
        $response = $next($request);
        $duration = number_format((hrtime(true) - $started) / 1000000, 1, '.', '');
        $earlier = (string) $response->headers->get('Server-Timing', '');
        // This spans route setup and authentication, not only Sanctum SQL.
        // Do not expose token contents, IDs or connection details.
        $preAuth = is_int($origin) && $origin <= $started
            ? 'pre_auth_pipeline;dur='.number_format(($started - $origin) / 1000000, 1, '.', '').', '
            : '';

        // Break down the pre-auth duration without claiming that every
        // millisecond in the route segment is a Sanctum database query.
        // Invariant: pre_auth_route + auth_route_segment = pre_auth_pipeline.
        $authSegments = '';
        if (is_int($origin) && is_int($routeAuthStarted)
            && $origin <= $routeAuthStarted && $routeAuthStarted <= $started) {
            $authSegments = 'pre_auth_route;dur='
                .number_format(($routeAuthStarted - $origin) / 1000000, 1, '.', '')
                .', auth_route_segment;dur='
                .number_format(($started - $routeAuthStarted) / 1000000, 1, '.', '')
                .', ';
        }
        // QueryExecuted::time is milliseconds and does not disclose SQL.
        // Distinguish remote query time from the complete auth stage.
        $authQueryMetrics = '';
        $queryCount = $request->attributes->get('journal_auth_sql_count');
        if (is_int($queryCount)) {
            $formatMs = static fn (float $value): string => number_format($value, 1, '.', '');
            $authQueryMetrics = 'auth_sql_total;dur='
                .$formatMs((float) $request->attributes->get('journal_auth_sql_total_ms', 0.0))
                .', auth_sql_queries;desc="'.$queryCount.'"';

            if ($queryCount > 0) {
                $authQueryMetrics .= ', auth_sql_first;dur='
                    .$formatMs((float) $request->attributes->get('journal_auth_sql_first_ms', 0.0))
                    .', auth_sql_max;dur='
                    .$formatMs((float) $request->attributes->get('journal_auth_sql_max_ms', 0.0))
                    .', auth_sql_token;dur='
                    .$formatMs((float) $request->attributes->get('journal_auth_sql_token_ms', 0.0))
                    .', auth_sql_user;dur='
                    .$formatMs((float) $request->attributes->get('journal_auth_sql_user_ms', 0.0));
            }
            $authQueryMetrics .= ', ';
        }
        $response->headers->set(
            'Server-Timing',
            ($earlier !== '' ? $earlier.', ' : '')
                .$preAuth.$authSegments.$authQueryMetrics.'post_auth_total;dur='.$duration
        );

        return $response;
    }
}
