<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Api\V1\Company\GetCompanyExchangeRatesController;
use App\Models\AccountingAccount;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Validation\ValidationException;
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
        private GetCompanyExchangeRatesController $rates,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $controllerStarted = hrtime(true);
        $timings = [];
        $companyId = $this->tenant->requireCompanyId(AccountingAccount::class);
        $validated = $request->validate(['date' => ['sometimes', 'date_format:Y-m-d']]);
        $date = isset($validated['date'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $validated['date'])
            : CarbonImmutable::now();
        if ($date === false) {
            throw ValidationException::withMessages(['date' => ['Fecha contable invalida.']]);
        }
        $month = $date->month;
        $year = $date->year;

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
              ), '[]'::jsonb) AS subdiaries,
              COALESCE((
                SELECT jsonb_build_object(
                  'status', fp.status,
                  'max_suffix', (
                    SELECT COALESCE(MAX(right(je.entry_number, 5)::integer), 0)
                    FROM public.journal_entries je
                    WHERE je.company_id = ?::bigint
                      AND je.fiscal_period_id = fp.id
                      AND je.entry_number LIKE ?
                      AND je.entry_number ~ '^[0-9]{2}-[0-9]{5}$'
                  )
                )
                FROM public.fiscal_periods fp
                WHERE fp.company_id = ?::bigint
                  AND fp.year = ?::integer
                  AND fp.month = ?::integer
                LIMIT 1
              ), '{}'::jsonb) AS number_context
            SQL, [
                ...array_fill(0, 5, $companyId),
                $companyId, sprintf('%02d-', $month).'%', $companyId, $year, $month,
            ]);

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

        // Vista previa no reservada, calculada junto a los catálogos.
        // El registro POST sigue usando bloqueo del periodo (FOR UPDATE).
        $numberContext = json_decode((string) ($row?->number_context ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($numberContext)) {
            return response()->json(['message' => 'Contexto de numeración contable inválido.'], 503);
        }
        $nextSuffix = (int) ($numberContext['max_suffix'] ?? 0) + 1;
        if ($nextSuffix > 99999) {
            throw new DomainException('El periodo alcanzó el límite del correlativo contable.');
        }
        $data['next_number'] = [
            'next_number' => sprintf('%02d-%05d', $month, $nextSuffix),
            'period_year' => $year,
            'period_month' => $month,
            'is_period_open' => ! isset($numberContext['status']) || $numberContext['status'] === 'OPEN',
            'is_reserved' => false,
        ];
        $timings[] = 'next_number;desc="included_in_catalogs_sql"';

        foreach (['exchange_rates' => $this->rates] as $name => $handler) {
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
