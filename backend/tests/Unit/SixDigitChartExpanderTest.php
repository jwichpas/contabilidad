<?php

declare(strict_types=1);

use App\Models\AccountingAccountTemplate;
use App\Support\Accounting\SixDigitChartExpander;
use Illuminate\Support\Collection;

/**
 * Pest Unit tests do not bootstrap the Laravel application. Using a filled
 * guarded Eloquent model would ask its missing DB connection for the table
 * schema. Raw in-memory attributes exercise casts without SQL or a resolver.
 *
 * @param array<string, mixed> $attributes
 */
function makeSixDigitPcgeUnitTemplate(array $attributes): AccountingAccountTemplate
{
    $model = new AccountingAccountTemplate();
    $model->setRawAttributes($attributes, true);

    return $model;
}

it('creates six-digit posting children without mutating the base PCGE leaf', function (): void {
    $parent = makeSixDigitPcgeUnitTemplate([
        'code' => '676',
        'name' => 'Diferencia de cambio',
        'level' => 3,
        'is_operational' => true,
    ]);
    $alreadySix = makeSixDigitPcgeUnitTemplate([
        'code' => '101111',
        'name' => 'Cuenta de movimiento existente',
        'level' => 6,
        'is_operational' => true,
    ]);
    $structural = makeSixDigitPcgeUnitTemplate([
        'code' => '67',
        'name' => 'Gastos financieros',
        'level' => 2,
        'is_operational' => false,
    ]);

    $source = new Collection([$parent, $alreadySix, $structural]);
    $expanded = SixDigitChartExpander::expand($source);

    expect($source)->toHaveCount(3)
        ->and($expanded)->toHaveCount(4)
        ->and($parent->code)->toBe('676')
        ->and($parent->level)->toBe(3)
        ->and($parent->is_operational)->toBeTrue();

    $parentInExpandedChart = $expanded->first(fn ($row) => $row->code === '676');
    expect($parentInExpandedChart)->not->toBe($parent)
        ->and($parentInExpandedChart->is_operational)->toBeFalse();

    $alias = $expanded->first(fn ($row) => $row->code === '676000');
    expect($alias)->not->toBeNull()
        ->and($alias->parent_code)->toBe('676')
        ->and($alias->level)->toBe(4)
        ->and($alias->is_operational)->toBeTrue();

    $movements = $expanded->filter(fn ($row) => $row->is_operational);
    expect($movements->map(fn ($row) => strlen((string) $row->code))->unique()->values()->all())
        ->toBe([6]);
});

it('rejects aliases that collide with an existing canonical code', function (): void {
    $leaf = makeSixDigitPcgeUnitTemplate([
        'code' => '676',
        'name' => 'Diferencia',
        'level' => 3,
        'is_operational' => true,
    ]);
    $collision = makeSixDigitPcgeUnitTemplate([
        'code' => '676000',
        'name' => 'Ya usado',
        'level' => 6,
        'is_operational' => false,
    ]);

    expect(fn () => SixDigitChartExpander::expand(new Collection([$leaf, $collision])))
        ->toThrow(DomainException::class);
});
