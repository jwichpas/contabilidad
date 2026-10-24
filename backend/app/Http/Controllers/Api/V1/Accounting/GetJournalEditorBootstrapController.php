<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Api\V1\Company\GetCompanyExchangeRatesController;
use App\Http\Controllers\Api\V1\Company\ListCashFlowAreasController;
use App\Http\Controllers\Api\V1\Company\ListCompanyAreasController;
use App\Http\Controllers\Api\V1\Company\ListCostCentersController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One HTTP/auth/RLS context to prime the manual journal editor instead of six
 * serialized requests on the Windows built-in PHP development server.
 * Read only: no periods, rates, accounts or journal movements are created.
 */
final readonly class GetJournalEditorBootstrapController
{
    public function __construct(
        private ListOperationalAccountsController $accounts,
        private ListCostCentersController $centers,
        private ListCompanyAreasController $areas,
        private ListCashFlowAreasController $flows,
        private GetNextJournalEntryNumberController $nextNumber,
        private GetCompanyExchangeRatesController $rates,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $responses = [
            'accounts' => ($this->accounts)($request),
            'cost_centers' => ($this->centers)($request),
            'company_areas' => ($this->areas)($request),
            'cash_flow_areas' => ($this->flows)($request),
            'next_number' => ($this->nextNumber)($request),
            'exchange_rates' => ($this->rates)($request),
        ];
        $data = [];
        foreach ($responses as $name => $response) {
            if ($response->getStatusCode() !== 200) {
                return $response;
            }
            $data[$name] = $response->getData(true)['data'] ?? null;
        }

        return response()->json(['data' => $data]);
    }
}
