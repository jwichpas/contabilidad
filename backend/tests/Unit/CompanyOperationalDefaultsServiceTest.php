<?php

declare(strict_types=1);

use App\Services\Company\CompanyOperationalDefaultsService;

it('defines unique company-specific organization and cash flow areas without fake entities', function (): void {
    $areas = CompanyOperationalDefaultsService::organizationAreas();
    $cash = CompanyOperationalDefaultsService::cashFlowAreas();

    expect($areas)->toHaveCount(6)
        ->and($cash)->toHaveCount(9)
        ->and(array_column($areas, 'code'))->toEqual(array_values(array_unique(array_column($areas, 'code'))))
        ->and(array_column($cash, 'code'))->toEqual(array_values(array_unique(array_column($cash, 'code'))));

    foreach ($cash as $row) {
        expect($row['activity_category'])->toBeIn(['OPERATING', 'INVESTING', 'FINANCING', 'CASH_EQUIVALENT'])
            ->and($row['flow_direction'])->toBeIn(['INFLOW', 'OUTFLOW', 'BOTH']);
    }

    foreach ($areas as $row) {
        expect(array_keys($row))->toEqual(['code', 'name', 'description']);
    }

    foreach ($cash as $row) {
        expect(array_keys($row))->toEqual(['code', 'name', 'activity_category', 'flow_direction']);
    }
});
