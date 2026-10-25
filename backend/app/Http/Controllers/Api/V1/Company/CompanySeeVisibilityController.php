<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Services\Security\AuditLoggerService;
use App\Support\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Company-specific visibility, separate from the versioned, read-only SEE source. */
final readonly class CompanySeeVisibilityController
{
    public function __construct(
        private TenantContext $tenantContext,
        private AuditLoggerService $auditLogger,
    ) {}

    public function index(): JsonResponse
    {
        $companyId = $this->tenantContext->requireCompanyId(self::class);
        $currencyCodes = DB::table('company_currencies as cc')
            ->join('currencies as c', 'c.id', '=', 'cc.currency_id')
            ->where('cc.company_id', $companyId)->whereRaw('cc.is_active IS TRUE')
            ->whereRaw('c.is_active IS TRUE')->pluck('c.code')->all();

        $preferences = [];
        foreach (['03', '04'] as $number) {
            $row = DB::table('company_see_visibility_revisions')
                ->where('company_id', $companyId)->where('catalog_number', $number)
                ->orderByDesc('revision')->first();
            $preferences[$number] = $row === null ? null : [
                'revision' => (int) $row->revision,
                'source_version' => $row->source_version,
                'visible_codes' => json_decode($row->visible_codes, true, 512, JSON_THROW_ON_ERROR),
            ];
        }

        return response()->json(['data' => [
            'currencies' => $currencyCodes,
            'catalogs' => $preferences,
        ]]);
    }

    public function update(Request $request, string $number): JsonResponse
    {
        abort_unless(in_array($number, ['03', '04'], true), 404);
        $companyId = $this->tenantContext->requireCompanyId(self::class);
        $input = $request->validate([
            'visible_codes' => ['present', 'array', 'max:2500'],
            'visible_codes.*' => ['required', 'string', 'max:32', 'distinct'],
            'expected_revision' => ['required', 'integer', 'min:0'],
            'source_version' => ['required', 'string', 'max:40'],
        ]);

        $result = DB::transaction(function () use ($companyId, $number, $input, $request): array {
            // Serializes simultaneous updates to either catalog for this company.
            DB::table('companies')->where('id', $companyId)->lockForUpdate()->first();
            $previous = DB::table('company_see_visibility_revisions')
                ->where('company_id', $companyId)->where('catalog_number', $number)
                ->orderByDesc('revision')->first();
            $revision = (int) ($previous->revision ?? 0);
            abort_if($revision !== (int) $input['expected_revision'], 409, 'La selección cambió; recárguela antes de guardar.');

            $latest = DB::table('see_catalog_versions')->where('catalog_number', $number)
                ->orderByDesc('version')->first();
            abort_if($latest === null, 409, 'La fuente SEE no tiene una versión incorporada.');
            abort_if($latest->version !== $input['source_version'], 409, 'La edición cambió; revise los códigos antes de guardar.');

            $rows = DB::table('see_catalog_version_items')
                ->where('catalog_number', $number)->where('version', $latest->version)
                ->whereIn('code', $input['visible_codes'])->get(['code', 'details']);
            $found = [];
            foreach ($rows as $row) {
                $details = json_decode($row->details ?? '{}', true, 512, JSON_THROW_ON_ERROR);
                if ($number === '03' && in_array($details['Estado fuente'] ?? '', ['D', 'X', '¦'], true)) {
                    throw ValidationException::withMessages(['visible_codes' => ['Hay unidades desaconsejadas, eliminadas o de estado incierto en la selección.']]);
                }
                $found[] = $row->code;
            }
            if (count($found) !== count($input['visible_codes'])) {
                throw ValidationException::withMessages(['visible_codes' => ['Uno o más códigos no pertenecen a la edición actual.']]);
            }

            $codes = array_values($input['visible_codes']);
            DB::table('company_see_visibility_revisions')->insert([
                'company_id' => $companyId,
                'catalog_number' => $number,
                'revision' => $revision + 1,
                'source_version' => $latest->version,
                'visible_codes' => json_encode($codes, JSON_THROW_ON_ERROR),
                'created_by' => $request->user()?->id,
                'created_at' => now(),
            ]);
            $this->auditLogger->log(
                eventType: 'COMPANY_SEE_VISIBILITY_UPDATED', companyId: $companyId,
                userId: $request->user()?->id, auditableType: 'company_see_visibility_revisions',
                auditableId: $number, oldValues: $previous ? ['revision' => $revision, 'visible_codes' => json_decode($previous->visible_codes, true)] : null,
                newValues: ['revision' => $revision + 1, 'source_version' => $latest->version, 'visible_codes' => $codes], request: $request,
            );
            return ['revision' => $revision + 1, 'source_version' => $latest->version, 'visible_codes' => $codes];
        });

        return response()->json(['data' => $result]);
    }
}
