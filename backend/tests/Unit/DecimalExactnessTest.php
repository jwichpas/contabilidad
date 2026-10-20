<?php

declare(strict_types=1);

use App\Support\Math\Decimal;

it('retains exact monetary decimals without binary floating point', function (): void {
    $a = Decimal::ofMonetary('0.1000');
    $b = Decimal::ofMonetary('0.2000');

    expect($a->add($b)->toString())->toBe('0.3000')
        ->and($b->sub($a)->toString())->toBe('0.1000')
        ->and(Decimal::ofMonetary('1000000000.9999')->toString())->toBe('1000000000.9999');
});

it('normalizes money and exchange rates to the documented scales', function (): void {
    expect(Decimal::ofMonetary('32')->toString())->toBe('32.0000')
        ->and(Decimal::ofExchangeRate('3.712345')->toString())->toBe('3.712345')
        ->and(Decimal::zero()->toString())->toBe('0.0000')
        ->and(Decimal::ofMonetary('-0.00001')->toString())->toBe('0.0000');
});

it('rounds multiplication half up at a monetary scale', function (): void {
    expect(Decimal::of('1.23445', 5)->multiply('1', 4)->toString())->toBe('1.2345')
        ->and(Decimal::of('-1.23445', 5)->multiply('1', 4)->toString())->toBe('-1.2345')
        ->and(Decimal::ofMonetary('3.0000')->multiply('3.700000', 4)->toString())->toBe('11.1000');
});

it('divides exactly and guards against division by zero', function (): void {
    expect(Decimal::ofMonetary('1')->divide('8', 4)->toString())->toBe('0.1250')
        ->and(fn () => Decimal::ofMonetary('1')->divide('0'))->toThrow(InvalidArgumentException::class);
});

it('does not accept scientific notation or floats masquerading as exact strings', function (): void {
    expect(fn () => Decimal::of('1e-5'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Decimal::of('NaN'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Decimal::of('12,34'))->toThrow(InvalidArgumentException::class);
});

it('never silently truncates nonzero decimal digits beyond the accounting scale', function (): void {
    expect(fn () => Decimal::ofMonetary('10.99995'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Decimal::ofExchangeRate('3.7123459'))->toThrow(InvalidArgumentException::class)
        ->and(Decimal::ofMonetary('10.999900')->toString())->toBe('10.9999')
        ->and(Decimal::ofExchangeRate('3.7123450')->toString())->toBe('3.712345');
});
