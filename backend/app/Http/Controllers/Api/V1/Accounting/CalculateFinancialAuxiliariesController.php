<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Services\Accounting\AssetAndLoanCalculations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CalculateFinancialAuxiliariesController
{
    private const AMOUNT = ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/D'];

    public function loan(Request $request, AssetAndLoanCalculations $calculations): JsonResponse
    {
        $input = $request->validate([
            'principal' => self::AMOUNT,
            'transaction_costs' => self::AMOUNT,
            'payments' => ['required', 'list', 'array', 'min:1', 'max:240'],
            'payments.*' => self::AMOUNT,
            'apply_ebitda_limit' => ['required', 'boolean'],
            'previous_tax_ebitda' => self::AMOUNT,
            'tax_interest_expense' => self::AMOUNT,
            'tax_interest_income' => self::AMOUNT,
        ]);

        return response()->json(['data' => $calculations->loan($input)]);
    }

    public function asset(Request $request, AssetAndLoanCalculations $calculations): JsonResponse
    {
        $input = $request->validate([
            'book_cost' => self::AMOUNT,
            'residual' => self::AMOUNT,
            'useful_months' => ['required', 'integer', 'between:1,1200'],
            'available_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2100-12-31'],
            'stop_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:available_from'],
            'tax_cost' => self::AMOUNT,
            'tax_category' => ['required', 'in:BUILDING,DATA_EQUIPMENT,VEHICLE,OTHER'],
            'tax_use_from' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:2100-12-31'],
            'last_eligible_month' => ['required', 'integer', 'between:0,12'],
            'prior_tax_depreciation' => self::AMOUNT,
            'recorded_depreciation' => self::AMOUNT,
        ]);

        return response()->json(['data' => $calculations->asset($input)]);
    }
}
