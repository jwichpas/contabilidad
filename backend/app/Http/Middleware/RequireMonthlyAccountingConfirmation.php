<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Accounting\MonthlyAccountingWork;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireMonthlyAccountingConfirmation
{
    public function __construct(private MonthlyAccountingWork $work) {}

    public function handle(Request $request, Closure $next, string $field): Response
    {
        $date = $request->input($field);
        // The action owns date validation. Do not replace its validation response for malformed input.
        if (is_string($date) && preg_match('/^(20(?:2[5-9]|[3-9]\d))-(0[1-9]|1[0-2])-(\d{2})$/D', $date, $match)
            && checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            $this->work->requireConfirmation($this->work->period(substr($date, 0, 7)));
        }

        return $next($request);
    }
}
