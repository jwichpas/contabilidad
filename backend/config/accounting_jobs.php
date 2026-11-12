<?php

declare(strict_types=1);

return [
    // Feature flags for the centralized Laravel task registry.
    // Scheduling is opt-in. Use a single scheduler worker until Redis/shared locks exist.
    'sunat_fx' => [
        'enabled' => filter_var(env('ACCOUNTING_SUNAT_FX_SCHEDULE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        // Explicit tenant allowlist; the restricted RLS role cannot list all companies.
        'company_ids' => (string) env('ACCOUNTING_SUNAT_FX_COMPANY_IDS', ''),
    ],
];
