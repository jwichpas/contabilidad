<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Api\V1\Company\GetCompanyExchangeRatesController;
use App\Http\Controllers\Api\V1\Company\ListCashFlowAreasController;
use App\Http\Controllers\Api\V1\Company\ListCompanyAreasController;
use App\Http\Controllers\Api\V1\Company\ListCostCentersController;
use App\Support\Accounting\JournalSubdiaryCatalog;
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
        $handlers = [
            'accounts' => $this->accounts,
            'cost_centers' => $this->centers,
            'company_areas' => $this->areas,
            'cash_flow_areas' => $this->flows,
            'next_number' => $this->nextNumber,
            'exchange_rates' => $this->rates,
        ];
        $data = [];
        $timings = [];
        foreach ($handlers as $name => $handler) {
            $started = hrtime(true);
            $response = $handler($request);
            $timings[] = $name.';dur='.number_format((hrtime(true) - $started) / 1000000, 1, '.', '');
            if ($response->getStatusCode() !== 200) {
                return $response->header('Server-Timing', implode(', ', $timings));
            }
            $data[$name] = $response->getData(true)['data'] ?? null;
        }

        $data['subdiaries'] = JournalSubdiaryCatalog::options();
        return response()->json(['data' => $data])
            ->header('Server-Timing', implode(', ', $timings));
    }
}
