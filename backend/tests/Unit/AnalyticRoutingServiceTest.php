<?php

declare(strict_types=1);

use App\Services\Accounting\AnalyticRoutingService as Routing;

it('mirrors an element-six debit to an exactly balanced dual-currency functional pair', function (): void {
    $pair=Routing::mirrorAmounts([
        'debit_local'=>'123.4567','credit_local'=>'0.0000',
        'debit_foreign'=>'32.9218','credit_foreign'=>'0.0000',
    ]);
    expect($pair['debit'])->toBe([
        'debit_local'=>'123.4567','credit_local'=>'0.0000',
        'debit_foreign'=>'32.9218','credit_foreign'=>'0.0000',
    ])->and($pair['credit'])->toBe([
        'debit_local'=>'0.0000','credit_local'=>'123.4567',
        'debit_foreign'=>'0.0000','credit_foreign'=>'32.9218',
    ]);
});

it('mirrors a standalone credit adjustment without rounding or changing debit/credit sums', function (): void {
    $pair=Routing::mirrorAmounts([
        'debit_local'=>'0.0000','credit_local'=>'9999.9999',
        'debit_foreign'=>'0.0000','credit_foreign'=>'2721.0884',
    ]);
    expect($pair['debit']['debit_local'])->toBe('9999.9999')
        ->and($pair['credit']['credit_local'])->toBe('9999.9999')
        ->and($pair['debit']['debit_foreign'])->toBe('2721.0884')
        ->and($pair['credit']['credit_foreign'])->toBe('2721.0884');
});

it('fails closed when local and foreign amounts disagree on their posting side', function (): void {
    expect(fn () => Routing::mirrorAmounts([
        'debit_local'=>'10.0000','credit_local'=>'0.0000',
        'debit_foreign'=>'0.0000','credit_foreign'=>'10.0000',
    ]))->toThrow(\DomainException::class);

    expect(fn () => Routing::mirrorAmounts([
        'debit_local'=>'0.0000','credit_local'=>'0.0000',
        'debit_foreign'=>'0.0000','credit_foreign'=>'0.0000',
    ]))->toThrow(\DomainException::class);
});
