<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function seedMonthlyConfirmation(int $companyId, int $userId, string $month): void
    {
        $tenant = app(\App\Support\Tenant\TenantContext::class);
        $tenant->run($companyId, static function () use ($companyId, $userId, $month): void {
            \Illuminate\Support\Facades\DB::table('company_monthly_work_revisions')->insertOrIgnore([
                'company_id' => $companyId, 'created_by' => $userId, 'period' => $month.'-01',
                'code' => 'config', 'revision' => 1,
                'payload' => json_encode(['values' => [], 'tasks' => []], JSON_THROW_ON_ERROR),
            ]);
        });
    }
}
