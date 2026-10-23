<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Company;

use App\Http\Requests\Api\V1\Company\SaveCostCenterRequest;
use App\Http\Resources\Api\V1\Company\CostCenterResource;
use App\Models\CostCenter;
use App\Services\Company\CostCenterMasterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final readonly class CostCenterMasterController
{
    public function __construct(private CostCenterMasterService $service) {}

    /** Separate paginated endpoint: the existing journal dropdown keeps its contract. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive', 'all'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $query = CostCenter::query()->with(['destinationDebitAccount', 'destinationCreditAccount']);
        $status = $filters['status'] ?? 'active';
        if ($status !== 'all') {
            $query->whereRaw($status === 'active' ? 'cost_centers.is_active IS TRUE' : 'cost_centers.is_active IS FALSE');
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($query) use ($search): void {
                $query->where('code', 'ilike', '%'.$search.'%')->orWhere('name', 'ilike', '%'.$search.'%');
            });
        }
        $page = $query->orderBy('code')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'data' => CostCenterResource::collection($page->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, int $costCenterId): JsonResponse
    {
        $center = CostCenter::query()->with(['destinationDebitAccount', 'destinationCreditAccount'])->findOrFail($costCenterId);

        return response()->json(['data' => (new CostCenterResource($center))->resolve($request)]);
    }

    public function store(SaveCostCenterRequest $request): JsonResponse
    {
        $center = $this->service->save($request->validated(), null, $request);

        return response()->json(['message' => 'Centro de costo creado y auditado.',
            'data' => (new CostCenterResource($center))->resolve($request)], 201);
    }

    public function update(SaveCostCenterRequest $request, int $costCenterId): JsonResponse
    {
        $center = $this->service->save($request->validated(), $costCenterId, $request);

        return response()->json(['message' => 'Centro de costo actualizado.',
            'data' => (new CostCenterResource($center))->resolve($request)]);
    }

    public function deactivate(Request $request, int $costCenterId): JsonResponse
    {
        $request->validate(['company_id' => ['missing'], 'is_active' => ['missing'], 'id' => ['missing']]);
        $center = $this->service->deactivate($costCenterId, $request);

        return response()->json(['message' => 'Centro de costo desactivado.',
            'data' => (new CostCenterResource($center))->resolve($request)]);
    }
}
