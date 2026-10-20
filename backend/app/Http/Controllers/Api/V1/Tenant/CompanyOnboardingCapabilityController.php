<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Models\User;
use App\Support\Company\CompanyOnboardingGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final readonly class CompanyOnboardingCapabilityController
{
    public function __construct(private CompanyOnboardingGuard $guard) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->guard->status($user)]);
    }
}
