<?php

declare(strict_types=1);

use App\Services\Accounting\InitializeCompanyAnalyticAccountsService as Analytics;

it('defines a complete tenant-owned 94 and 95 chart with six-digit posting leaves', function (): void {
    $accounts = Analytics::accountDefinitions();
    $byCode = [];
    foreach ($accounts as $account) {
        expect(isset($byCode[$account['code']]))->toBeFalse();
        $byCode[$account['code']] = $account;
    }

    expect(count($byCode))->toBe(11)
        ->and($byCode['9']['parent'])->toBeNull()
        ->and($byCode['941100']['rubric'])->toBe('ERF_GASTOS_ADMIN_94')
        ->and($byCode['951100']['rubric'])->toBe('ERF_GASTOS_VENTAS_95');

    foreach ($accounts as $account) {
        if ($account['parent'] !== null) {
            expect(isset($byCode[$account['parent']]))->toBeTrue()
                ->and($byCode[$account['parent']]['level'])->toBe($account['level'] - 1)
                ->and(str_starts_with($account['code'], $account['parent']))->toBeTrue();
        }
        if ($account['operational']) {
            expect(strlen($account['code']))->toBe(6);
        }
    }
});

it('routes administration and sales to distinct six-digit debit accounts and same 79 bridge', function (): void {
    $accounts = collect(Analytics::accountDefinitions())->keyBy('code');
    $centers = Analytics::centerDefinitions();

    expect($centers)->toHaveCount(2)
        ->and(array_column($centers, 'code'))->toEqualCanonicalizing(['CC-ADM', 'CC-VTA']);

    foreach ($centers as $center) {
        expect($center['credit_code'])->toBe('791000')
            ->and($center['debit_code'])->not->toBe('791000')
            ->and(strlen($center['debit_code']))->toBe(6)
            ->and($accounts->get($center['debit_code'])['operational'])->toBeTrue();
    }
    expect($centers[0]['debit_code'])->not->toBe($centers[1]['debit_code']);
});
