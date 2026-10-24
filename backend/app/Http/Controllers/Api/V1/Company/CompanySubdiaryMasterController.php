<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Models\CompanySubdiary;
use App\Services\Security\AuditLoggerService;
use App\Support\Tenant\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Tenant-isolated administrative master. Codes are immutable after creation;
 * deactivation hides from new entries but keeps historical references.
 */
final readonly class CompanySubdiaryMasterController
{
    public function __construct(
        private TenantContext $tenant,
        private AuditLoggerService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireSchema();
        $this->tenant->requireCompanyId(CompanySubdiary::class);
        $filter = $request->validate(['active_only' => ['sometimes', 'boolean']]);
        $items = CompanySubdiary::query()
            ->when((bool)($filter['active_only'] ?? false),
                fn ($q) => $q->where('is_active', true))
            ->orderBy('code')
            ->get(['id', 'company_id', 'code', 'name', 'is_active']);

        return response()->json(['data' => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireSchema();
        $companyId = $this->tenant->requireCompanyId(CompanySubdiary::class);
        $payload = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_]{2,24}$/'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'company_id' => ['prohibited'],
            'id' => ['prohibited'],
            'is_active' => ['prohibited'],
        ]);
        $code = (string) $payload['code'];
        $name = trim((string) $payload['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Indique un nombre válido.']]);
        }
        if (CompanySubdiary::query()->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => ['Ya existe ese código en la empresa.']]);
        }
        try {
            // SAVEPOINT protects the outer tenant transaction if a concurrent
            // request inserts the same unique (company_id, code) first.
            $record = DB::transaction(fn () =>
                CompanySubdiary::query()->create(['code' => $code, 'name' => $name])
            );
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23505') {
                throw ValidationException::withMessages(['code' => ['El código ya está registrado.']]);
            }
            throw $exception;
        }
        $this->audit->log('COMPANY_SUBDIARY_CREATED', $companyId, (int)$request->user()->id,
            CompanySubdiary::class, (string)$record->id, null, ['code' => $record->code, 'name' => $record->name], $request);
        return response()->json(['data' => $record->only(['id','company_id','code','name','is_active'])], 201);
    }

    public function update(Request $request, int $subdiaryId): JsonResponse
    {
        $this->requireSchema();
        $companyId = $this->tenant->requireCompanyId(CompanySubdiary::class);
        $payload = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'code' => ['prohibited'],
            'company_id' => ['prohibited'],
            'id' => ['prohibited'],
            'is_active' => ['prohibited'],
        ]);
        $name = trim((string)$payload['name']);
        if ($name === '') throw ValidationException::withMessages(['name' => ['Indique un nombre válido.']]);

        $record = CompanySubdiary::query()->findOrFail($subdiaryId);
        $previous = $record->only(['name']);
        $record->update(['name' => $name]);
        $this->audit->log('COMPANY_SUBDIARY_UPDATED', $companyId, (int)$request->user()->id,
            CompanySubdiary::class, (string)$record->id, $previous, ['name' => $name], $request);
        return response()->json(['data' => $record->only(['id','company_id','code','name','is_active'])]);
    }

    public function setStatus(Request $request, int $subdiaryId): JsonResponse
    {
        $this->requireSchema();
        $companyId = $this->tenant->requireCompanyId(CompanySubdiary::class);
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
            'code' => ['prohibited'],
            'company_id' => ['prohibited'],
            'id' => ['prohibited'],
        ]);
        $record = CompanySubdiary::query()->findOrFail($subdiaryId);
        $previous = (bool)$record->is_active;
        $next = (bool)$data['is_active'];
        $record->update(['is_active' => $next]);
        $this->audit->log('COMPANY_SUBDIARY_STATUS_CHANGED', $companyId, (int)$request->user()->id,
            CompanySubdiary::class, (string)$record->id,
            ['is_active' => $previous], ['is_active' => $next], $request);
        return response()->json(['data' => $record->only(['id','company_id','code','name','is_active'])]);
    }

    private function requireSchema(): void
    {
        if (! Schema::hasTable('company_subdiaries')) {
            abort(503, 'Falta aplicar la migración aditiva del maestro de subdiarios.');
        }
    }
}
