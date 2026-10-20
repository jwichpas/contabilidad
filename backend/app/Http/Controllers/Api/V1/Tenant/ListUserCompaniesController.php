<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Models\User;
use App\Services\Tenant\ListUserCompaniesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Retorna las empresas activas del usuario autenticado mediante la misma
 * lectura RLS que usa el login (sin bypass de tenant ni privilegios nuevos).
 */
final readonly class ListUserCompaniesController
{
    public function __construct(
        private ListUserCompaniesService $companyListing,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->companyListing->forUser($user),
        ]);
    }
}
