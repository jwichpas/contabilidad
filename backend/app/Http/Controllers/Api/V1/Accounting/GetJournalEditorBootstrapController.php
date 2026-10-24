<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Api\V1\Company\GetCompanyExchangeRatesController;
use App\Http\Controllers\Api\V1\Company\ListCashFlowAreasController;
use App\Http\Controllers\Api\V1\Company\ListCompanyAreasController;
use App\Models\CostCenter;
use App\Models\CompanySubdiary;
use App\Models\AccountingAccount;
use Illuminate\Support\Facades\Schema;
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
        private ListCompanyAreasController $areas,
        private ListCashFlowAreasController $flows,
        private GetNextJournalEntryNumberController $nextNumber,
        private GetCompanyExchangeRatesController $rates,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $handlers = [
            'company_areas' => $this->areas,
            'cash_flow_areas' => $this->flows,
            'next_number' => $this->nextNumber,
            'exchange_rates' => $this->rates,
        ];
        if (! Schema::hasColumn('accounting_accounts', 'requires_company_area')) {
            return response()->json(['message' => 'Falta aplicar migración de requisitos por cuenta.'], 503);
        }
        $data = [];
        $timings = [];

        // Slim editor DTO: ids, labels and validation flags only. Avoid UUIDs,
        // account taxonomy and currencies that are irrelevant to the journal form.
        $started = hrtime(true);
        $data['accounts'] = AccountingAccount::query()
            ->whereRaw('accounting_accounts.is_operational IS TRUE')
            ->whereRaw('accounting_accounts.is_active IS TRUE')
            ->orderBy('code')
            ->get([
                'id', 'code', 'name', 'requires_cost_center', 'has_automatic_destination',
                'requires_company_area', 'requires_auxiliary', 'entity_requirement_type',
                'requires_cash_flow_area', 'cash_flow_category',
            ])
            ->toArray();
        $timings[] = 'accounts;dur='.number_format((hrtime(true) - $started) / 1000000, 1, '.', '');

        foreach ($handlers as $name => $handler) {
            $started = hrtime(true);
            $response = $handler($request);
            $timings[] = $name.';dur='.number_format((hrtime(true) - $started) / 1000000, 1, '.', '');
            if ($response->getStatusCode() !== 200) {
                return $response->header('Server-Timing', implode(', ', $timings));
            }
            $data[$name] = $response->getData(true)['data'] ?? null;
        }

        // The editor needs only code/label/status. Do not eager-load analytic
        // destination account relations required by the separate master screen.
        $started = hrtime(true);
        $data['cost_centers'] = CostCenter::query()
            ->whereRaw('cost_centers.is_active IS TRUE')
            ->orderBy('code')
            ->get(['id', 'company_id', 'code', 'name', 'is_active'])
            ->toArray();
        $timings[] = 'cost_centers;dur='.number_format((hrtime(true) - $started) / 1000000, 1, '.', '');
        // Full 40-row FX history is not needed by the editor.
        if (isset($data['exchange_rates']['recent_history'])) {
            unset($data['exchange_rates']['recent_history']);
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable('company_subdiaries')) {
            return response()->json(['message' => 'Falta la migración del maestro de subdiarios.'], 503);
        }
        $data['subdiaries'] = CompanySubdiary::query()->where('is_active', true)
            ->orderBy('code')->get(['id', 'code', 'name', 'is_active'])->toArray();
        return response()->json(['data' => $data])
            ->header('Server-Timing', implode(', ', $timings));
    }
}
