<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalogs;

use App\Models\AccountingAccountTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de Plantillas Matrices de Planes Contables (PCGE 2019 y PCGE 2026).
 */
final readonly class ListChartOfAccountsTemplatesController
{
    public function __invoke(Request $request): JsonResponse
    {
        $version = $request->query('version');

        if (is_string($version) && in_array($version, ['PCGE_2019', 'PCGE_2026'], true)) {
            $accounts = AccountingAccountTemplate::query()
                ->where('plan_version', $version)
                ->whereRaw('accounting_account_templates.is_active IS TRUE')
                ->orderBy('level')
                ->orderBy('code')
                ->get();

            return response()->json([
                'data' => [
                    'plan_version' => $version,
                    'total_accounts' => $accounts->count(),
                    'accounts' => $accounts,
                ],
            ]);
        }

        // Resumen de las versiones disponibles
        $summary = DB::table('accounting_account_templates')
            ->select('plan_version', DB::raw('count(*) as total_accounts'), DB::raw('min(level) as min_level'), DB::raw('max(level) as max_level'))
            ->groupBy('plan_version')
            ->orderBy('plan_version')
            ->get()
            ->map(fn ($row) => [
                'plan_version' => $row->plan_version,
                'name' => $row->plan_version === 'PCGE_2019'
                    ? 'Plan Contable General Empresarial 2019 (Vigente)'
                    : 'Plan Contable General Empresarial 2026 (NIIF 18 / NIIF 19)',
                'is_current_default' => $row->plan_version === 'PCGE_2019',
                'total_accounts' => (int) $row->total_accounts,
                'min_level' => (int) $row->min_level,
                'max_level' => (int) $row->max_level,
            ]);

        return response()->json([
            'data' => $summary,
        ]);
    }
}
