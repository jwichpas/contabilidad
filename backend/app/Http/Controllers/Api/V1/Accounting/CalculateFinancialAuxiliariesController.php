<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Services\Accounting\AssetAndLoanCalculations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CalculateFinancialAuxiliariesController
{
    private const TABLE = 'company_financial_auxiliary_revisions';
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

        return $this->respond($request, 'loan', $input, $calculations->loan($input));
    }

    public function asset(Request $request, AssetAndLoanCalculations $calculations): JsonResponse
    {
        $input = $request->validate([
            'tax_year' => ['sometimes', 'integer', 'between:2025,2026'],
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

        return $this->respond($request, 'asset', $input, $calculations->asset($input));
    }
    public function records(Request $request, string $kind): JsonResponse
    {
        $filter = $request->validate([
            'reference' => ['sometimes', 'regex:/^[A-Z0-9_-]{1,40}$/D'],
            'revision' => ['sometimes', 'integer', 'min:1'],
        ]);
        $query = $this->query($kind);
        if (isset($filter['reference'])) {
            $query->where('reference', $filter['reference']);
            if (isset($filter['revision'])) {
                $query->where('revision', $filter['revision']);
            }
            $row = $query->orderByDesc('revision')->first();
            abort_if(!$row, 404, 'Ficha no encontrada.');
            $row->inputs = json_decode($row->inputs, true, 512, JSON_THROW_ON_ERROR);
            $row->result = json_decode($row->result, true, 512, JSON_THROW_ON_ERROR);

            return response()->json(['data' => $row]);
        }

        $latest = $query->selectRaw('DISTINCT ON (reference) reference, label, revision, created_at')
            ->orderBy('reference')->orderByDesc('revision');

        return response()->json(['data' => DB::query()->fromSub($latest, 'latest')
            ->orderByDesc('created_at')->orderBy('reference')->simplePaginate(40)]);
    }

    private function query(string $kind): Builder
    {
        abort_unless(Schema::hasTable(self::TABLE), 503, 'Aplique la migración de auxiliares.');
        $company = app(TenantContext::class)->requireCompanyId(self::class);

        return DB::table(self::TABLE)->where('company_id', $company)->where('kind', $kind);
    }

    private function respond(Request $request, string $kind, array $input, array $result): JsonResponse
    {
        if (!$request->routeIs('api.v1.accounting.financial-auxiliaries.*.save')) {
            return response()->json(['data' => $result]);
        }
        $metadata = $request->validate([
            'reference' => ['required', 'regex:/^[A-Z0-9_-]{1,40}$/D'],
            'label' => ['required', 'string', 'max:160'],
            'source_reference' => ['required', 'string', 'max:255'],
            'expected_revision' => ['required', 'integer', 'min:0'],
        ]);
        $query = $this->query($kind)->where('reference', $metadata['reference']);
        $company = app(TenantContext::class)->requireCompanyId(self::class);
        $revision = DB::transaction(function () use ($request, $kind, $input, $result, $metadata, $query, $company) {
            DB::table('companies')->where('id', $company)->lockForUpdate()->first();
            $current = (int) ($query->orderByDesc('revision')->value('revision') ?? 0);
            abort_if($current !== (int) $metadata['expected_revision'], 409, 'La ficha cambió. Abra la revisión vigente.');
            DB::table(self::TABLE)->insert([
                'company_id' => $company, 'kind' => $kind, 'reference' => $metadata['reference'],
                'revision' => $current + 1, 'label' => $metadata['label'],
                'source_reference' => $metadata['source_reference'], 'created_by' => $request->user()->id,
                'inputs' => json_encode($input, JSON_THROW_ON_ERROR), 'result' => json_encode($result, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return $current + 1;
        });

        return response()->json(['data' => $result, 'revision' => $revision], 201);
    }
}
