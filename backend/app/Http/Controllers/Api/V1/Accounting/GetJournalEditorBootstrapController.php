<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Api\V1\Company\GetCompanyExchangeRatesController;
use App\Models\AccountingAccount;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only journal setup in one authorized tenant/RLS context.
 *
 * Five accounting dimensions arrive in ONE PostgreSQL roundtrip. Never cache
 * company account requirements: a configuration change must affect the next
 * journal draft immediately. Fiscal number and exchange rates retain their
 * existing controller contracts.
 */
final readonly class GetJournalEditorBootstrapController
{
    public function __construct(
        private TenantContext $tenant,
        private GetNextJournalEntryNumberController $nextNumber,
        private GetCompanyExchangeRatesController $rates,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $controllerStarted = hrtime(true);
        $timings = [];
        $companyId = $this->tenant->requireCompanyId(AccountingAccount::class);

        // Explicit company predicates are an additional defence on top of
        // PostgreSQL FORCE RLS and the request's SET LOCAL tenant context.
        // Each JSONB aggregation selects only the fields used by the editor.
        $started = hrtime(true);
        $row = DB::selectOne(<<<'SQL'
            SELECT
              COALESCE((
                SELECT jsonb_agg(jsonb_build_object(
                  'id', a.id, 'code', a.code, 'name', a.name,
                  'requires_cost_center', a.requires_cost_center,
                  'has_automatic_destination', a.has_automatic_destination,
                  'requires_company_area', a.requires_company_area,
                  'requires_auxiliary', a.requires_auxiliary,
                  'entity_requirement_type', a.entity_requirement_type,
                  'requires_cash_flow_area', a.requires_cash_flow_area,
                  'cash_flow_category', a.cash_flow_category
                ) ORDER BY a.code)
                FROM public.accounting_accounts a
                WHERE a.company_id = ?::bigint
                  AND a.is_operational IS TRUE AND a.is_active IS TRUE
              ), '[]'::jsonb) AS accounts,
              COALESCE((
                SELECT jsonb_agg(jsonb_build_object(
                  'id', a.id, 'company_id', a.company_id, 'code', a.code,
                  'name', a.name, 'is_active', a.is_active
                ) ORDER BY a.code)
                FROM public.company_areas a
                WHERE a.company_id = ?::bigint AND a.is_active IS TRUE
              ), '[]'::jsonb) AS company_areas,
              COALESCE((
                SELECT jsonb_agg(jsonb_build_object(
                  'id', a.id, 'company_id', a.company_id, 'code', a.code,
                  'name', a.name, 'activity_category', a.activity_category,
                  'flow_direction', a.flow_direction, 'is_active', a.is_active
                ) ORDER BY a.code)
                FROM public.cash_flow_areas a
                WHERE a.company_id = ?::bigint AND a.is_active IS TRUE
              ), '[]'::jsonb) AS cash_flow_areas,
              COALESCE((
                SELECT jsonb_agg(jsonb_build_object(
                  'id', c.id, 'company_id', c.company_id, 'code', c.code,
                  'name', c.name, 'is_active', c.is_active
                ) ORDER BY c.code)
                FROM public.cost_centers c
                WHERE c.company_id = ?::bigint AND c.is_active IS TRUE
              ), '[]'::jsonb) AS cost_centers,
              COALESCE((
                SELECT jsonb_agg(jsonb_build_object(
                  'id', s.id, 'code', s.code, 'name', s.name,
                  'is_active', s.is_active
                ) ORDER BY s.code)
                FROM public.company_subdiaries s
                WHERE s.company_id = ?::bigint AND s.is_active IS TRUE
              ), '[]'::jsonb) AS subdiaries
            SQL, array_fill(0, 5, $companyId));

        $data = [];
        foreach (['accounts', 'company_areas', 'cash_flow_areas', 'cost_centers', 'subdiaries'] as $key) {
            if ($row === null || ! is_string($row->{$key} ?? null)) {
                return response()->json(['message' => 'No se pudo cargar el catálogo contable.'], 503);
            }
            $decoded = json_decode($row->{$key}, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                return response()->json(['message' => 'Respuesta de catálogo contable inválida.'], 503);
            }
            $data[$key] = $decoded;
        }
        $timings[] = 'catalogs_sql;dur='.number_format((hrtime(true) - $started) / 1000000, 1, '.', '');

        foreach ([
            'next_number' => $this->nextNumber,
            'exchange_rates' => $this->rates,
        ] as $name => $handler) {
            $started = hrtime(true);
            $response = $handler($request);
            $timings[] = $name.';dur='.number_format((hrtime(true) - $started) / 1000000, 1, '.', '');
            if ($response->getStatusCode() !== 200) {
                return $response->header('Server-Timing', implode(', ', $timings));
            }
            $data[$name] = $response->getData(true)['data'] ?? null;
        }

        // Exchange rates retain the authoritative daily values, but not the
        // unused 40-row history present on the full FX configuration screen.
        unset($data['exchange_rates']['recent_history']);

        $timings[] = 'controller_total;dur='.number_format((hrtime(true) - $controllerStarted) / 1000000, 1, '.', '');

        return response()->json(['data' => $data])
            ->header('Server-Timing', implode(', ', $timings));
    }
}
